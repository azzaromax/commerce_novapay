<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Signature;

use Drupal\commerce_novapay\Credential\RsaKeyValidator;
use Drupal\commerce_novapay\Signature\RsaSha1SandboxVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the legacy verifier required by real NovaPay sandbox postbacks.
 */
#[CoversClass(RsaSha1SandboxVerifier::class)]
#[Group('commerce_novapay')]
final class RsaSha1SandboxVerifierTest extends TestCase {

  /**
   * Tests exact-byte RSA SHA-1 verification with no algorithm fallback.
   */
  public function testVerifiesOnlyMatchingLegacySignature(): void {
    $private_key = require dirname(__DIR__, 4)
      . '/resources/test/private-key.php';
    self::assertIsString($private_key);
    $key = openssl_pkey_get_private($private_key);
    self::assertNotFalse($key);
    $details = openssl_pkey_get_details($key);
    self::assertIsArray($details);
    $public_key = $details['key'] ?? NULL;
    self::assertIsString($public_key);

    $raw_body = '{"id":"sandbox-session","status":"paid"}';
    $signed = openssl_sign(
      $raw_body,
      $signature,
      $private_key,
      OPENSSL_ALGO_SHA1,
    );
    self::assertTrue($signed);
    $encoded_signature = base64_encode($signature);
    $verifier = new RsaSha1SandboxVerifier(new RsaKeyValidator());

    self::assertTrue($verifier->verify(
      $raw_body,
      $encoded_signature,
      $public_key,
    ));
    self::assertFalse($verifier->verify(
      $raw_body . ' ',
      $encoded_signature,
      $public_key,
    ));
  }

}
