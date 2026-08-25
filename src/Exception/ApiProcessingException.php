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
    private readonly ?string $request_uuid = NULL,
  ) {
    parent::__construct('NovaPay could not process the request.', $http_status);
  }

  /**
   * Gets the bounded machine-readable NovaPay error code.
   */
  public function getApiCode(): ?string {
    return $this->api_code;
  }

  /**
   * Gets the safe NovaPay request UUID for support escalation.
   */
  public function getRequestUuid(): ?string {
    return $this->request_uuid;
  }

}
