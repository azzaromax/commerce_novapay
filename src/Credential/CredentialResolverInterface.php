<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Credential;

use Drupal\commerce_novapay\Runtime\RuntimeConfiguration;

/**
 * Resolves environment-local NovaPay credentials.
 */
interface CredentialResolverInterface {

  /**
   * Resolves one atomic snapshot of profile, credentials, and endpoint.
   */
  public function resolveRuntimeConfiguration(
    string $gateway_uuid,
  ): RuntimeConfiguration;

}
