<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Exception;

/**
 * Thrown when a NovaPay signature operation cannot be completed safely.
 */
final class SignatureException extends \RuntimeException {

  /**
   * Creates a safe signing failure without OpenSSL details.
   */
  public static function signingFailed(): self {
    return new self('The NovaPay request could not be signed.');
  }

  /**
   * Creates a safe verification failure without key or OpenSSL details.
   */
  public static function verificationFailed(): self {
    return new self('The NovaPay signature could not be verified.');
  }

}
