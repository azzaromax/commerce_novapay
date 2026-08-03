<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Phone;

use Drupal\commerce_novapay\Phone\PhoneNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests NovaPay phone normalization.
 */
#[CoversClass(PhoneNormalizer::class)]
#[Group('commerce_novapay')]
final class PhoneNormalizerTest extends TestCase {

  /**
   * Tests accepted local and international formats.
   */
  #[DataProvider('validPhoneProvider')]
  public function testNormalizesPhone(string $input, string $expected): void {
    self::assertSame($expected, (new PhoneNormalizer())->normalize($input));
  }

  /**
   * Provides supported phone representations.
   *
   * @return iterable<string, array{string, string}>
   *   Input and expected E.164 value.
   */
  public static function validPhoneProvider(): iterable {
    yield 'Ukrainian local' => ['050 123 45 67', '+380501234567'];
    yield 'Ukrainian international' => [
      '+380 (50) 123-45-67',
      '+380501234567',
    ];
    yield 'Ukrainian digits' => ['380501234567', '+380501234567'];
    yield 'international prefix' => ['00442079460000', '+442079460000'];
    yield 'international E.164' => ['+12025550123', '+12025550123'];
  }

  /**
   * Tests values that must fail closed.
   */
  #[DataProvider('invalidPhoneProvider')]
  public function testRejectsInvalidPhone(string $phone): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid phone number.');
    (new PhoneNormalizer())->normalize($phone);
  }

  /**
   * Provides invalid phone values without embedding personal data.
   *
   * @return iterable<string, array{string}>
   *   Invalid values.
   */
  public static function invalidPhoneProvider(): iterable {
    yield 'empty' => [''];
    yield 'letters' => ['phone'];
    yield 'too short' => ['+1234567'];
    yield 'too long' => ['+1234567890123456'];
    yield 'invalid plus' => ['++380501234567'];
    yield 'extension' => ['+380501234567 ext 1'];
  }

}
