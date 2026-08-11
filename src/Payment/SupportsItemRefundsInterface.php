<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\commerce_payment\Entity\PaymentInterface;

/**
 * Exposes NovaPay item-level refunds to the custom Commerce plugin form.
 */
interface SupportsItemRefundsInterface {

  /**
   * Gets the current item-level refund availability.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The NovaPay payment.
   *
   * @return list<\Drupal\commerce_novapay\Payment\RefundableItem>
   *   Current refundable items.
   */
  public function getRefundableItems(PaymentInterface $payment): array;

  /**
   * Submits selected item quantities for a NovaPay refund.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The NovaPay payment.
   * @param array<int, string> $quantities
   *   Selected quantities keyed by order item ID.
   */
  public function refundItems(
    PaymentInterface $payment,
    array $quantities,
  ): void;

}
