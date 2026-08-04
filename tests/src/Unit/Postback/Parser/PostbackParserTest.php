<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Postback\Parser;

use Drupal\commerce_novapay\Exception\InvalidPostbackException;
use Drupal\commerce_novapay\Postback\NovaPayStatus;
use Drupal\commerce_novapay\Postback\Parser\PostbackParser;
use Drupal\commerce_novapay\Postback\Parser\V1PostbackParser;
use Drupal\commerce_novapay\Postback\Parser\V2PostbackParser;
use Drupal\commerce_novapay\Postback\PostbackVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests strict v1/v2 detection and shared event normalization.
 */
#[CoversClass(PostbackParser::class)]
#[CoversClass(V1PostbackParser::class)]
#[CoversClass(V2PostbackParser::class)]
#[Group('commerce_novapay')]
final class PostbackParserTest extends TestCase {

  /**
   * The version-detecting parser under test.
   */
  private PostbackParser $parser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->parser = new PostbackParser(
      new V1PostbackParser(),
      new V2PostbackParser(),
    );
  }

  /**
   * Tests that documented v1 and v2 fixtures normalize identically.
   */
  public function testNormalizesV1AndV2FixturesIdentically(): void {
    $v1 = $this->parser->parse($this->getFixture('v1-holded.json'));
    $v2 = $this->parser->parse($this->getFixture('v2-holded.json'));

    self::assertSame(PostbackVersion::V1, $v1->getVersion());
    self::assertSame(PostbackVersion::V2, $v2->getVersion());
    self::assertSame(
      $v1->getEvent()->getSessionId(),
      $v2->getEvent()->getSessionId(),
    );
    self::assertSame(NovaPayStatus::Holded, $v1->getEvent()->getStatus());
    self::assertSame(
      $v1->getEvent()->getStatus(),
      $v2->getEvent()->getStatus(),
    );
    self::assertSame(
      $v1->getEvent()->getExternalIds(),
      $v2->getEvent()->getExternalIds(),
    );
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
    yield 'unknown status' => [
      '{"id":"session","status":"new-v3-status","external_id":"1","amount":1}',
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
