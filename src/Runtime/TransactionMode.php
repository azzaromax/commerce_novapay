<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Runtime;

/**
 * Defines supported NovaPay transaction modes.
 */
enum TransactionMode: string {

  case Direct = 'direct';
  case Hold = 'hold';

}
