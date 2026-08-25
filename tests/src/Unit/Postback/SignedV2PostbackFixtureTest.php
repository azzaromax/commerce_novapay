<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Postback;

use Drupal\commerce_novapay\Credential\RsaKeyValidator;
use Drupal\commerce_novapay\Postback\NovaPayStatus;
use Drupal\commerce_novapay\Postback\Parser\PostbackParser;
use Drupal\commerce_novapay\Postback\Parser\V1PostbackParser;
use Drupal\commerce_novapay\Postback\Parser\V2PostbackParser;
use Drupal\commerce_novapay\Postback\PostbackVersion;
use Drupal\commerce_novapay\Signature\RsaSha256Verifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Verifies and parses exact-byte signed NovaPay v2 fixtures.
 */
#[Group('commerce_novapay')]
final class SignedV2PostbackFixtureTest extends TestCase {

  /**
   * Tests the signature and normalized status of a v2 fixture.
   */
  #[DataProvider('fixtureProvider')]
  public function testSignedFixture(
    string $fixture,
    NovaPayStatus $expected_status,
  ): void {
    $raw_body = $this->readFixture($fixture . '.json');
    $signature = trim($this->readFixture($fixture . '.signature.base64'));
    $private_key = require dirname(__DIR__, 4)
      . '/resources/test/private-key.php';
    self::assertIsString($private_key);
    $key = openssl_pkey_get_private($private_key);
    self::assertNotFalse($key);
    $details = openssl_pkey_get_details($key);
    self::assertIsArray($details);
    self::assertIsString($details['key'] ?? NULL);

    $verifier = new RsaSha256Verifier(new RsaKeyValidator());
    self::assertTrue($verifier->verify(
      $raw_body,
      $signature,
      $details['key'],
    ));

    $parser = new PostbackParser(
      new V1PostbackParser(),
      new V2PostbackParser(),
    );
    $parsed = $parser->parse($raw_body);
    self::assertSame(PostbackVersion::V2, $parsed->getVersion());
    self::assertSame($expected_status, $parsed->getEvent()->getStatus());
  }

  /**
   * Provides signed direct, hold, capture, void, and refund evidence.
   *
   * @return iterable<string, array{string, \Drupal\commerce_novapay\Postback\NovaPayStatus}>
   *   Fixture names and normalized statuses.
   */
  public static function fixtureProvider(): iterable {
    yield 'direct paid' => ['v2-paid', NovaPayStatus::Paid];
    yield 'hold authorized' => ['v2-holded', NovaPayStatus::Holded];
    yield 'hold captured' => [
      'v2-hold-confirmed',
      NovaPayStatus::HoldConfirmed,
    ];
    yield 'voided' => ['v2-voided', NovaPayStatus::Voided];
    yield 'partial refund' => [
      'v2-partial-refund',
      NovaPayStatus::Paid,
    ];
  }

  /**
   * Reads an exact-byte postback fixture.
   */
  private function readFixture(string $filename): string {
    $contents = file_get_contents(
      dirname(__DIR__, 4) . '/tests/fixtures/postback/' . $filename,
    );
    self::assertIsString($contents);

    return $contents;
  }

}
