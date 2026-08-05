<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Postback;

use Drupal\commerce_payment\Entity\PaymentInterface;

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
  ): void {
    $state = $payment->getState();
    $transition_id = $this->getTransitionId($state->getId(), $status);
    if ($transition_id !== NULL) {
      $state->applyTransitionById($transition_id);
      $payment->setRemoteState($status->value);
      $payment->save();
      return;
    }

    if (
      $this->canUpdateRemoteState($state->getId(), $status)
      && $payment->getRemoteState() !== $status->value
    ) {
      $payment->setRemoteState($status->value);
      $payment->save();
    }
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
      'completed', 'partially_refunded' => match ($status) {
        NovaPayStatus::Voided => 'refund',
        default => NULL,
      },
      default => NULL,
    };
  }

}
