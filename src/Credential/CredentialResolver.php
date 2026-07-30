<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Credential;

use Drupal\commerce_novapay\Exception\InvalidCredentialsException;

/**
 * Resolves sandbox fixtures or environment-local live credentials.
 */
final class CredentialResolver implements CredentialResolverInterface {

  private const MAX_PROFILE_BYTES = 65536;

  private const MAX_KEY_BYTES = 65536;

  private const SANDBOX_MERCHANT_ID = '2';

  /**
   * Constructs a NovaPay credential resolver.
   *
   * The optional paths exist for isolated tests. The container uses defaults.
   */
  public function __construct(
    private readonly string $private_base_uri = 'private://commerce_novapay',
    private readonly ?string $sandbox_resource_directory = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolve(
    string $gateway_uuid,
    NovaPayMode $mode,
  ): Credentials {
    $this->assertGatewayUuid($gateway_uuid);

    return match ($mode) {
      NovaPayMode::Test => $this->resolveSandboxCredentials(),
      NovaPayMode::Live => $this->resolveLiveCredentials($gateway_uuid),
    };
  }

  /**
   * Resolves the packaged NovaPay sandbox fixture.
   */
  private function resolveSandboxCredentials(): Credentials {
    $resource_directory = $this->sandbox_resource_directory
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

    $this->validatePrivateKey($private_key);
    $this->validatePublicKey($public_key);

    return new Credentials(
      NovaPayMode::Test,
      self::SANDBOX_MERCHANT_ID,
      $private_key,
      $public_key,
    );
  }

  /**
   * Resolves credentials from a local private filesystem directory.
   */
  private function resolveLiveCredentials(string $gateway_uuid): Credentials {
    $directory = rtrim($this->private_base_uri, '/') . '/' . $gateway_uuid;
    $profile = $this->readProfile($directory . '/settings.json');

    if (($profile['mode'] ?? NULL) !== NovaPayMode::Live->value) {
      throw InvalidCredentialsException::liveProfileUnavailable();
    }

    $merchant_id = $profile['merchant_id'] ?? NULL;
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

    $this->validatePrivateKey($private_key);
    $this->validatePublicKey($public_key);

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
   * Validates an RSA private key without exposing OpenSSL errors.
   */
  private function validatePrivateKey(
    #[\SensitiveParameter]
    string $pem,
  ): void {
    $this->clearOpenSslErrors();
    $key = openssl_pkey_get_private($pem);
    $this->clearOpenSslErrors();

    if ($key === FALSE) {
      throw InvalidCredentialsException::invalidPrivateKey();
    }

    $details = openssl_pkey_get_details($key);
    $this->clearOpenSslErrors();
    if ($details === FALSE || $details['type'] !== OPENSSL_KEYTYPE_RSA) {
      throw InvalidCredentialsException::invalidPrivateKey();
    }
  }

  /**
   * Validates an RSA public key without exposing OpenSSL errors.
   */
  private function validatePublicKey(
    #[\SensitiveParameter]
    string $pem,
  ): void {
    $this->clearOpenSslErrors();
    $key = openssl_pkey_get_public($pem);
    $this->clearOpenSslErrors();

    if ($key === FALSE) {
      throw InvalidCredentialsException::invalidPublicKey();
    }

    $details = openssl_pkey_get_details($key);
    $this->clearOpenSslErrors();
    if ($details === FALSE || $details['type'] !== OPENSSL_KEYTYPE_RSA) {
      throw InvalidCredentialsException::invalidPublicKey();
    }
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
   * Clears OpenSSL's process-level error queue.
   */
  private function clearOpenSslErrors(): void {
    while (openssl_error_string() !== FALSE) {
      // Intentionally discard details that may contain sensitive context.
    }
  }

}
