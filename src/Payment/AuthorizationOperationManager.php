<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\commerce_novapay\Api\Dto\Request\CompleteHoldRequest;
use Drupal\commerce_novapay\Api\Dto\Request\VoidRequest;
use Drupal\commerce_novapay\Api\NovaPayApiClientInterface;
use Drupal\commerce_novapay\Exception\ApiFatalException;
use Drupal\commerce_novapay\Exception\ApiProcessingException;
use Drupal\commerce_novapay\Exception\ApiTransportException;
use Drupal\commerce_novapay\Exception\ApiUnexpectedResponseException;
use Drupal\commerce_novapay\Exception\NovaPayApiException;
use Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentStorageInterface;
use Drupal\commerce_price\Price;

/**
 * Serializes and submits authorization operations without changing state.
 */
final class AuthorizationOperationManager implements AuthorizationOperationManagerInterface {

  private const LOCK_TIMEOUT_SECONDS = 30.0;

  public function __construct(
    private readonly EntityTypeManagerInterface $entity_type_manager,
    private readonly NovaPayApiClientInterface $api_client,
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function canCapture(PaymentInterface $payment): bool {
    return $this->isAvailableAuthorization($payment);
  }

  /**
   * {@inheritdoc}
   */
  public function canVoid(PaymentInterface $payment): bool {
    return $this->isAvailableAuthorization($payment);
  }

  /**
   * {@inheritdoc}
   */
  public function capture(
    PaymentInterface $payment,
    RuntimeConfigurationProviderInterface $gateway,
    ?Price $amount = NULL,
  ): void {
    $this->executeLocked(
      $payment,
      PendingOperation::Capture,
      function (PaymentInterface $current) use ($gateway, $amount): void {
        $authorized_amount = $current->getAmount();
        if (!$authorized_amount instanceof Price) {
          throw InvalidRequestException::createForPayment(
            $current,
            'The authorized payment amount is unavailable.',
          );
        }
        $capture_amount = $amount ?? $authorized_amount;
        try {
          $valid_amount = $capture_amount->isPositive()
            && $capture_amount->lessThanOrEqual($authorized_amount);
        }
        catch (\InvalidArgumentException) {
          $valid_amount = FALSE;
        }
        if (!$valid_amount) {
          throw InvalidRequestException::createForPayment(
            $current,
            'The capture amount must be greater than zero and must not exceed the authorized amount.',
          );
        }

        $operations = [];
        $request_amount = NULL;
        if (!$capture_amount->equals($authorized_amount)) {
          $operation_id = trim(
            $current->get('novapay_operation_id')->getString(),
          );
          $recipient_identifier = $gateway
            ->getRuntimeConfiguration()
            ->getProfile()
            ->getRecipientIdentifier();
          if ($operation_id === '' || $recipient_identifier === '') {
            throw InvalidRequestException::createForPayment(
              $current,
              'Partial capture requires a NovaPay operation ID and recipient identifier.',
            );
          }

          $operation_amount = $capture_amount->getNumber();
          $operations[] = [
            'id' => $operation_id,
            'amount' => $operation_amount,
            'recipient_identifier' => $recipient_identifier,
          ];
        }

        $this->submitMarkedOperation(
          $current,
          PendingOperation::Capture,
          $capture_amount->getNumber(),
          fn () => $this->api_client->completeHold(
            $gateway,
            new CompleteHoldRequest($current->getRemoteId() ?? '', $request_amount, $operations),
          ),
        );
      },
    );
  }

  /**
   * {@inheritdoc}
   */
  public function void(
    PaymentInterface $payment,
    RuntimeConfigurationProviderInterface $gateway,
  ): void {
    $this->executeLocked(
      $payment,
      PendingOperation::Void,
      function (PaymentInterface $current) use ($gateway): void {
        $this->submitMarkedOperation(
          $current,
          PendingOperation::Void,
          '',
          fn () => $this->api_client->voidPayment(
            $gateway,
            new VoidRequest($current->getRemoteId() ?? ''),
          ),
        );
      },
    );
  }

  /**
   * Runs an operation against a freshly loaded payment under one lock.
   */
  private function executeLocked(
    PaymentInterface $payment,
    PendingOperation $operation,
    callable $callback,
  ): void {
    $payment_id = $payment->id();
    if (!is_int($payment_id) && !is_string($payment_id)) {
      throw InvalidRequestException::createForPayment(
        $payment,
        'The payment must be saved before it can be updated.',
      );
    }
    $session_id = $payment->getRemoteId();
    if (!is_string($session_id) || trim($session_id) === '') {
      throw InvalidRequestException::createForPayment(
        $payment,
        'The NovaPay session ID is unavailable.',
      );
    }
    $session_id = trim($session_id);
    $lock_name = SessionLockName::fromSessionId($session_id);
    if (!$this->lock->acquire($lock_name, self::LOCK_TIMEOUT_SECONDS)) {
      throw PaymentGatewayException::createForPayment(
        $payment,
        'Another NovaPay operation is already being processed.',
      );
    }

    try {
      $current = $this->loadCurrentPayment($payment);
      if (!$this->isAvailableAuthorization($current)) {
        throw InvalidRequestException::createForPayment(
          $current,
          'This authorization is not available for another NovaPay operation.',
        );
      }
      $remote_id = $current->getRemoteId();
      if (!is_string($remote_id) || trim($remote_id) !== $session_id) {
        throw InvalidRequestException::createForPayment(
          $current,
          'The NovaPay session ID changed while preparing the operation.',
        );
      }

      $callback($current);
      if ($current !== $payment) {
        $this->setPendingValues(
          $payment,
          $operation,
          $current->get('novapay_pending_amount')->getString(),
        );
      }
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Persists the pending marker before sending a non-idempotent request.
   */
  private function submitMarkedOperation(
    PaymentInterface $payment,
    PendingOperation $operation,
    string $amount,
    callable $submit,
  ): void {
    $this->setPendingValues($payment, $operation, $amount);
    $payment->save();

    try {
      $submit();
    }
    catch (\Throwable $exception) {
      if (!$this->hasUncertainOutcome($exception)) {
        $this->clearPendingValues($payment);
        $payment->save();
      }
      throw PaymentGatewayException::createForPayment(
        $payment,
        $this->hasUncertainOutcome($exception)
          ? 'The NovaPay response is uncertain. Wait for postback confirmation before retrying.'
          : 'NovaPay rejected the operation request.',
        previous: $exception,
      );
    }
  }

  /**
   * Loads the persisted payment after discarding the entity cache.
   */
  private function loadCurrentPayment(
    PaymentInterface $payment,
  ): PaymentInterface {
    $storage = $this->entity_type_manager->getStorage('commerce_payment');
    if (!$storage instanceof PaymentStorageInterface) {
      throw PaymentGatewayException::createForPayment(
        $payment,
        'Commerce payment storage is unavailable.',
      );
    }
    $payment_id = $payment->id();
    $storage->resetCache([$payment_id]);
    $current = $storage->load($payment_id);
    if (
      !$current instanceof PaymentInterface
      || $current->getPaymentGatewayId() !== $payment->getPaymentGatewayId()
      || $current->bundle() !== 'novapay_payment'
    ) {
      throw InvalidRequestException::createForPayment(
        $payment,
        'The NovaPay payment could not be reloaded safely.',
      );
    }

    return $current;
  }

  /**
   * Checks authorization state and the durable in-flight marker.
   */
  private function isAvailableAuthorization(
    PaymentInterface $payment,
  ): bool {
    return $payment->getState()->getId() === 'authorization'
      && !$payment->isExpired()
      && $payment->hasField('novapay_pending_operation')
      && $payment->get('novapay_pending_operation')->isEmpty();
  }

  /**
   * Writes bounded non-sensitive operation metadata.
   */
  private function setPendingValues(
    PaymentInterface $payment,
    PendingOperation $operation,
    string $amount,
  ): void {
    $payment->set('novapay_pending_operation', $operation->value);
    $payment->set('novapay_pending_amount', $amount);
  }

  /**
   * Clears operation metadata after a definitive rejected response.
   */
  private function clearPendingValues(PaymentInterface $payment): void {
    $payment->set('novapay_pending_operation', NULL);
    $payment->set('novapay_pending_amount', NULL);
  }

  /**
   * Returns whether NovaPay might have accepted the financial command.
   */
  private function hasUncertainOutcome(\Throwable $exception): bool {
    if (
      $exception instanceof ApiTransportException
      || $exception instanceof ApiFatalException
      || $exception instanceof ApiProcessingException
    ) {
      return TRUE;
    }
    if (
      $exception instanceof NovaPayApiException
      && ($exception->getHttpStatus() ?? 0) >= 500
    ) {
      return TRUE;
    }
    if ($exception instanceof ApiUnexpectedResponseException) {
      $status = $exception->getHttpStatus();
      return is_int($status) && $status >= 200 && $status < 300;
    }

    return FALSE;
  }

}
