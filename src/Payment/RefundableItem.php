<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\commerce_price\Price;

/**
 * Describes the remaining refundable quantity for one order item.
 */
final class RefundableItem {

  public function __construct(
    private readonly int $order_item_id,
    private readonly string $title,
    private readonly string $ordered_quantity,
    private readonly string $refunded_quantity,
    private readonly string $available_quantity,
    private readonly Price $unit_price,
  ) {}

  /**
   * Gets the Commerce order item identifier.
   */
  public function getOrderItemId(): int {
    return $this->order_item_id;
  }

  /**
   * Gets the order item title.
   */
  public function getTitle(): string {
    return $this->title;
  }

  /**
   * Gets the original paid quantity.
   */
  public function getOrderedQuantity(): string {
    return $this->ordered_quantity;
  }

  /**
   * Gets the confirmed refunded quantity.
   */
  public function getRefundedQuantity(): string {
    return $this->refunded_quantity;
  }

  /**
   * Gets the remaining refundable quantity.
   */
  public function getAvailableQuantity(): string {
    return $this->available_quantity;
  }

  /**
   * Gets the adjusted unit price used for refund calculation.
   */
  public function getUnitPrice(): Price {
    return $this->unit_price;
  }

}
