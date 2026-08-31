<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\commerce_novapay\Api\Dto\Request\GetStatusRequest;
use Drupal\commerce_novapay\Api\NovaPayApiClientInterface;
use Drupal\commerce_novapay\Postback\PaymentStatusMapperInterface;
use Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentStorageInterface;

/**
 * Reconciles one payment with NovaPay without issuing a financial command.
 */
final class PaymentStatusCheckManager implements PaymentStatusCheckManagerInterface {

  use StringTranslationTrait;

  private const LOCK_TIMEOUT_SECONDS = 30.0;

  public function __construct(
    private readonly EntityTypeManagerInterface $entity_type_manager,
    private readonly NovaPayApiClientInterface $api_client,
    private readonly PaymentStatusMapperInterface $status_mapper,
    private readonly LockBackendInterface $lock,
    TranslationInterface $string_translation,
  ) {
    $this->setStringTranslation($string_translation);
  }

  /**
   * {@inheritdoc}
   */
  public function canCheckStatus(PaymentInterface $payment): bool {
    return in_array(
      $payment->getState()->getId(),
      ['pending', 'authorization', 'failed'],
      TRUE,
    ) && trim((string) $payment->getRemoteId()) !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function checkStatus(
    PaymentInterface $payment,
    RuntimeConfigurationProviderInterface $gateway,
  ): PaymentStatusCheckResult {
    $payment_id = $payment->id();
    if (!is_int($payment_id) && !is_string($payment_id)) {
      throw InvalidRequestException::createForPayment(
        $payment,
        (string) $this->t('The payment must be saved before its NovaPay status can be checked.'),
      );
    }
    $session_id = trim((string) $payment->getRemoteId());
    if ($session_id === '') {
      throw InvalidRequestException::createForPayment(
        $payment,
        (string) $this->t('The NovaPay session ID is unavailable.'),
      );
    }

    $lock_name = SessionLockName::fromSessionId($session_id);
    if (!$this->lock->acquire($lock_name, self::LOCK_TIMEOUT_SECONDS)) {
      throw PaymentGatewayException::createForPayment(
        $payment,
        (string) $this->t('Another NovaPay operation is already being processed.'),
      );
    }

    try {
      $current = $this->loadCurrentPayment($payment_id, $payment);
      if (!$this->canCheckStatus($current)) {
        throw InvalidRequestException::createForPayment(
          $current,
          (string) $this->t('This payment cannot be reconciled with NovaPay at this time.'),
        );
      }
      if (trim((string) $current->getRemoteId()) !== $session_id) {
        throw InvalidRequestException::createForPayment(
          $current,
          (string) $this->t('The NovaPay session ID changed before the status check.'),
        );
      }

      try {
        $response = $this->api_client->getStatus(
          $gateway,
          new GetStatusRequest($session_id),
        );
      }
      catch (\Throwable $exception) {
        throw PaymentGatewayException::createForPayment(
          $current,
          (string) $this->t('The NovaPay payment status could not be checked.'),
          previous: $exception,
        );
      }
      if ($response->getSessionId() !== $session_id) {
        throw PaymentGatewayException::createForPayment(
          $current,
          (string) $this->t('NovaPay returned an unexpected session status.'),
        );
      }

      return $this->status_mapper->apply($current, $response->getStatus())
        ? PaymentStatusCheckResult::Reconciled
        : PaymentStatusCheckResult::Unchanged;
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Reloads the payment and verifies it still belongs to this gateway.
   */
  private function loadCurrentPayment(
    int|string $payment_id,
    PaymentInterface $payment,
  ): PaymentInterface {
    $storage = $this->entity_type_manager->getStorage('commerce_payment');
    if (!$storage instanceof PaymentStorageInterface) {
      throw PaymentGatewayException::createForPayment(
        $payment,
        (string) $this->t('Commerce payment storage is unavailable.'),
      );
    }
    $storage->resetCache([$payment_id]);
    $current = $storage->load($payment_id);
    if (
      !$current instanceof PaymentInterface
      || $current->getPaymentGatewayId() !== $payment->getPaymentGatewayId()
      || $current->bundle() !== 'novapay_payment'
    ) {
      throw InvalidRequestException::createForPayment(
        $payment,
        (string) $this->t('The NovaPay payment could not be reloaded safely.'),
      );
    }
    return $current;
  }

}
