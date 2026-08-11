<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\commerce_price\Price;

/**
 * Contains an exact item quantity and amount awaiting refund confirmation.
 */
final class RefundSelection {

  public function __construct(
    private readonly int $order_item_id,
    private readonly string $quantity,
    private readonly Price $amount,
  ) {}

  /**
   * Gets the Commerce order item identifier.
   */
  public function getOrderItemId(): int {
    return $this->order_item_id;
  }

  /**
   * Gets the exact selected quantity.
   */
  public function getQuantity(): string {
    return $this->quantity;
  }

  /**
   * Gets the exact selected refund amount.
   */
  public function getAmount(): Price {
    return $this->amount;
  }

  /**
   * Returns bounded non-sensitive values for durable pending storage.
   *
   * @return array{order_item_id: int, quantity: string, amount: string}
   *   The serialized selection.
   */
  public function toArray(): array {
    return [
      'order_item_id' => $this->order_item_id,
      'quantity' => $this->quantity,
      'amount' => $this->amount->getNumber(),
    ];
  }

}
