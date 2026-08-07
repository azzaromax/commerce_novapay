<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;

/**
 * Submits NovaPay authorization capture and void commands.
 */
interface AuthorizationOperationManagerInterface {

  /**
   * Returns whether an authorization can be captured.
   */
  public function canCapture(PaymentInterface $payment): bool;

  /**
   * Returns whether an authorization can be voided.
   */
  public function canVoid(PaymentInterface $payment): bool;

  /**
   * Submits a full or partial capture command.
   */
  public function capture(
    PaymentInterface $payment,
    RuntimeConfigurationProviderInterface $gateway,
    ?Price $amount = NULL,
  ): void;

  /**
   * Submits a full authorization void command.
   */
  public function void(
    PaymentInterface $payment,
    RuntimeConfigurationProviderInterface $gateway,
  ): void;

}
