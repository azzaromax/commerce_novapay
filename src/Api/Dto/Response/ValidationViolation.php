<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Api\Dto\Response;

/**
 * Contains non-sensitive fields from one NovaPay validation error.
 */
final class ValidationViolation {

  /**
   * Constructs a validation violation.
   */
  public function __construct(
    private readonly ?string $code,
    private readonly ?string $path,
  ) {}

  /**
   * Gets the NovaPay validation code or JSON Schema keyword.
   */
  public function getCode(): ?string {
    return $this->code;
  }

  /**
   * Gets the invalid request field path, when supplied.
   */
  public function getPath(): ?string {
    return $this->path;
  }

}
