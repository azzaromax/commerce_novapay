<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Logging;

use Drupal\commerce_novapay\Logging\LogSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests recursive payment and personal-data log sanitization.
 */
#[CoversClass(LogSanitizer::class)]
#[Group('commerce_novapay')]
final class LogSanitizerTest extends TestCase {

  /**
   * Tests recursive key redaction, PAN masking, and safe scalar retention.
   */
  public function testSanitizesNestedPayload(): void {
    $sanitizer = new LogSanitizer();

    $result = $sanitizer->sanitize([
      'session_id' => 'session-uuid',
      'pan' => '4134171111111001',
      'nested' => [
        'client_phone' => '+380501234567',
        'customer_email' => 'customer@example.com',
        'client_ip' => '192.0.2.10',
        'document_number' => 'AB123456',
        'x-sign' => 'base64-signature',
        'public_key_pem' => "-----BEGIN PUBLIC KEY-----\nsecret\n-----END PUBLIC KEY-----",
        'message' => 'card 4111 1111 1111 1111',
      ],
    ]);

    self::assertSame('session-uuid', $result['session_id']);
    self::assertSame('413417******1001', $result['pan']);
    self::assertSame(LogSanitizer::REDACTED, $result['nested']['client_phone']);
    self::assertSame(LogSanitizer::REDACTED, $result['nested']['customer_email']);
    self::assertSame(LogSanitizer::REDACTED, $result['nested']['client_ip']);
    self::assertSame(LogSanitizer::REDACTED, $result['nested']['document_number']);
    self::assertSame(LogSanitizer::REDACTED, $result['nested']['x-sign']);
    self::assertSame(LogSanitizer::REDACTED, $result['nested']['public_key_pem']);
    self::assertSame('card 411111******1111', $result['nested']['message']);
  }

  /**
   * Tests free-form PEM, email, and international phone removal.
   */
  public function testSanitizesSensitiveFreeFormText(): void {
    $sanitizer = new LogSanitizer();
    $value = 'Contact customer@example.com or +380 (50) 123-45-67. '
      . "-----BEGIN PRIVATE KEY-----\nsecret\n-----END PRIVATE KEY-----";

    $result = $sanitizer->sanitize($value);

    self::assertStringNotContainsString('customer@example.com', $result);
    self::assertStringNotContainsString('380', $result);
    self::assertStringNotContainsString('PRIVATE KEY', $result);
  }

  /**
   * Tests malformed JSON is never copied into a log entry.
   */
  public function testRejectsMalformedJsonWithoutRawFallback(): void {
    $sanitizer = new LogSanitizer();

    self::assertSame(
      LogSanitizer::INVALID_JSON,
      $sanitizer->sanitizeJson('{"client_phone":"+380501234567"'),
    );
  }

  /**
   * Tests sensitive object keys, numeric PAN, and local phone fail closed.
   */
  public function testSanitizesKeysAndUnkeyedNumericValues(): void {
    $sanitizer = new LogSanitizer();
    $json = '{"+380501234567":"phone-key",'
      . '"4134171111111001":"pan-key",'
      . '"list":[4134171111111001],'
      . '"note":"0501234567"}';

    $result = $sanitizer->sanitizeJson($json);
    $encoded = json_encode($result, JSON_THROW_ON_ERROR);

    self::assertStringNotContainsString('+380501234567', $encoded);
    self::assertStringNotContainsString('4134171111111001', $encoded);
    self::assertStringNotContainsString('0501234567', $encoded);
    self::assertSame('phone-key', $result[LogSanitizer::REDACTED]);
    self::assertSame('pan-key', $result['413417******1001']);
    self::assertSame('413417******1001', $result['list'][0]);
    self::assertSame(LogSanitizer::REDACTED, $result['note']);
  }

}
