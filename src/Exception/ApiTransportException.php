<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Exception;

/**
 * Indicates that the HTTP exchange did not produce a response.
 */
final class ApiTransportException extends NovaPayApiException {

  /**
   * Creates a transport exception with no retained Guzzle request context.
   */
  public static function requestFailed(): self {
    return new self('The NovaPay API request failed before a response was received.');
  }

}
