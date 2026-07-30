<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Credential;

use Drupal\commerce_novapay\Exception\InvalidCredentialsException;

/**
 * Loads the packaged NovaPay sandbox credentials from protected PHP resources.
 */
final class SandboxCredentialProvider implements SandboxCredentialProviderInterface {

  private const SANDBOX_MERCHANT_ID = '2';

  /**
   * Constructs a sandbox credential provider.
   */
  public function __construct(
    private readonly RsaKeyValidatorInterface $key_validator,
    private readonly ?string $resource_directory = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getCredentials(): Credentials {
    [$private_key, $public_key] = $this->loadKeys();
    $this->key_validator->validatePrivateKey($private_key);
    $this->key_validator->validatePublicKey($public_key);

    return new Credentials(
      NovaPayMode::Test,
      self::SANDBOX_MERCHANT_ID,
      $private_key,
      $public_key,
    );
  }

  /**
   * Loads both packaged resource values.
   *
   * @return array{0: string, 1: string}
   *   The merchant private key and NovaPay public key.
   */
  private function loadKeys(): array {
    $resource_directory = $this->resource_directory
      ?? dirname(__DIR__, 2) . '/resources/test';

    try {
      $private_key = require $resource_directory . '/private-key.php';
      $public_key = require $resource_directory . '/public-key.php';
    }
    catch (\Throwable) {
      throw InvalidCredentialsException::sandboxCredentialsUnavailable();
    }

    if (!is_string($private_key) || !is_string($public_key)) {
      throw InvalidCredentialsException::sandboxCredentialsUnavailable();
    }

    return [$private_key, $public_key];
  }

}
