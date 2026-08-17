<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\commerce_payment\Entity\PaymentInterface;

/**
 * Exposes read-only NovaPay payment status reconciliation.
 */
interface SupportsPaymentStatusChecksInterface {

  /**
   * Returns whether the payment has a reconcilable NovaPay session state.
   */
  public function canCheckPaymentStatus(PaymentInterface $payment): bool;

  /**
   * Checks and safely reconciles the payment at NovaPay.
   */
  public function checkPaymentStatus(
    PaymentInterface $payment,
  ): PaymentStatusCheckResult;

}
