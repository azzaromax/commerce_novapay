<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Logging;

/**
 * Recursively removes payment secrets and personal data from log values.
 */
final class LogSanitizer implements LogSanitizerInterface {

  public const REDACTED = '[REDACTED]';

  public const INVALID_JSON = '[INVALID JSON]';

  public const OVERSIZED = '[OVERSIZED]';

  private const MAX_DEPTH = 16;

  private const MAX_ITEMS = 1000;

  private const MAX_JSON_BYTES = 1048576;

  private const MAX_STRING_BYTES = 8192;

  /**
   * {@inheritdoc}
   */
  public function sanitize(mixed $value): mixed {
    return $this->sanitizeValue($value, NULL, 0);
  }

  /**
   * {@inheritdoc}
   */
  public function sanitizeJson(
    #[\SensitiveParameter]
    string $json,
  ): mixed {
    if (strlen($json) > self::MAX_JSON_BYTES) {
      return self::OVERSIZED;
    }
    if (trim($json) === '') {
      return NULL;
    }

    try {
      $value = json_decode($json, TRUE, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return self::INVALID_JSON;
    }

    return $this->sanitize($value);
  }

  /**
   * Sanitizes one value with its normalized parent key.
   */
  private function sanitizeValue(
    mixed $value,
    ?string $key,
    int $depth,
  ): mixed {
    if ($depth > self::MAX_DEPTH) {
      return self::REDACTED;
    }
    if ($key !== NULL && $this->isSecretKey($key)) {
      return self::REDACTED;
    }
    if ($key !== NULL && $this->isPersonallyIdentifyingKey($key)) {
      return self::REDACTED;
    }
    if ($key !== NULL && $this->isPanKey($key)) {
      return $this->maskPanValue($value);
    }

    if (is_array($value)) {
      $sanitized = [];
      $position = 0;
      $is_list = array_is_list($value);
      foreach ($value as $child_key => $child_value) {
        if ($position >= self::MAX_ITEMS) {
          $sanitized['__truncated__'] = TRUE;
          break;
        }
        $normalized_key = is_string($child_key)
          ? $this->normalizeKey($child_key)
          : NULL;
        $sanitized_key = $this->sanitizeArrayKey($child_key, $is_list);
        if (array_key_exists($sanitized_key, $sanitized)) {
          $sanitized_key = (string) $sanitized_key . '#' . $position;
        }
        $sanitized[$sanitized_key] = $this->sanitizeValue(
          $child_value,
          $normalized_key,
          $depth + 1,
        );
        $position++;
      }
      return $sanitized;
    }

    if (is_string($value)) {
      return $this->sanitizeString($value);
    }
    if (
      is_int($value)
      && preg_match('/^\d{12,19}$/D', (string) $value) === 1
    ) {
      return $this->maskDigits((string) $value);
    }
    if (is_float($value) && !is_finite($value)) {
      return self::REDACTED;
    }
    if (is_int($value) || is_float($value) || is_bool($value) || $value === NULL) {
      return $value;
    }

    return self::REDACTED;
  }

  /**
   * Normalizes structured field names for deny-list matching.
   */
  private function normalizeKey(string $key): string {
    $key = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key);
    return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($key)), '_');
  }

  /**
   * Sanitizes untrusted object keys while retaining ordinary list indexes.
   */
  private function sanitizeArrayKey(
    string|int $key,
    bool $is_list,
  ): string|int {
    if ($is_list && is_int($key)) {
      return $key;
    }

    return $this->sanitizeString((string) $key);
  }

  /**
   * Checks whether a field contains a secret or any PEM material.
   */
  private function isSecretKey(string $key): bool {
    return preg_match(
      '/(?:^|_)(?:authorization|cookie|password|passwd|secret|token|pem|private_key|privatekey|public_key|publickey|signature|sign|x_sign|xsign)(?:_|$)/D',
      $key,
    ) === 1;
  }

  /**
   * Checks whether a structured field contains customer PII.
   */
  private function isPersonallyIdentifyingKey(string $key): bool {
    return preg_match(
      '/(?:^|_)(?:phone|email|first_name|last_name|middle_name|full_name|customer_name|client_name|payer_name|recipient_name|patronymic|address|birth_date|ip|ip_address|recipient_identifier|document_number|document_series|document_issued_at|document_issued_by)(?:_|$)/D',
      $key,
    ) === 1;
  }

  /**
   * Checks whether a structured field explicitly contains a card number.
   */
  private function isPanKey(string $key): bool {
    return preg_match(
      '/(?:^|_)(?:pan|card_number|cardnumber|payment_card)(?:_|$)/D',
      $key,
    ) === 1;
  }

  /**
   * Masks a structured PAN while preserving BIN and last four digits.
   */
  private function maskPanValue(mixed $value): string {
    if (!is_string($value) && !is_int($value)) {
      return self::REDACTED;
    }

    $string_value = (string) $value;
    if (preg_match('/^\d{6}([*xX]+)\d{4}$/D', $string_value, $matches) === 1) {
      return substr($string_value, 0, 6)
        . str_repeat('*', strlen($matches[1]))
        . substr($string_value, -4);
    }

    $digits = preg_replace('/\D+/', '', $string_value);
    if (!is_string($digits) || strlen($digits) < 10) {
      return self::REDACTED;
    }

    return $this->maskDigits($digits);
  }

  /**
   * Removes PEM blocks and embedded email, phone, and PAN-like values.
   */
  private function sanitizeString(
    #[\SensitiveParameter]
    string $value,
  ): string {
    if (
      strlen($value) > self::MAX_STRING_BYTES
      || preg_match('//u', $value) !== 1
    ) {
      return self::REDACTED;
    }

    $value = (string) preg_replace(
      '/-----BEGIN [^-\r\n]+-----.*?-----END [^-\r\n]+-----/s',
      self::REDACTED,
      $value,
    );
    $value = (string) preg_replace(
      '/(?<![A-Z0-9._%+-])[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}(?![A-Z0-9.-])/i',
      self::REDACTED,
      $value,
    );
    $value = (string) preg_replace(
      '/(?<!\d)\+\d(?:[\s()-]*\d){9,14}(?!\d)/',
      self::REDACTED,
      $value,
    );
    $value = (string) preg_replace(
      '/(?<![\d+])(?:\d[\s().-]*){10,11}(?!\d)/',
      self::REDACTED,
      $value,
    );

    return (string) preg_replace_callback(
      '/(?<!\d)(?:\d[ -]?){12,19}(?!\d)/',
      function (array $matches): string {
        $digits = preg_replace('/\D+/', '', $matches[0]);
        if (!is_string($digits) || strlen($digits) < 12 || strlen($digits) > 19) {
          return $matches[0];
        }
        return $this->maskDigits($digits);
      },
      $value,
    );
  }

  /**
   * Applies NovaPay's required BIN/stars/last-four PAN format.
   */
  private function maskDigits(string $digits): string {
    return substr($digits, 0, 6)
      . str_repeat('*', max(1, strlen($digits) - 10))
      . substr($digits, -4);
  }

}
