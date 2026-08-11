<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Postback\Parser;

use Drupal\commerce_novapay\Exception\InvalidPostbackException;
use Drupal\commerce_novapay\Postback\Dto\NormalizedPostbackEvent;

/**
 * Parses the documented combined acquiring postback v2 schema.
 */
final class V2PostbackParser implements PostbackVersionParserInterface {

  /**
   * {@inheritdoc}
   */
  public function supports(array $payload): bool {
    return array_key_exists('payments', $payload);
  }

  /**
   * {@inheritdoc}
   */
  public function parse(array $payload): NormalizedPostbackEvent {
    $payments = $payload['payments'] ?? NULL;
    if (!is_array($payments) || !array_is_list($payments) || $payments === []) {
      throw InvalidPostbackException::unsupportedSchema();
    }

    $external_ids = [];
    $refunded_amounts = [];
    foreach ($payments as $payment) {
      if (
        !is_array($payment)
        || !array_key_exists('external_id', $payment)
        || !array_key_exists('amount', $payment)
        || !V1PostbackParser::isAmount($payment['amount'])
      ) {
        throw InvalidPostbackException::unsupportedSchema();
      }
      $external_ids[] = $payment['external_id'];
      if (array_key_exists('refunded_amount', $payment)) {
        $refunded_amounts[] = $payment['refunded_amount'];
      }
    }

    return NormalizedPostbackEvent::fromValues(
      $payload['id'] ?? NULL,
      $payload['status'] ?? NULL,
      $external_ids,
      $refunded_amounts,
    );
  }

}
