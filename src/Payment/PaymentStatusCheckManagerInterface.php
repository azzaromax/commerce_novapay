<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;

/**
 * Reconciles an existing NovaPay payment with its remote session status.
 */
interface PaymentStatusCheckManagerInterface {

  /**
   * Returns whether a payment can be reconciled without a financial command.
   */
  public function canCheckStatus(PaymentInterface $payment): bool;

  /**
   * Performs one signed read-only NovaPay session status check.
   */
  public function checkStatus(
    PaymentInterface $payment,
    RuntimeConfigurationProviderInterface $gateway,
  ): PaymentStatusCheckResult;

}
