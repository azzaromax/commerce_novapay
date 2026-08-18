<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Logging;

use Drupal\commerce_novapay\Logging\LogSanitizer;
use Drupal\commerce_novapay\Logging\NovaPayLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests detailed-log gating and sanitized JSON output.
 */
#[CoversClass(NovaPayLogger::class)]
#[Group('commerce_novapay')]
final class NovaPayLoggerTest extends TestCase {

  /**
   * Tests informational payload logs are disabled by the runtime toggle.
   */
  public function testDetailedLoggingOffWritesNothing(): void {
    $channel = $this->createMock(LoggerInterface::class);
    $channel->expects(self::never())->method('info');
    $logger = new NovaPayLogger($channel, new LogSanitizer());

    $logger->logDetailed(FALSE, 'api_request', [
      'client_phone' => '+380501234567',
    ]);
    $logger->logDetailedJson(FALSE, 'postback', '{"pan":"4134171111111001"}');
  }

  /**
   * Tests enabled logs are valid JSON with no PAN, PII, PEM, or signature.
   */
  public function testDetailedLoggingSanitizesJsonRecursively(): void {
    $channel = $this->createMock(LoggerInterface::class);
    $channel->expects(self::once())->method('info')
      ->with(self::callback(static function (string $message): bool {
        $entry = json_decode($message, TRUE, 32, JSON_THROW_ON_ERROR);
        return $entry['event'] === 'postback'
          && $entry['context']['payload']['pan'] === '413417******1001'
          && $entry['context']['payload']['client_phone'] === '[REDACTED]'
          && $entry['context']['payload']['signature'] === '[REDACTED]'
          && !str_contains($message, '+380501234567')
          && !str_contains($message, 'raw-signature');
      }));
    $logger = new NovaPayLogger($channel, new LogSanitizer());

    $logger->logDetailedJson(
      TRUE,
      'postback',
      '{"pan":"4134171111111001","client_phone":"+380501234567","signature":"raw-signature"}',
    );
  }

  /**
   * Tests critical operational errors remain visible with the toggle off.
   */
  public function testErrorsAreAlwaysLoggedAndSanitized(): void {
    $channel = $this->createMock(LoggerInterface::class);
    $channel->expects(self::once())->method('error')
      ->with(self::callback(static function (string $message): bool {
        return str_contains($message, 'api_transport_error')
          && !str_contains($message, 'customer@example.com');
      }));
    $logger = new NovaPayLogger($channel, new LogSanitizer());

    $logger->logError('api_transport_error', [
      'detail' => 'Contact customer@example.com',
    ]);
  }

  /**
   * Tests the encoded log entry has a strict size ceiling.
   */
  public function testOversizedEntryIsReplacedWithFixedMetadata(): void {
    $channel = $this->createMock(LoggerInterface::class);
    $channel->expects(self::once())->method('info')
      ->with(self::callback(static function (string $message): bool {
        $entry = json_decode($message, TRUE, 4, JSON_THROW_ON_ERROR);
        return strlen($message) <= 32768
          && $entry['event'] === 'api_response'
          && $entry['context']['entry'] === LogSanitizer::OVERSIZED
          && $entry['context']['original_bytes'] > 32768;
      }));
    $logger = new NovaPayLogger($channel, new LogSanitizer());

    $logger->logDetailed(TRUE, 'api_response', [
      'items' => array_fill(0, 1000, str_repeat('A', 800)),
    ]);
  }

}
