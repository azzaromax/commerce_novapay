<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Runtime;

use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Exception\InvalidRuntimeProfileException;

/**
 * Contains environment-local NovaPay gateway settings.
 */
final class RuntimeProfile {

  public const VERSION = 1;

  /**
   * Constructs a validated runtime profile.
   */
  public function __construct(
    private readonly NovaPayMode $mode,
    private readonly ?string $merchant_id,
    private readonly TransactionMode $transaction_mode,
    private readonly string $recipient_identifier,
    private readonly bool $logging_enabled,
  ) {
    if (strlen($this->recipient_identifier) > 128) {
      throw InvalidRuntimeProfileException::invalidProfile();
    }

    if (
      $this->mode === NovaPayMode::Live
      && (
        $this->merchant_id === NULL
        || trim($this->merchant_id) === ''
        || strlen($this->merchant_id) > 128
      )
    ) {
      throw InvalidRuntimeProfileException::invalidProfile();
    }
  }

  /**
   * Builds a profile from decoded local JSON.
   *
   * @param array<string, mixed> $values
   *   Decoded profile values.
   */
  public static function fromArray(array $values): self {
    if (($values['version'] ?? NULL) !== self::VERSION) {
      throw InvalidRuntimeProfileException::invalidProfile();
    }

    $mode = is_string($values['mode'] ?? NULL)
      ? NovaPayMode::tryFrom($values['mode'])
      : NULL;
    $transaction_mode = is_string($values['transaction_mode'] ?? NULL)
      ? TransactionMode::tryFrom($values['transaction_mode'])
      : NULL;
    $merchant_id = $values['merchant_id'] ?? NULL;
    $recipient_identifier = $values['recipient_identifier'] ?? NULL;
    $logging_enabled = $values['logging_enabled'] ?? NULL;

    if (
      $mode === NULL
      || $transaction_mode === NULL
      || (!is_string($merchant_id) && $merchant_id !== NULL)
      || !is_string($recipient_identifier)
      || !is_bool($logging_enabled)
    ) {
      throw InvalidRuntimeProfileException::invalidProfile();
    }

    return new self(
      $mode,
      $mode === NovaPayMode::Live ? $merchant_id : NULL,
      $transaction_mode,
      trim($recipient_identifier),
      $logging_enabled,
    );
  }

  /**
   * Gets the NovaPay API environment.
   */
  public function getMode(): NovaPayMode {
    return $this->mode;
  }

  /**
   * Gets the live merchant ID, or NULL in test mode.
   */
  public function getMerchantId(): ?string {
    return $this->mode === NovaPayMode::Live
      ? trim((string) $this->merchant_id)
      : NULL;
  }

  /**
   * Gets the direct/hold transaction mode.
   */
  public function getTransactionMode(): TransactionMode {
    return $this->transaction_mode;
  }

  /**
   * Gets the payment recipient identifier.
   */
  public function getRecipientIdentifier(): string {
    return trim($this->recipient_identifier);
  }

  /**
   * Returns whether detailed sanitized logging is enabled.
   */
  public function isLoggingEnabled(): bool {
    return $this->logging_enabled;
  }

  /**
   * Converts the profile to its environment-local JSON representation.
   *
   * @return array<string, bool|int|string>
   *   Serializable non-key settings.
   */
  public function toArray(): array {
    $values = [
      'version' => self::VERSION,
      'mode' => $this->mode->value,
      'transaction_mode' => $this->transaction_mode->value,
      'recipient_identifier' => $this->getRecipientIdentifier(),
      'logging_enabled' => $this->logging_enabled,
    ];

    if ($this->mode === NovaPayMode::Live) {
      $values['merchant_id'] = $this->getMerchantId() ?? '';
    }

    return $values;
  }

}
