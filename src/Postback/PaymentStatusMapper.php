<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Postback;

use Drupal\commerce_novapay\Payment\PendingOperation;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;

/**
 * Maps documented NovaPay statuses to Commerce payment transitions.
 */
final class PaymentStatusMapper implements PaymentStatusMapperInterface {

  /**
   * {@inheritdoc}
   */
  public function apply(
    PaymentInterface $payment,
    NovaPayStatus $status,
  ): bool {
    $state = $payment->getState();
    $transition_id = $this->getTransitionId($state->getId(), $status);
    if ($transition_id !== NULL) {
      $this->validateConfirmedPartialCapture($payment, $status);
      $this->applyConfirmedFullRefund($payment, $status);
      $this->clearPendingOperation($payment);
      $state->applyTransitionById($transition_id);
      $payment->setRemoteState($status->value);
      $payment->save();
      $payment->getOrder()->save();
      return TRUE;
    }

    if (
      $this->canUpdateRemoteState($state->getId(), $status)
      && $payment->getRemoteState() !== $status->value
    ) {
      $payment->setRemoteState($status->value);
      $payment->save();
      return TRUE;
    }

    if ($this->canClearPendingOperationForHolded($payment, $status)) {
      $this->clearPendingOperation($payment);
      $payment->setRemoteState($status->value);
      $payment->save();
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Clears an uncertain capture or void once NovaPay confirms the hold remains.
   */
  private function canClearPendingOperationForHolded(
    PaymentInterface $payment,
    NovaPayStatus $status,
  ): bool {
    if (
      $status !== NovaPayStatus::Holded
      || $payment->getState()->getId() !== 'authorization'
      || !$payment->hasField('novapay_pending_operation')
    ) {
      return FALSE;
    }

    return in_array(
      PendingOperation::tryFrom(
        $payment->get('novapay_pending_operation')->getString(),
      ),
      [PendingOperation::Capture, PendingOperation::Void],
      TRUE,
    );
  }

  /**
   * Keeps Commerce balance consistent for a signed full-refund postback.
   */
  private function applyConfirmedFullRefund(
    PaymentInterface $payment,
    NovaPayStatus $status,
  ): void {
    if ($status !== NovaPayStatus::Voided) {
      return;
    }
    if (!in_array(
      $payment->getState()->getId(),
      ['completed', 'partially_refunded'],
      TRUE,
    )) {
      return;
    }

    $amount = $payment->getAmount();
    if ($amount instanceof Price) {
      $payment->setRefundedAmount($amount);
    }
  }

  /**
   * Validates a submitted partial capture once its postback is final.
   *
   * NovaPay releases the remainder of a partially completed hold. The business
   * workflow treats that final postback as payment of the whole order, so the
   * original authorization amount must remain on the Commerce payment.
   */
  private function validateConfirmedPartialCapture(
    PaymentInterface $payment,
    NovaPayStatus $status,
  ): void {
    if (
      !in_array(
        $status,
        [NovaPayStatus::Paid, NovaPayStatus::HoldConfirmed],
        TRUE,
      )
      ||
      !$payment->hasField('novapay_pending_operation')
      || $payment->get('novapay_pending_operation')->getString()
        !== PendingOperation::Capture->value
    ) {
      return;
    }

    $authorized_amount = $payment->getAmount();
    $number = $payment->get('novapay_pending_amount')->getString();
    if (!$authorized_amount instanceof Price || $number === '') {
      throw new \UnexpectedValueException(
        'The pending NovaPay capture amount is invalid.',
      );
    }
    $capture_amount = new Price(
      $number,
      $authorized_amount->getCurrencyCode(),
    );
    if (
      !$capture_amount->isPositive()
      || $capture_amount->greaterThan($authorized_amount)
    ) {
      throw new \UnexpectedValueException(
        'The pending NovaPay capture amount is outside the authorization.',
      );
    }

  }

  /**
   * Clears a durable operation marker after a financial transition.
   */
  private function clearPendingOperation(PaymentInterface $payment): void {
    if (!$payment->hasField('novapay_pending_operation')) {
      return;
    }

    $payment->set('novapay_pending_operation', NULL);
    $payment->set('novapay_pending_amount', NULL);
  }

  /**
   * Determines whether an intermediate status is monotonic for this state.
   */
  private function canUpdateRemoteState(
    string $current_state,
    NovaPayStatus $status,
  ): bool {
    return match ($current_state) {
      'pending' => in_array(
        $status,
        [NovaPayStatus::Created, NovaPayStatus::Processing],
        TRUE,
      ),
      'authorization' => in_array(
        $status,
        [
          NovaPayStatus::ProcessingHoldCompletion,
          NovaPayStatus::ProcessingVoid,
        ],
        TRUE,
      ),
      'completed', 'partially_refunded' =>
        $status === NovaPayStatus::ProcessingVoid,
      default => FALSE,
    };
  }

  /**
   * Gets the allowed workflow transition for a current/remote state pair.
   */
  private function getTransitionId(
    string $current_state,
    NovaPayStatus $status,
  ): ?string {
    return match ($current_state) {
      'pending' => match ($status) {
        NovaPayStatus::Holded => 'authorize',
        NovaPayStatus::Paid,
        NovaPayStatus::HoldConfirmed => 'authorize_capture',
        NovaPayStatus::Expired => 'expire',
        NovaPayStatus::Failed => 'fail',
        default => NULL,
      },
      'authorization' => match ($status) {
        NovaPayStatus::Paid,
        NovaPayStatus::HoldConfirmed => 'capture',
        NovaPayStatus::Voided => 'void',
        default => NULL,
      },
      'failed' => match ($status) {
        NovaPayStatus::Processing => 'retry',
        NovaPayStatus::Holded => 'retry_authorize',
        NovaPayStatus::Paid,
        NovaPayStatus::HoldConfirmed => 'retry_authorize_capture',
        default => NULL,
      },
      'completed', 'partially_refunded' => match ($status) {
        NovaPayStatus::Voided => 'refund',
        default => NULL,
      },
      default => NULL,
    };
  }

}
