<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Checkout;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\commerce_novapay\Api\Dto\Response\PaymentResponse;
use Drupal\commerce_novapay\Api\NovaPayApiClientInterface;
use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Exception\CheckoutPreparationException;
use Drupal\commerce_novapay\Order\OrderPayloadBuilderInterface;
use Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderStorageInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\PaymentStorageInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;
use Drupal\commerce_price\Price;

/**
 * Creates one locked NovaPay checkout session or reuses an active payment.
 */
final class CheckoutCoordinator implements CheckoutCoordinatorInterface {

  private const CHECKOUT_LOCK_TIMEOUT_SECONDS = 60.0;

  private const CHECKOUT_LOCK_WAIT_SECONDS = 60;

  private const SESSION_LIFETIME_SECONDS = 2592000;

  /**
   * Constructs the NovaPay checkout coordinator.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entity_type_manager,
    private readonly NovaPayApiClientInterface $api_client,
    private readonly OrderPayloadBuilderInterface $payload_builder,
    private readonly LockBackendInterface $checkout_lock,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function prepareRedirect(
    PaymentInterface $payment,
    string $return_url,
    string $cancel_url,
  ): string {
    $stage = 'order_id';
    $order_id = NULL;
    $order_storage = NULL;
    $checkout_lock_id = NULL;
    $checkout_lock_acquired = FALSE;
    try {
      $order_id = $this->getOrderId($payment);
      $stage = 'checkout_lock';
      $checkout_lock_id = 'commerce_novapay_checkout:' . $order_id;
      $this->acquireCheckoutLock($checkout_lock_id);
      $checkout_lock_acquired = TRUE;
      $stage = 'order_storage';
      $order_storage = $this->entity_type_manager
        ->getStorage('commerce_order');
      if (!$order_storage instanceof OrderStorageInterface) {
        throw new \RuntimeException('Commerce order storage is unavailable.');
      }

      $stage = 'order_lock';
      $order = $order_storage->loadForUpdate($order_id);
      if (!$order instanceof OrderInterface) {
        throw new \RuntimeException('The Commerce order is unavailable.');
      }
      $stage = 'checkout_lock_renewal';
      if (!$this->checkout_lock->acquire(
        $checkout_lock_id,
        self::CHECKOUT_LOCK_TIMEOUT_SECONDS,
      )) {
        throw new \RuntimeException('The NovaPay checkout lock was lost.');
      }

      $stage = 'gateway_validation';
      $gateway = $this->getCurrentGateway($order, $payment);
      $plugin = $gateway->getPlugin();
      if (
        !$plugin instanceof OffsitePaymentGatewayInterface
        || !$plugin instanceof RuntimeConfigurationProviderInterface
      ) {
        throw new \RuntimeException('The NovaPay gateway is unavailable.');
      }

      $stage = 'runtime_configuration';
      $runtime_configuration = $plugin->getRuntimeConfiguration();
      $stage = 'payment_reuse';
      $existing_url = $this->findReusablePaymentUrl(
        $order,
        $gateway,
        $runtime_configuration->getProfile()->getMode(),
      );
      if ($existing_url !== NULL) {
        return $existing_url;
      }

      $stage = 'session_payload';
      $session_request = $this->payload_builder->buildSessionRequest(
        $order,
        $gateway,
        $plugin->getNotifyUrl()->toString(),
        $return_url,
        $cancel_url,
      );
      $stage = 'create_session';
      $session = $this->api_client->createSession(
        $plugin,
        $session_request,
      );
      $profile = $runtime_configuration->getProfile();
      $stage = 'payment_payload';
      $payment_request = $this->payload_builder->buildPaymentRequest(
        $order,
        $session->getSessionId(),
        $profile->getTransactionMode(),
        $profile->getRecipientIdentifier(),
      );
      $stage = 'add_payment';
      $response = $this->api_client->addPayment($plugin, $payment_request);
      $stage = 'payment_save';
      $balance = $order->getBalance();
      if (!$balance instanceof Price) {
        throw new \RuntimeException('The Commerce order balance is unavailable.');
      }

      $payment->setAmount($balance);
      $payment->setState('pending');
      $payment->setRemoteId($session->getSessionId());
      $payment->setRemoteState('created');
      $payment->setExpiresTime(
        $this->time->getRequestTime() + self::SESSION_LIFETIME_SECONDS,
      );
      $payment->set('novapay_operation_id', $response->getOperationId());
      $payment->set('novapay_payment_url', $response->getPaymentUrl());
      $payment->save();

      return $response->getPaymentUrl();
    }
    catch (\Throwable $exception) {
      throw CheckoutPreparationException::fromThrowable($stage, $exception);
    }
    finally {
      if (
        $order_storage instanceof OrderStorageInterface
        && is_int($order_id)
      ) {
        $order_storage->releaseLock($order_id);
      }
      if ($checkout_lock_acquired && is_string($checkout_lock_id)) {
        $this->checkout_lock->release($checkout_lock_id);
      }
    }
  }

  /**
   * Acquires the checkout lock with enough time for both NovaPay API calls.
   */
  private function acquireCheckoutLock(string $lock_id): void {
    if ($this->checkout_lock->acquire(
      $lock_id,
      self::CHECKOUT_LOCK_TIMEOUT_SECONDS,
    )) {
      return;
    }

    if (
      !$this->checkout_lock->wait(
        $lock_id,
        self::CHECKOUT_LOCK_WAIT_SECONDS,
      )
      && $this->checkout_lock->acquire(
        $lock_id,
        self::CHECKOUT_LOCK_TIMEOUT_SECONDS,
      )
    ) {
      return;
    }

    throw new \RuntimeException('The NovaPay checkout is already being prepared.');
  }

