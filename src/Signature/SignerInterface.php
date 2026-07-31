<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Signature;

/**
 * Signs exact request body bytes for the NovaPay x-sign header.
 */
interface SignerInterface {

  /**
   * Returns the canonical base64-encoded RSA SHA-256 signature.
   */
  public function sign(
    #[\SensitiveParameter]
    string $raw_body,
    #[\SensitiveParameter]
    string $private_key_pem,
  ): string;

}
