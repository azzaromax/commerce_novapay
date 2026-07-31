<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Signature;

use Drupal\commerce_novapay\Credential\RsaKeyValidatorInterface;
use Drupal\commerce_novapay\Exception\SignatureException;

/**
 * Signs exact request body bytes using RSA SHA-256.
 */
final class RsaSha256Signer implements SignerInterface {

  /**
   * Constructs the NovaPay signer.
   */
  public function __construct(
    private readonly RsaKeyValidatorInterface $keyValidator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function sign(
    #[\SensitiveParameter]
    string $raw_body,
    #[\SensitiveParameter]
    string $private_key_pem,
  ): string {
    try {
      $this->keyValidator->validatePrivateKey($private_key_pem);
    }
    catch (\RuntimeException) {
      throw SignatureException::signingFailed();
    }

    $signature = '';
    $signed = @openssl_sign(
      $raw_body,
      $signature,
      $private_key_pem,
      OPENSSL_ALGO_SHA256,
    );
    $this->clearOpenSslErrors();

    if (!$signed || $signature === '') {
      throw SignatureException::signingFailed();
    }

    return base64_encode($signature);
  }

  /**
   * Clears OpenSSL's process-level error queue without exposing details.
   */
  private function clearOpenSslErrors(): void {
    while (openssl_error_string() !== FALSE) {
      // Intentionally discard details that may contain sensitive context.
    }
  }

}
