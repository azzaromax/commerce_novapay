<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Postback;

/**
 * Identifies the supported acquiring postback format.
 */
enum PostbackVersion: string {

  case V2 = 'v2';

}
