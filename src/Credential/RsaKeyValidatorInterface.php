<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Credential;

/**
 * Validates NovaPay RSA key material without exposing it.
 */
interface RsaKeyValidatorInterface {

  /**
   * Validates a merchant RSA private key.
   */
  public function validatePrivateKey(
    #[\SensitiveParameter]
    string $pem,
  ): void;

  /**
   * Validates a NovaPay RSA public key.
   */
  public function validatePublicKey(
    #[\SensitiveParameter]
    string $pem,
  ): void;

}