  /**
   * Gets a positive integer order ID from a checkout payment.
   */
  private function getOrderId(PaymentInterface $payment): int {
    $order_id = $payment->get('order_id')->getString();
    if (
      preg_match('/^[1-9][0-9]*$/D', $order_id) !== 1
      || filter_var(
        $order_id,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]],
      ) === FALSE
    ) {
      throw new \RuntimeException('The Commerce order identifier is invalid.');
    }

    return (int) $order_id;
  }

  /**
   * Confirms that the locked order still uses the supplied gateway.
   */
  private function getCurrentGateway(
    OrderInterface $order,
    PaymentInterface $payment,
  ): PaymentGatewayInterface {
    $gateway = $payment->getPaymentGateway();
    $gateway_items = $order->get('payment_gateway');
    $current_gateway = $gateway_items instanceof EntityReferenceFieldItemListInterface
      ? ($gateway_items->referencedEntities()[0] ?? NULL)
      : NULL;
    if (
      !$gateway instanceof PaymentGatewayInterface
      || !$current_gateway instanceof PaymentGatewayInterface
      || $gateway->id() !== $current_gateway->id()
      || $gateway->getPluginId() !== 'novapay'
    ) {
      throw new \RuntimeException('The order does not use the NovaPay gateway.');
    }

    return $gateway;
  }

  /**
   * Finds the newest pending payment that is safe to redirect to again.
   */
  private function findReusablePaymentUrl(
    OrderInterface $order,
    PaymentGatewayInterface $gateway,
    NovaPayMode $mode,
  ): ?string {
    $payment_storage = $this->entity_type_manager
      ->getStorage('commerce_payment');
    if (!$payment_storage instanceof PaymentStorageInterface) {
      throw new \RuntimeException('Commerce payment storage is unavailable.');
    }

    $payments = $payment_storage->loadByProperties([
      'order_id' => $order->id(),
      'payment_gateway' => $gateway->id(),
      'state' => 'pending',
    ]);
    usort(
      $payments,
      static fn (EntityInterface $left, EntityInterface $right): int =>
        ((int) $right->id()) <=> ((int) $left->id()),
    );
    $balance = $order->getBalance();
    if (!$balance instanceof Price) {
      return NULL;
    }
    $request_time = $this->time->getRequestTime();

    foreach ($payments as $candidate) {
      if (!$candidate instanceof PaymentInterface) {
        continue;
      }
      $amount = $candidate->getAmount();
      $session_id = $candidate->getRemoteId();
      $operation_id = $candidate->get('novapay_operation_id')->getString();
      $payment_url = $candidate->get('novapay_payment_url')->getString();
      if (
        !$amount instanceof Price
        || !$amount->equals($balance)
        || $candidate->getExpiresTime() <= $request_time
        || !is_string($session_id)
        || trim($session_id) === ''
      ) {
        continue;
      }

      try {
        return PaymentResponse::fromArray(
          ['id' => $operation_id, 'url' => $payment_url],
          $mode,
        )->getPaymentUrl();
      }
      catch (\InvalidArgumentException) {
        continue;
      }
    }

    return NULL;
  }

}
