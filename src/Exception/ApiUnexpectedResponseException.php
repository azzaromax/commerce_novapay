<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Exception;

/**
 * Indicates an undocumented or malformed NovaPay API response.
 */
final class ApiUnexpectedResponseException extends NovaPayApiException {

  /**
   * Creates an exception for an invalid successful response.
   */
  public static function invalidSuccess(int $http_status): self {
    return new self('NovaPay returned an invalid success response.', $http_status);
  }

  /**
   * Creates an exception for an unrecognized error response.
   */
  public static function invalidError(int $http_status): self {
    return new self('NovaPay returned an unrecognized error response.', $http_status);
  }

}
