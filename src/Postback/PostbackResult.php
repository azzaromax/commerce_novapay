<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Postback;

/**
 * Carries bounded non-sensitive postback processing diagnostics.
 */
final class PostbackResult {

  /**
   * Constructs a bounded postback result.
   */
  private function __construct(
    private readonly PostbackOutcome $outcome,
    private readonly ?PostbackVersion $version = NULL,
    private readonly ?NovaPayStatus $status = NULL,
  ) {}

  /**
   * Creates an invalid-signature result without parsed payload details.
   */
  public static function invalidSignature(): self {
    return new self(PostbackOutcome::InvalidSignature);
  }

  /**
   * Creates an invalid-payload result without retaining the raw body.
   */
  public static function invalidPayload(): self {
    return new self(PostbackOutcome::InvalidPayload);
  }

  /**
   * Creates a result for a successfully normalized event.
   */
  public static function forEvent(
    PostbackOutcome $outcome,
    PostbackVersion $version,
    NovaPayStatus $status,
  ): self {
    return new self($outcome, $version, $status);
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

}
