<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

/**
 * Lists financial commands awaiting a confirming NovaPay postback.
 */
enum PendingOperation: string {

  case Capture = 'capture';
  case Void = 'void';

}
