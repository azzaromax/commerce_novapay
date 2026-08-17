<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

/**
 * Lists safe outcomes of a manual NovaPay payment status check.
 */
enum PaymentStatusCheckResult: string {

  case Reconciled = 'reconciled';
  case Unchanged = 'unchanged';

}
