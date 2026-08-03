<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Checkout;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
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

  /**
   * Constructs the NovaPay checkout coordinator.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entity_type_manager,
    private readonly NovaPayApiClientInterface $api_client,
    private readonly OrderPayloadBuilderInterface $payload_builder,
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
    try {
      $order_id = $this->getOrderId($payment);
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
      $payment->setRemoteState('pending');
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
    }
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
