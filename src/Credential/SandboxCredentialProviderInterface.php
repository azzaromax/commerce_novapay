<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Credential;

/**
 * Provides the packaged official NovaPay sandbox fixture.
 */
interface SandboxCredentialProviderInterface {

  /**
   * Gets validated fixed sandbox credentials.
   */
  public function getCredentials(): Credentials;

}
