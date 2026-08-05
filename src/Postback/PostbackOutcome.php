<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Postback;

/**
 * Describes the safe outcome returned to the gateway boundary.
 */
enum PostbackOutcome: string {

  case Applied = 'applied';
  case Duplicate = 'duplicate';
  case InvalidPayload = 'invalid_payload';
  case InvalidSignature = 'invalid_signature';
  case UnknownPayment = 'unknown_payment';

}
