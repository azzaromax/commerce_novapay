<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Postback\Parser;

use Drupal\commerce_novapay\Exception\InvalidPostbackException;
use Drupal\commerce_novapay\Postback\NovaPayStatus;
use Drupal\commerce_novapay\Postback\Parser\PostbackParser;
use Drupal\commerce_novapay\Postback\Parser\V2PostbackParser;
use Drupal\commerce_novapay\Postback\PostbackVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests strict v2 parsing and event normalization.
 */
#[CoversClass(PostbackParser::class)]
#[CoversClass(V2PostbackParser::class)]
#[Group('commerce_novapay')]
final class PostbackParserTest extends TestCase {

  /**
   * The v2 parser under test.
   */
  private PostbackParser $parser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->parser = new PostbackParser(new V2PostbackParser());
  }

  /**
   * Tests that the documented v2 fixture is normalized.
   */
  public function testNormalizesV2Fixture(): void {
    $postback = $this->parser->parse($this->getFixture('v2-holded.json'));

    self::assertSame(PostbackVersion::V2, $postback->getVersion());
    self::assertSame('session-uuid', $postback->getEvent()->getSessionId());
    self::assertSame(NovaPayStatus::Holded, $postback->getEvent()->getStatus());
    self::assertSame(['ORDER-1001'], $postback->getEvent()->getExternalIds());
  }

  /**
   * Tests exact refund evidence normalization without float arithmetic.
   */
  public function testNormalizesExplicitRefundAmounts(): void {
    $postback = $this->parser->parse(
      '{"id":"session","status":"paid","payments":[{"external_id":"1","amount":"20","refunded_amount":"10.25"},{"external_id":"2","amount":"10","refunded_amount":5}]}',
    );

    self::assertSame('15.25', $postback->getEvent()->getRefundedAmount());
  }

  /**
   * Tests fail-closed handling of malformed and unsupported schemas.
   */
  #[DataProvider('invalidPayloadProvider')]
  public function testRejectsUnsupportedSchema(string $raw_body): void {
    $this->expectException(InvalidPostbackException::class);
    $this->parser->parse($raw_body);
  }

  /**
   * Provides malformed or undocumented payloads.
   *
   * @return iterable<string, array{string}>
   *   Invalid raw JSON bodies.
   */
  public static function invalidPayloadProvider(): iterable {
    yield 'malformed JSON' => ['{"id":'];
    yield 'JSON list' => ['[]'];
    yield 'unknown shape' => ['{"id":"session","status":"paid"}'];
    yield 'legacy flat shape' => [
      '{"id":"session","status":"paid","external_id":"1","amount":1}',
    ];
    yield 'unknown status' => [
      '{"id":"session","status":"new-v3-status","payments":[{"external_id":"1","amount":1}]}',
    ];
    yield 'empty v2 payments' => [
      '{"id":"session","status":"paid","payments":[]}',
    ];
  }

  /**
   * Reads an exact postback fixture.
   */
  private function getFixture(string $filename): string {
    $contents = file_get_contents(
      dirname(__DIR__, 5) . '/tests/fixtures/postback/' . $filename,
    );
    self::assertIsString($contents);

    return $contents;
  }

}
