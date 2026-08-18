<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Postback;

/**
 * Carries bounded non-sensitive postback processing diagnostics.
 */
final class PostbackResult {

  /**
   * Constructs a bounded postback result.
   *
   * @param \Drupal\commerce_novapay\Postback\PostbackOutcome $outcome
   *   The processing outcome.
   * @param \Drupal\commerce_novapay\Postback\PostbackVersion|null $version
   *   The detected schema version.
   * @param \Drupal\commerce_novapay\Postback\NovaPayStatus|null $status
   *   The normalized NovaPay status.
   * @param array<string, string> $diagnostics
   *   Fixed-value diagnostic fields safe for logging.
   * @param bool $detailed_logging
   *   Whether sanitized informational logging is enabled for this gateway.
   */
  private function __construct(
    private readonly PostbackOutcome $outcome,
    private readonly ?PostbackVersion $version = NULL,
    private readonly ?NovaPayStatus $status = NULL,
    private readonly array $diagnostics = [],
    private readonly bool $detailed_logging = FALSE,
  ) {}

  /**
   * Creates an invalid-signature result without parsed payload details.
   */
  public static function invalidSignature(bool $detailed_logging = FALSE): self {
    return new self(
      PostbackOutcome::InvalidSignature,
      detailed_logging: $detailed_logging,
    );
  }

  /**
   * Creates an invalid-payload result without retaining the raw body.
   */
  public static function invalidPayload(bool $detailed_logging = FALSE): self {
    return new self(
      PostbackOutcome::InvalidPayload,
      detailed_logging: $detailed_logging,
    );
  }

  /**
   * Creates a result for a successfully normalized event.
   *
   * @param \Drupal\commerce_novapay\Postback\PostbackOutcome $outcome
   *   The processing outcome.
   * @param \Drupal\commerce_novapay\Postback\PostbackVersion $version
   *   The detected schema version.
   * @param \Drupal\commerce_novapay\Postback\NovaPayStatus $status
   *   The normalized NovaPay status.
   * @param array<string, string> $diagnostics
   *   Fixed-value diagnostic fields safe for logging.
   * @param bool $detailed_logging
   *   Whether sanitized informational logging is enabled for this gateway.
   */
  public static function forEvent(
    PostbackOutcome $outcome,
    PostbackVersion $version,
    NovaPayStatus $status,
    array $diagnostics = [],
    bool $detailed_logging = FALSE,
  ): self {
    return new self(
      $outcome,
      $version,
      $status,
      $diagnostics,
      $detailed_logging,
    );
  }

  /**
   * Gets the processing outcome.
   */
  public function getOutcome(): PostbackOutcome {
    return $this->outcome;
  }

  /**
   * Gets the detected schema version when parsing succeeded.
   */
  public function getVersion(): ?PostbackVersion {
    return $this->version;
  }

  /**
   * Gets the normalized NovaPay status when parsing succeeded.
   */
  public function getStatus(): ?NovaPayStatus {
    return $this->status;
  }

  /**
   * Gets optional bounded diagnostics for sanitized detailed logging.
   *
   * @return array<string, string>
   *   Fixed-value diagnostic fields only; no request identifiers or payload.
   */
  public function getDiagnostics(): array {
    return $this->diagnostics;
  }

  /**
   * Returns whether sanitized informational logging is enabled.
   */
  public function isDetailedLoggingEnabled(): bool {
    return $this->detailed_logging;
  }

}
