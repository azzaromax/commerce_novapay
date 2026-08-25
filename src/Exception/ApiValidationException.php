<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Exception;

/**
 * Indicates that NovaPay rejected request validation.
 */
final class ApiValidationException extends NovaPayApiException {

  /**
   * Constructs a validation exception.
   *
   * @param int $http_status
   *   NovaPay HTTP response status.
   * @param list<\Drupal\commerce_novapay\Api\Dto\Response\ValidationViolation> $violations
   *   Non-sensitive validation details.
   * @param string|null $request_uuid
   *   Validated NovaPay request UUID for support escalation.
   */
  public function __construct(
    int $http_status,
    private readonly array $violations,
    private readonly ?string $request_uuid = NULL,
  ) {
    parent::__construct('NovaPay rejected the request data.', $http_status);
  }

  /**
   * Gets non-sensitive validation details.
   *
   * @return list<\Drupal\commerce_novapay\Api\Dto\Response\ValidationViolation>
   *   The validation details.
   */
  public function getViolations(): array {
    return $this->violations;
  }

  /**
   * Gets the safe NovaPay request UUID for support escalation.
   */
  public function getRequestUuid(): ?string {
    return $this->request_uuid;
  }

}
