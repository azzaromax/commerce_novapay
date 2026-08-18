<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Postback\Parser;

use Drupal\commerce_novapay\Exception\InvalidPostbackException;
use Drupal\commerce_novapay\Postback\Dto\NormalizedPostbackEvent;

/**
 * Parses the documented flat acquiring postback v1 sandbox schema.
 */
final class V1PostbackParser implements PostbackVersionParserInterface {

  /**
   * {@inheritdoc}
   */
  public function supports(array $payload): bool {
    return !array_key_exists('payments', $payload)
      && array_key_exists('external_id', $payload)
      && array_key_exists('amount', $payload);
  }

  /**
   * {@inheritdoc}
   */
  public function parse(array $payload): NormalizedPostbackEvent {
    if (!$this->supports($payload) || !self::isAmount($payload['amount'])) {
      throw InvalidPostbackException::unsupportedSchema();
    }

    return NormalizedPostbackEvent::fromValues(
      $payload['id'] ?? NULL,
      $payload['status'] ?? NULL,
      [$payload['external_id']],
      array_key_exists('refunded_amount', $payload)
        ? [$payload['refunded_amount']]
        : [],
    );
  }

  /**
   * Checks a documented JSON number or decimal-string amount shape.
   */
  private static function isAmount(mixed $amount): bool {
    return (
      is_int($amount)
      || is_float($amount) && is_finite($amount)
    ) && $amount >= 0
      || is_string($amount)
      && preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $amount) === 1;
  }

}
