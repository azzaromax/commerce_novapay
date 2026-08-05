<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Exception;

/**
 * Indicates that the verified NovaPay postback cannot be processed safely.
 */
final class PostbackProcessingException extends \RuntimeException {

  /**
   * Creates an exception when session event serialization fails.
   */
  public static function serializationFailed(): self {
    return new self('Unable to serialize postback processing.');
  }

  /**
   * Creates an exception for unavailable Commerce payment storage.
   */
  public static function paymentStorageUnavailable(): self {
    return new self('Commerce payment storage is unavailable.');
  }

  /**
   * Creates an exception for unavailable Commerce order storage.
   */
  public static function orderStorageUnavailable(): self {
    return new self('Commerce order storage is unavailable.');
  }

}
