<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Exception;

/**
 * Indicates that NovaPay could not process a valid API request.
 */
final class ApiProcessingException extends NovaPayApiException {

  /**
   * Constructs a processing exception.
   */
  public function __construct(
    int $http_status,
    private readonly ?string $api_code,
  ) {
    parent::__construct('NovaPay could not process the request.', $http_status);
  }

  /**
   * Gets the bounded machine-readable NovaPay error code.
   */
  public function getApiCode(): ?string {
    return $this->api_code;
  }

}
