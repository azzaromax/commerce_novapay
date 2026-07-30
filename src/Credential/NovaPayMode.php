<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Credential;

/**
 * Defines the supported NovaPay API environments.
 */
enum NovaPayMode: string {

  case Test = 'test';
  case Live = 'live';

}
