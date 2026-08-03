<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Exception;

/**
 * Contains bounded non-sensitive diagnostics for checkout preparation.
 */
final class CheckoutPreparationException extends \RuntimeException {

  /**
   * Constructs a safe checkout preparation exception.
   */
  private function __construct(
    private readonly string $stage,
    private readonly string $source_class,
    private readonly ?int $http_status,
    private readonly ?string $api_detail,
  ) {
    parent::__construct(
      sprintf('NovaPay checkout preparation failed during %s.', $stage),
    );
  }

  /**
   * Creates diagnostics without retaining the source exception.
   */
  public static function fromThrowable(
    string $stage,
    \Throwable $source,
  ): self {
    $http_status = $source instanceof NovaPayApiException
      ? $source->getHttpStatus()
      : NULL;
    $api_detail = NULL;
    if ($source instanceof ApiProcessingException) {
      $api_detail = $source->getApiCode();
    }
    elseif ($source instanceof ApiValidationException) {
      $details = [];
      foreach ($source->getViolations() as $violation) {
        $path = $violation->getPath() ?? 'unknown_path';
        $code = $violation->getCode() ?? 'unknown_code';
        $details[] = $path . ':' . $code;
      }
      $api_detail = $details !== [] ? implode(',', $details) : NULL;
    }

    return new self(
      $stage,
      $source::class,
      $http_status,
      $api_detail,
    );
  }

  /**
   * Gets the checkout stage that failed.
   */
  public function getStage(): string {
    return $this->stage;
  }

  /**
   * Gets the source exception class without retaining the exception object.
   */
  public function getSourceClass(): string {
    return $this->source_class;
  }

  /**
   * Gets the NovaPay HTTP status when a response was received.
   */
  public function getHttpStatus(): ?int {
    return $this->http_status;
  }

  /**
   * Gets a bounded NovaPay code or validation path/code summary.
   */
  public function getApiDetail(): ?string {
    return $this->api_detail;
  }

}
