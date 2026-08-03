<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Runtime;

/**
 * Provides an atomic NovaPay runtime configuration for an API call.
 */
interface RuntimeConfigurationProviderInterface {

  /**
   * Resolves the endpoint and credentials for the current environment.
   */
  public function getRuntimeConfiguration(): RuntimeConfiguration;

}
