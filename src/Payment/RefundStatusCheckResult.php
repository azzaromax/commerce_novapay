<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

/**
 * Describes the outcome of a read-only NovaPay refund status check.
 */
enum RefundStatusCheckResult: string {

  case Confirmed = 'confirmed';
  case Pending = 'pending';

}
