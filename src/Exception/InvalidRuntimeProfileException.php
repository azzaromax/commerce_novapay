<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Exception;

/**
 * Indicates that local NovaPay runtime settings cannot be used safely.
 */
final class InvalidRuntimeProfileException extends \RuntimeException {

  /**
   * Creates an exception for an unavailable private filesystem.
   */
  public static function privateStorageUnavailable(): self {
    return new self('The Drupal private filesystem is unavailable.');
  }

  /**
   * Creates an exception for an unavailable local profile.
   */
  public static function profileUnavailable(): self {
    return new self('The local NovaPay settings are missing or unreadable.');
  }

  /**
   * Creates an exception for invalid local settings.
   */
  public static function invalidProfile(): self {
    return new self('The local NovaPay settings are invalid.');
  }

  /**
   * Creates an exception for an incomplete live key upload.
   */
  public static function incompleteKeyUpload(): self {
    return new self('Upload both NovaPay live key files together.');
  }

  /**
   * Creates an exception for a concurrent profile operation.
   */
  public static function lockUnavailable(): self {
    return new self('The local NovaPay settings are currently being updated.');
  }

  /**
   * Creates an exception for a failed atomic write.
   */
  public static function writeFailed(): self {
    return new self('The local NovaPay settings could not be saved.');
  }

}
