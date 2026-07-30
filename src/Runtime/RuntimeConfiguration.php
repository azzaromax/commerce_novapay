<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Runtime;

use Drupal\commerce_novapay\Credential\Credentials;
use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Exception\InvalidRuntimeProfileException;

/**
 * Binds one endpoint to credentials from the same resolved environment.
 */
final class RuntimeConfiguration {

  private const TEST_API_BASE_URL = 'https://api-qecom.novapay.ua';

  private const LIVE_API_BASE_URL = 'https://api-ecom.novapay.ua';

  /**
   * Constructs a consistent runtime configuration.
   */
  public function __construct(
    private readonly RuntimeProfile $profile,
    private readonly Credentials $credentials,
  ) {
    if ($this->profile->getMode() !== $this->credentials->getMode()) {
      throw InvalidRuntimeProfileException::invalidProfile();
    }
  }

  /**
   * Gets the local runtime profile.
   */
  public function getProfile(): RuntimeProfile {
    return $this->profile;
  }

  /**
   * Gets credentials from the same environment as the endpoint.
   */
  public function getCredentials(): Credentials {
    return $this->credentials;
  }

  /**
   * Gets the API base URL bound to the resolved mode.
   */
  public function getApiBaseUrl(): string {
    return match ($this->profile->getMode()) {
      NovaPayMode::Test => self::TEST_API_BASE_URL,
      NovaPayMode::Live => self::LIVE_API_BASE_URL,
    };
  }

}
