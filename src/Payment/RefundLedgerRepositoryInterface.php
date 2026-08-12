<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

/**
 * Stores confirmed item-level NovaPay refunds.
 */
interface RefundLedgerRepositoryInterface {

  /**
   * Gets confirmed refunded quantities keyed by order item ID.
   *
   * @param int $payment_id
   *   Commerce payment identifier.
   *
   * @return array<int, string>
   *   Exact decimal quantities.
   */
  public function getRefundedQuantities(int $payment_id): array;

  /**
   * Records one authoritative NovaPay confirmation atomically.
   *
   * @param int $payment_id
   *   Commerce payment identifier.
   * @param string $event_key
   *   SHA-256 hash of the bounded confirming evidence.
   * @param list<\Drupal\commerce_novapay\Payment\RefundSelection> $items
   *   Confirmed item selections.
   */
  public function recordConfirmed(
    int $payment_id,
    string $event_key,
    array $items,
  ): void;

}
