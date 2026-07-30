<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Credential;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\commerce_novapay\Exception\InvalidCredentialsException;
use Drupal\commerce_novapay\Runtime\RuntimeConfiguration;
use Drupal\commerce_novapay\Runtime\RuntimeProfile;

/**
 * Resolves sandbox fixtures or environment-local live credentials.
 */
final class CredentialResolver implements CredentialResolverInterface {

  private const MAX_PROFILE_BYTES = 65536;

  private const MAX_KEY_BYTES = 65536;

  /**
   * Constructs a NovaPay credential resolver.
   */
  public function __construct(
    private readonly RsaKeyValidatorInterface $key_validator,
    private readonly SandboxCredentialProviderInterface $sandbox_credentials,
    private readonly LockBackendInterface $lock,
    private readonly string $private_base_uri = 'private://commerce_novapay',
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolveRuntimeConfiguration(
    string $gateway_uuid,
  ): RuntimeConfiguration {
    $this->assertGatewayUuid($gateway_uuid);
    $directory = rtrim($this->private_base_uri, '/') . '/' . $gateway_uuid;
    $lock_name = self::getLockName($gateway_uuid);
    if (!$this->acquireLock($lock_name)) {
      throw InvalidCredentialsException::liveProfileUnavailable();
    }

    try {
      try {
        $profile = RuntimeProfile::fromArray(
          $this->readProfile($directory . '/settings.json'),
        );
      }
      catch (\RuntimeException) {
        throw InvalidCredentialsException::liveProfileUnavailable();
      }

      $credentials = match ($profile->getMode()) {
        NovaPayMode::Test => $this->resolveSandboxCredentials(),
        NovaPayMode::Live => $this->readLiveCredentials(
          $directory,
          $profile->getMerchantId(),
        ),
      };

      return new RuntimeConfiguration($profile, $credentials);
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Gets the cooperative lock name shared with profile writes.
   */
  public static function getLockName(string $gateway_uuid): string {
    return 'commerce_novapay.runtime_profile.' . $gateway_uuid;
  }

  /**
   * Resolves the packaged NovaPay sandbox fixture.
   */
  private function resolveSandboxCredentials(): Credentials {
    return $this->sandbox_credentials->getCredentials();
  }

  /**
   * Reads live credentials while the caller holds the profile lock.
   */
  private function readLiveCredentials(
    string $directory,
    mixed $merchant_id,
  ): Credentials {
    if (
      !is_string($merchant_id)
      || trim($merchant_id) === ''
      || strlen($merchant_id) > 128
    ) {
      throw InvalidCredentialsException::invalidMerchantId();
    }

    $private_key = $this->readKey(
      $directory . '/private.pem',
      TRUE,
    );
    $public_key = $this->readKey(
      $directory . '/public.pem',
      FALSE,
    );

    $this->key_validator->validatePrivateKey($private_key);
    $this->key_validator->validatePublicKey($public_key);

    return new Credentials(
      NovaPayMode::Live,
      trim($merchant_id),
      $private_key,
      $public_key,
    );
  }

  /**
   * Reads and decodes a local runtime profile.
   *
   * @return array<string, mixed>
   *   The decoded profile.
   */
  private function readProfile(string $uri): array {
    $contents = $this->readFile($uri, self::MAX_PROFILE_BYTES);
    if ($contents === NULL) {
      throw InvalidCredentialsException::liveProfileUnavailable();
    }

    try {
      $profile = json_decode(
        $contents,
        TRUE,
        16,
        JSON_THROW_ON_ERROR,
      );
    }
    catch (\JsonException) {
      throw InvalidCredentialsException::liveProfileUnavailable();
    }

    if (!is_array($profile)) {
      throw InvalidCredentialsException::liveProfileUnavailable();
    }

    return $profile;
  }

  /**
   * Reads one key without leaking its URI or PHP warnings.
   */
  private function readKey(string $uri, bool $private): string {
    $contents = $this->readFile($uri, self::MAX_KEY_BYTES);
    if ($contents === NULL || trim($contents) === '') {
      throw $private
        ? InvalidCredentialsException::privateKeyUnavailable()
        : InvalidCredentialsException::publicKeyUnavailable();
    }

    return $contents;
  }

  /**
   * Reads a size-limited local file.
   */
  private function readFile(string $uri, int $maximum_bytes): ?string {
    $contents = @file_get_contents($uri, FALSE, NULL, 0, $maximum_bytes + 1);
    if (!is_string($contents) || strlen($contents) > $maximum_bytes) {
      return NULL;
    }

    return $contents;
  }

  /**
   * Rejects traversal and non-UUID gateway identifiers.
   */
  private function assertGatewayUuid(string $gateway_uuid): void {
    if (
      preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',
        $gateway_uuid,
      ) !== 1
    ) {
      throw InvalidCredentialsException::invalidGatewayIdentifier();
    }
  }

  /**
   * Acquires a short-lived credential read lock.
   */
  private function acquireLock(string $lock_name): bool {
    if ($this->lock->acquire($lock_name, 10.0)) {
      return TRUE;
    }

    $this->lock->wait($lock_name, 5);
    return $this->lock->acquire($lock_name, 10.0);
  }

}
