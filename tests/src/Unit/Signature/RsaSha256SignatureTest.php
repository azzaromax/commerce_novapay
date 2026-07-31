<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Signature;

use Drupal\commerce_novapay\Credential\RsaKeyValidator;
use Drupal\commerce_novapay\Exception\SignatureException;
use Drupal\commerce_novapay\Signature\RsaSha256Signer;
use Drupal\commerce_novapay\Signature\RsaSha256Verifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests exact-byte RSA SHA-256 signing and verification.
 */
#[CoversClass(RsaSha256Signer::class)]
#[CoversClass(RsaSha256Verifier::class)]
#[CoversClass(SignatureException::class)]
#[Group('commerce_novapay')]
final class RsaSha256SignatureTest extends TestCase {

  /**
   * The signer under test.
   */
  private RsaSha256Signer $signer;

  /**
   * The verifier under test.
   */
  private RsaSha256Verifier $verifier;

  /**
   * The public key matching the packaged test-only private fixture.
   */
  private string $publicKey;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $validator = new RsaKeyValidator();
    $this->signer = new RsaSha256Signer($validator);
    $this->verifier = new RsaSha256Verifier($validator);

    $private_key = $this->getPrivateKey();
    $key = openssl_pkey_get_private($private_key);
    self::assertNotFalse($key);
    $details = openssl_pkey_get_details($key);
    self::assertIsArray($details);
    self::assertIsString($details['key'] ?? NULL);
    $this->publicKey = $details['key'];
  }

  /**
   * Tests a stable signature fixture over the exact raw JSON bytes.
   */
  public function testSignsAndVerifiesExactRawBodyFixture(): void {
    $raw_body = $this->getFixture('raw-body.json');
    $expected_signature = trim($this->getFixture('signature.base64'));

    self::assertSame(
      $expected_signature,
      $this->signer->sign($raw_body, $this->getPrivateKey()),
    );
    self::assertTrue(
      $this->verifier->verify(
        $raw_body,
        $expected_signature,
        $this->publicKey,
      ),
    );
  }

  /**
   * Tests that changing one body byte invalidates the signature.
   */
  public function testRejectsOneByteBodyChange(): void {
    $raw_body = $this->getFixture('raw-body.json');
    $signature = trim($this->getFixture('signature.base64'));
    $tampered_body = substr_replace($raw_body, '3', 15, 1);

    self::assertNotSame($raw_body, $tampered_body);
    self::assertFalse(
      $this->verifier->verify(
        $tampered_body,
        $signature,
        $this->publicKey,
      ),
    );
  }

  /**
   * Tests fail-closed handling of non-canonical base64 signatures.
   */
  #[DataProvider('invalidSignatureProvider')]
  public function testRejectsInvalidBase64(string $signature): void {
    self::assertFalse(
      $this->verifier->verify(
        $this->getFixture('raw-body.json'),
        $signature,
        $this->publicKey,
      ),
    );
  }

  /**
   * Provides invalid or non-canonical x-sign header values.
   *
   * @return iterable<string, array{string}>
   *   Invalid signature values.
   */
  public static function invalidSignatureProvider(): iterable {
    yield 'empty' => [''];
    yield 'invalid alphabet' => ['not-valid-%%%'];
    yield 'whitespace' => ["YQ==\n"];
    yield 'missing padding' => ['YQ'];
    yield 'oversized' => [str_repeat('A', 16388)];
  }

  /**
   * Tests that an invalid private PEM produces only a safe exception.
   */
  public function testRejectsInvalidPrivatePemSafely(): void {
    try {
      $this->signer->sign('raw body', 'private-secret-value');
      self::fail('An invalid private PEM must not produce a signature.');
    }
    catch (SignatureException $exception) {
      self::assertSame(
        'The NovaPay request could not be signed.',
        $exception->getMessage(),
      );
      self::assertStringNotContainsString(
        'private-secret-value',
        $exception->getMessage(),
      );
    }
  }

  /**
   * Tests that an invalid public PEM produces only a safe exception.
   */
  public function testRejectsInvalidPublicPemSafely(): void {
    $signature = trim($this->getFixture('signature.base64'));

    try {
      $this->verifier->verify(
        $this->getFixture('raw-body.json'),
        $signature,
        'public-secret-value',
      );
      self::fail('An invalid public PEM must not verify a signature.');
    }
    catch (SignatureException $exception) {
      self::assertSame(
        'The NovaPay signature could not be verified.',
        $exception->getMessage(),
      );
      self::assertStringNotContainsString(
        'public-secret-value',
        $exception->getMessage(),
      );
    }
  }

  /**
   * Loads the existing public NovaPay sandbox private-key fixture.
   */
  private function getPrivateKey(): string {
    $private_key = require dirname(__DIR__, 4)
      . '/resources/test/private-key.php';
    self::assertIsString($private_key);

    return $private_key;
  }

  /**
   * Reads one stable signature test fixture as exact bytes.
   */
  private function getFixture(string $filename): string {
    $contents = file_get_contents(
      dirname(__DIR__, 4) . '/tests/fixtures/signature/' . $filename,
    );
    self::assertIsString($contents);

    return $contents;
  }

}
