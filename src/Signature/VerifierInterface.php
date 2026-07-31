<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Signature;

/**
 * Verifies NovaPay x-sign signatures against exact response body bytes.
 */
interface VerifierInterface {

  /**
   * Verifies one canonical base64-encoded RSA SHA-256 signature.
   */
  public function verify(
    #[\SensitiveParameter]
    string $raw_body,
    #[\SensitiveParameter]
    string $signature_base64,
    #[\SensitiveParameter]
    string $public_key_pem,
  ): bool;

}
