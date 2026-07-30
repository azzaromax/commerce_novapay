<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Exception;

/**
 * Indicates that NovaPay credentials cannot be used safely.
 */
final class InvalidCredentialsException extends \RuntimeException {

  /**
   * Creates an exception for an invalid gateway identifier.
   */
  public static function invalidGatewayIdentifier(): self {
    return new self('The payment gateway identifier is invalid.');
  }

  /**
   * Creates an exception for unavailable packaged sandbox credentials.
   */
  public static function sandboxCredentialsUnavailable(): self {
    return new self('NovaPay sandbox credentials are unavailable.');
  }

  /**
   * Creates an exception for an unavailable local live profile.
   */
  public static function liveProfileUnavailable(): self {
    return new self('NovaPay live settings are missing or unreadable.');
  }

  /**
   * Creates an exception for an invalid live merchant identifier.
   */
  public static function invalidMerchantId(): self {
    return new self('The NovaPay live merchant identifier is invalid.');
  }

  /**
   * Creates an exception for an unavailable private key.
   */
  public static function privateKeyUnavailable(): self {
    return new self('The NovaPay private key is missing or unreadable.');
  }

  /**
   * Creates an exception for an invalid private key.
   */
  public static function invalidPrivateKey(): self {
    return new self('The NovaPay private key is not a valid RSA private key.');
  }

  /**
   * Creates an exception for an unavailable public key.
   */
  public static function publicKeyUnavailable(): self {
    return new self('The NovaPay public key is missing or unreadable.');
  }

  /**
   * Creates an exception for an invalid public key.
   */
  public static function invalidPublicKey(): self {
    return new self('The NovaPay public key is not a valid RSA public key.');
  }

}
