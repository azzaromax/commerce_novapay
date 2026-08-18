<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Postback;

/**
 * Identifies documented acquiring postback formats.
 */
enum PostbackVersion: string {

  case V1 = 'v1';
  case V2 = 'v2';

}
