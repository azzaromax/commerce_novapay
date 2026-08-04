<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Postback\Dto;

use Drupal\commerce_novapay\Exception\InvalidPostbackException;
use Drupal\commerce_novapay\Postback\NovaPayStatus;

/**
 * Contains the payment-relevant fields shared by postback v1 and v2.
 */
final class NormalizedPostbackEvent {

  private const MAX_IDENTIFIER_BYTES = 255;

  /**
   * Constructs a normalized postback event.
   *
   * @param string $session_id
   *   The NovaPay session identifier.
   * @param \Drupal\commerce_novapay\Postback\NovaPayStatus $status
   *   The documented NovaPay status.
   * @param list<string> $external_ids
   *   Unique external order identifiers from the postback.
   */
  private function __construct(
    private readonly string $session_id,
    private readonly NovaPayStatus $status,
    private readonly array $external_ids,
  ) {}

  /**
   * Creates a validated normalized event from parser values.
   *
   * @param mixed $session_id
   *   The raw session identifier.
   * @param mixed $status
   *   The raw NovaPay status.
   * @param list<mixed> $external_ids
   *   Raw external identifiers.
   */
  public static function fromValues(
    mixed $session_id,
    mixed $status,
    array $external_ids,
  ): self {
    $session_id = self::validateIdentifier($session_id);
    if (!is_string($status)) {
      throw InvalidPostbackException::unsupportedSchema();
    }
    $status = NovaPayStatus::tryFrom($status);
    if ($status === NULL) {
      throw InvalidPostbackException::unsupportedSchema();
    }

    $normalized_external_ids = [];
    foreach ($external_ids as $external_id) {
      $external_id = self::validateIdentifier($external_id);
      $normalized_external_ids[$external_id] = $external_id;
    }

    return new self(
      $session_id,
      $status,
      array_values($normalized_external_ids),
    );
  }

  /**
   * Gets the NovaPay session identifier.
   */
  public function getSessionId(): string {
    return $this->session_id;
  }

  /**
   * Gets the documented NovaPay status.
   */
  public function getStatus(): NovaPayStatus {
    return $this->status;
  }

  /**
   * Gets unique external order identifiers.
   *
   * @return list<string>
   *   External order identifiers.
   */
  public function getExternalIds(): array {
    return $this->external_ids;
  }

  /**
   * Validates a bounded identifier without control characters.
   */
  private static function validateIdentifier(mixed $value): string {
    if (!is_string($value)) {
      throw InvalidPostbackException::unsupportedSchema();
    }
    $value = trim($value);
    if (
      $value === ''
      || strlen($value) > self::MAX_IDENTIFIER_BYTES
      || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
    ) {
      throw InvalidPostbackException::unsupportedSchema();
    }

    return $value;
  }

}
