<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Signature;

use Drupal\commerce_novapay\Credential\RsaKeyValidatorInterface;
use Drupal\commerce_novapay\Exception\SignatureException;

/**
 * Verifies exact response or postback body bytes using RSA SHA-256.
 */
final class RsaSha256Verifier implements VerifierInterface {

  private const MAX_SIGNATURE_BASE64_BYTES = 16384;

  /**
   * Constructs the NovaPay verifier.
   */
  public function __construct(
    private readonly RsaKeyValidatorInterface $keyValidator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function verify(
    #[\SensitiveParameter]
    string $raw_body,
    #[\SensitiveParameter]
    string $signature_base64,
    #[\SensitiveParameter]
    string $public_key_pem,
  ): bool {
    if (
      $signature_base64 === ''
      || strlen($signature_base64) > self::MAX_SIGNATURE_BASE64_BYTES
    ) {
      return FALSE;
    }

    $signature = base64_decode($signature_base64, TRUE);
    if (
      !is_string($signature)
      || $signature === ''
      || !hash_equals(base64_encode($signature), $signature_base64)
    ) {
      return FALSE;
    }

    try {
      $this->keyValidator->validatePublicKey($public_key_pem);
    }
    catch (\RuntimeException) {
      throw SignatureException::verificationFailed();
    }

    $verified = @openssl_verify(
      $raw_body,
      $signature,
      $public_key_pem,
      OPENSSL_ALGO_SHA256,
    );
    $this->clearOpenSslErrors();

    return match ($verified) {
      1 => TRUE,
      0 => FALSE,
      default => throw SignatureException::verificationFailed(),
    };
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
