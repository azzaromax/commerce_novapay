<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Api\Dto\Response;

/**
 * Represents a successful NovaPay session response.
 */
final class SessionResponse {

  /**
   * Constructs a session response.
   *
   * @param string $session_id
   *   NovaPay session identifier.
   */
  private function __construct(
    private readonly string $session_id,
  ) {}

  /**
   * Builds the DTO from documented response variants.
   *
   * @param array<string, mixed> $data
   *   The decoded response data.
   */
  public static function fromArray(array $data): self {
    $session_id = $data['id'] ?? $data['session_id'] ?? NULL;
    if (!is_string($session_id)) {
      $sessions = $data['sessions'] ?? NULL;
      $session_id = is_array($sessions) ? ($sessions['id'] ?? NULL) : NULL;
    }

    if (!is_string($session_id) || trim($session_id) === '') {
      throw new \InvalidArgumentException('Session ID is missing.');
    }

    return new self(trim($session_id));
  }

  /**
   * Gets the NovaPay session identifier.
   */
  public function getSessionId(): string {
    return $this->session_id;
  }

}
