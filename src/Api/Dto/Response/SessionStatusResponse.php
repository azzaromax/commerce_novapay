<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Api\Dto\Response;

use Drupal\commerce_novapay\Postback\NovaPayStatus;
use Drupal\commerce_price\Calculator;

/**
 * Contains only the non-sensitive fields needed to reconcile a refund.
 */
final class SessionStatusResponse {

  private const MAX_IDENTIFIER_BYTES = 255;

  /**
   * Constructs a projected NovaPay session status.
   *
   * @param string $session_id
   *   NovaPay session identifier.
   * @param \Drupal\commerce_novapay\Postback\NovaPayStatus $status
   *   Current NovaPay session status.
   * @param array<string, string> $refunded_amounts
   *   Cumulative refunded amounts keyed by NovaPay transaction ID.
   */
  private function __construct(
    private readonly string $session_id,
    private readonly NovaPayStatus $status,
    private readonly array $refunded_amounts,
  ) {}

  /**
   * Builds a bounded response without retaining PAN or customer data.
   *
   * @param array<string, mixed> $data
   *   Decoded NovaPay status response.
   */
  public static function fromArray(array $data): self {
    $session_id = self::validateIdentifier($data['id'] ?? NULL);
    $status_value = $data['status'] ?? NULL;
    $status = is_string($status_value)
      ? NovaPayStatus::tryFrom($status_value)
      : NULL;
    if ($status === NULL) {
      throw new \InvalidArgumentException('Status response is invalid.');
    }

    $operations = $data['operations'] ?? [];
    if (!is_array($operations) || !array_is_list($operations)) {
      throw new \InvalidArgumentException('Status response is invalid.');
    }
    $refunded_amounts = [];
    foreach ($operations as $operation) {
      if (!is_array($operation)) {
        throw new \InvalidArgumentException('Status response is invalid.');
      }
      if (!array_key_exists('refunded_amount', $operation)) {
        continue;
      }
      $transaction_id = self::validateIdentifier(
        $operation['transaction_id'] ?? NULL,
      );
      $refunded_amounts[$transaction_id] = self::validateAmount(
        $operation['refunded_amount'],
      );
    }

    return new self($session_id, $status, $refunded_amounts);
  }

  /**
   * Gets the NovaPay session identifier.
   */
  public function getSessionId(): string {
    return $this->session_id;
  }

  /**
   * Gets the current NovaPay session status.
   */
  public function getStatus(): NovaPayStatus {
    return $this->status;
  }

  /**
   * Gets the cumulative refund amount for a specific payment operation.
   */
  public function getRefundedAmount(string $operation_id): ?string {
    return $this->refunded_amounts[$operation_id] ?? NULL;
  }

  /**
   * Validates a bounded NovaPay identifier.
   */
  private static function validateIdentifier(mixed $value): string {
    if (!is_string($value)) {
      throw new \InvalidArgumentException('Status response is invalid.');
    }
    $value = trim($value);
    if (
      $value === ''
      || strlen($value) > self::MAX_IDENTIFIER_BYTES
      || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
    ) {
      throw new \InvalidArgumentException('Status response is invalid.');
    }
    return $value;
  }

  /**
   * Validates and normalizes an exact non-negative decimal amount.
   */
  private static function validateAmount(mixed $value): string {
    if (is_int($value)) {
      $value = (string) $value;
    }
    elseif (is_float($value) && is_finite($value)) {
      $encoded = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
      $value = is_string($encoded) ? $encoded : '';
    }
    if (
      !is_string($value)
      || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/D', $value) !== 1
    ) {
      throw new \InvalidArgumentException('Status response is invalid.');
    }
    return Calculator::trim($value);
  }

}
