<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Credential;

use Drupal\commerce_novapay\Exception\InvalidCredentialsException;

/**
 * Validates private and public RSA keys independently.
 */
final class RsaKeyValidator implements RsaKeyValidatorInterface {

  /**
   * {@inheritdoc}
   */
  public function validatePrivateKey(
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
    if (
      $details === FALSE
      || $details['type'] !== OPENSSL_KEYTYPE_RSA
      || !is_string($details['key'] ?? NULL)
    ) {
      throw InvalidCredentialsException::invalidPrivateKey();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validatePublicKey(
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
    if (
      $details === FALSE
      || $details['type'] !== OPENSSL_KEYTYPE_RSA
      || !is_string($details['key'] ?? NULL)
    ) {
      throw InvalidCredentialsException::invalidPublicKey();
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
