<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Credential;

/**
 * Contains resolved NovaPay credentials for one API environment.
 */
final class Credentials {

  /**
   * Constructs a credential value object.
   */
  public function __construct(
    private readonly NovaPayMode $mode,
    private readonly string $merchant_id,
    #[\SensitiveParameter]
    private readonly string $private_key_pem,
    #[\SensitiveParameter]
    private readonly string $public_key_pem,
  ) {}

  /**
   * Gets the selected API environment.
   */
  public function getMode(): NovaPayMode {
    return $this->mode;
  }

  /**
   * Gets the NovaPay merchant identifier.
   */
  public function getMerchantId(): string {
    return $this->merchant_id;
  }

  /**
   * Gets the merchant private key used to sign API requests.
   */
  public function getPrivateKeyPem(): string {
    return $this->private_key_pem;
  }

  /**
   * Gets the NovaPay public key used to verify responses and postbacks.
   */
  public function getPublicKeyPem(): string {
    return $this->public_key_pem;
  }

  /**
   * Prevents credentials from being serialized into caches or sessions.
   */
  public function __serialize(): array {
    throw new \LogicException('NovaPay credentials cannot be serialized.');
  }

  /**
   * Redacts key material from debug output.
   *
   * @return array<string, string>
   *   Safe diagnostic fields.
   */
  public function __debugInfo(): array {
    return [
      'mode' => $this->mode->value,
      'merchant_id' => $this->merchant_id,
      'private_key_pem' => '[redacted]',
      'public_key_pem' => '[redacted]',
    ];
  }

}
