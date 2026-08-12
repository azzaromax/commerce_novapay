<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\commerce_novapay\Postback\Dto\NormalizedPostbackEvent;
use Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;

/**
 * Submits item-level refunds and applies signed confirmations.
 */
interface RefundOperationManagerInterface {

  /**
   * Checks whether a payment can accept another refund command.
   */
  public function canRefund(PaymentInterface $payment): bool;

  /**
   * Checks whether a payment has a pending refund to reconcile.
   */
  public function canCheckStatus(PaymentInterface $payment): bool;

  /**
   * Gets the current item-level refund availability.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The NovaPay payment.
   *
   * @return list<\Drupal\commerce_novapay\Payment\RefundableItem>
   *   Refundable order items.
   */
  public function getRefundableItems(PaymentInterface $payment): array;

  /**
   * Submits a full refund for an empty selection or a partial item refund.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The NovaPay payment.
   * @param \Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface $gateway
   *   Runtime-aware NovaPay gateway.
   * @param array<int, string> $quantities
   *   Selected quantities keyed by order item ID.
   */
  public function refund(
    PaymentInterface $payment,
    RuntimeConfigurationProviderInterface $gateway,
    array $quantities = [],
  ): void;

  /**
   * Reconciles a pending refund against NovaPay's session status.
   */
  public function checkStatus(
    PaymentInterface $payment,
    RuntimeConfigurationProviderInterface $gateway,
  ): RefundStatusCheckResult;

  /**
   * Applies a refund intent from an authoritative NovaPay confirmation.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The NovaPay payment.
   * @param \Drupal\commerce_novapay\Postback\Dto\NormalizedPostbackEvent $event
   *   Verified normalized postback event.
   * @param string $event_key
   *   SHA-256 hash of the exact signed body.
   */
  public function confirm(
    PaymentInterface $payment,
    NormalizedPostbackEvent $event,
    string $event_key,
  ): void;

}
