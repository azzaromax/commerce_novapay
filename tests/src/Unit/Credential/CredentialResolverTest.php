<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Credential;

use Drupal\Core\Lock\NullLockBackend;
use Drupal\commerce_novapay\Credential\CredentialResolver;
use Drupal\commerce_novapay\Credential\Credentials;
use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Credential\RsaKeyValidator;
use Drupal\commerce_novapay\Credential\SandboxCredentialProvider;
use Drupal\commerce_novapay\Exception\InvalidCredentialsException;
use Drupal\commerce_novapay\Runtime\RuntimeProfile;
use Drupal\commerce_novapay\Runtime\TransactionMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests secure NovaPay credential resolution.
 */
#[CoversClass(CredentialResolver::class)]
#[CoversClass(Credentials::class)]
#[Group('commerce_novapay')]
final class CredentialResolverTest extends TestCase {

  private const GATEWAY_UUID = '8d442671-2f23-4e3e-a9b7-307f7fe3e40c';

  /**
   * The isolated filesystem root used by a test.
   */
  private string $temporaryDirectory;

  /**
   * The local profile directory used by a test.
   */
  private string $gatewayDirectory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->temporaryDirectory = sys_get_temp_dir()
      . '/commerce_novapay-'
      . bin2hex(random_bytes(8));
    $this->gatewayDirectory = $this->temporaryDirectory
      . '/commerce_novapay/'
      . self::GATEWAY_UUID;

    self::assertTrue(
      mkdir($this->gatewayDirectory, 0700, TRUE),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach (['settings.json', 'private.pem', 'public.pem'] as $filename) {
      $path = $this->gatewayDirectory . '/' . $filename;
      if (is_file($path)) {
        unlink($path);
      }
    }

    rmdir($this->gatewayDirectory);
    rmdir(dirname($this->gatewayDirectory));
    rmdir($this->temporaryDirectory);

    parent::tearDown();
  }

  /**
   * Tests the fixed official NovaPay sandbox credential set.
   */
  public function testResolvesPackagedSandboxCredentials(): void {
    $this->writeProfile($this->createProfile(NovaPayMode::Test)->toArray());
    $resolver = $this->createResolver();
    $credentials = $resolver
      ->resolveRuntimeConfiguration(self::GATEWAY_UUID)
      ->getCredentials();

    self::assertSame(NovaPayMode::Test, $credentials->getMode());
    self::assertSame('2', $credentials->getMerchantId());
    self::assertNotFalse(
      openssl_pkey_get_private($credentials->getPrivateKeyPem()),
    );
    self::assertNotFalse(
      openssl_pkey_get_public($credentials->getPublicKeyPem()),
    );

    $private_key = openssl_pkey_get_private(
      $credentials->getPrivateKeyPem(),
    );
    self::assertNotFalse($private_key);
    $private_details = openssl_pkey_get_details($private_key);
    self::assertIsArray($private_details);
    self::assertNotSame(
      trim($private_details['key']),
      trim($credentials->getPublicKeyPem()),
      'Merchant and NovaPay keys must be accepted as unrelated keys.',
    );
  }

  /**
   * Tests live credentials stored outside exportable configuration.
   */
  public function testResolvesEnvironmentLocalLiveCredentials(): void {
    $private_key = $this->createPrivateKey();
    $public_key = $this->createPublicKey();
    $this->writeLiveFiles('merchant-uat', $private_key, $public_key);

    $credentials = $this->createResolver()
      ->resolveRuntimeConfiguration(self::GATEWAY_UUID)
      ->getCredentials();

    self::assertSame(NovaPayMode::Live, $credentials->getMode());
    self::assertSame('merchant-uat', $credentials->getMerchantId());
    self::assertSame($private_key, $credentials->getPrivateKeyPem());
    self::assertSame($public_key, $credentials->getPublicKeyPem());
  }

  /**
   * Tests that missing profiles do not expose filesystem details.
   */
  public function testMissingLiveProfileUsesSafeException(): void {
    try {
      $this->createResolver()
        ->resolveRuntimeConfiguration(self::GATEWAY_UUID);
      self::fail('A missing live profile must be rejected.');
    }
    catch (InvalidCredentialsException $exception) {
      self::assertSame(
        'NovaPay live settings are missing or unreadable.',
        $exception->getMessage(),
      );
      self::assertStringNotContainsString(
        self::GATEWAY_UUID,
        $exception->getMessage(),
      );
      self::assertStringNotContainsString(
        $this->temporaryDirectory,
        $exception->getMessage(),
      );
    }
  }

  /**
   * Tests that callers cannot override the environment stored in the profile.
   */
  public function testStoredProfileSelectsCredentialsAndEndpoint(): void {
    $this->writeProfile($this->createProfile(NovaPayMode::Test)->toArray());
    $configuration = $this->createResolver()
      ->resolveRuntimeConfiguration(self::GATEWAY_UUID);

    self::assertSame(
      NovaPayMode::Test,
      $configuration->getCredentials()->getMode(),
    );
    self::assertSame(
      'https://api-qecom.novapay.ua',
      $configuration->getApiBaseUrl(),
    );
  }

  /**
   * Tests independent validation of the merchant private key.
   */
  public function testInvalidPrivateKeyIsRejectedWithoutExposure(): void {
    $private_key = 'private-secret-value';
    $this->writeLiveFiles(
      'merchant-live',
      $private_key,
      $this->createPublicKey(),
    );

    try {
      $this->createResolver()
        ->resolveRuntimeConfiguration(self::GATEWAY_UUID);
      self::fail('An invalid private key must be rejected.');
    }
    catch (InvalidCredentialsException $exception) {
      self::assertSame(
        'The NovaPay private key is not a valid RSA private key.',
        $exception->getMessage(),
      );
      self::assertStringNotContainsString(
        $private_key,
        $exception->getMessage(),
      );
    }
  }

  /**
   * Tests independent validation of the NovaPay public key.
   */
  public function testInvalidPublicKeyIsRejectedWithoutExposure(): void {
    $public_key = 'public-secret-value';
    $this->writeLiveFiles(
      'merchant-live',
      $this->createPrivateKey(),
      $public_key,
    );

    try {
      $this->createResolver()
        ->resolveRuntimeConfiguration(self::GATEWAY_UUID);
      self::fail('An invalid public key must be rejected.');
    }
    catch (InvalidCredentialsException $exception) {
      self::assertSame(
        'The NovaPay public key is not a valid RSA public key.',
        $exception->getMessage(),
      );
      self::assertStringNotContainsString(
        $public_key,
        $exception->getMessage(),
      );
    }
  }

  /**
   * Tests traversal-safe gateway identifier validation.
   */
  public function testInvalidGatewayIdentifierIsRejected(): void {
    $this->expectException(InvalidCredentialsException::class);
    $this->expectExceptionMessage(
      'The payment gateway identifier is invalid.',
    );
    $this->createResolver()->resolveRuntimeConfiguration('../production');
  }

  /**
   * Tests that debug and serialization cannot expose key material.
   */
  public function testCredentialObjectProtectsKeyMaterial(): void {
    $this->writeProfile($this->createProfile(NovaPayMode::Test)->toArray());
    $credentials = $this->createResolver()
      ->resolveRuntimeConfiguration(self::GATEWAY_UUID)
      ->getCredentials();
    $debug = $credentials->__debugInfo();

    self::assertSame('[redacted]', $debug['private_key_pem']);
    self::assertSame('[redacted]', $debug['public_key_pem']);

    $this->expectException(\LogicException::class);
    serialize($credentials);
  }

  /**
   * Creates a resolver backed by the test's temporary private directory.
   */
  private function createResolver(): CredentialResolver {
    $validator = new RsaKeyValidator();

    return new CredentialResolver(
      $validator,
      new SandboxCredentialProvider($validator),
      new NullLockBackend(),
      $this->temporaryDirectory . '/commerce_novapay',
    );
  }

  /**
   * Writes a complete local live credential set.
   */
  private function writeLiveFiles(
    string $merchant_id,
    #[\SensitiveParameter]
    string $private_key,
    #[\SensitiveParameter]
    string $public_key,
  ): void {
    $this->writeProfile(
      $this->createProfile(NovaPayMode::Live, $merchant_id)->toArray(),
    );
    file_put_contents(
      $this->gatewayDirectory . '/private.pem',
      $private_key,
    );
    file_put_contents(
      $this->gatewayDirectory . '/public.pem',
      $public_key,
    );
  }

  /**
   * Writes a local runtime profile.
   *
   * @param array<string, bool|int|string> $profile
   *   The profile values.
   */
  private function writeProfile(array $profile): void {
    file_put_contents(
      $this->gatewayDirectory . '/settings.json',
      json_encode($profile, JSON_THROW_ON_ERROR),
    );
  }

  /**
   * Creates a complete strict runtime profile.
   */
  private function createProfile(
    NovaPayMode $mode,
    ?string $merchant_id = NULL,
  ): RuntimeProfile {
    return new RuntimeProfile(
      $mode,
      $mode === NovaPayMode::Live ? $merchant_id : NULL,
      TransactionMode::Direct,
      '',
      FALSE,
    );
  }

  /**
   * Generates an ephemeral RSA private key.
   */
  private function createPrivateKey(): string {
    $key = openssl_pkey_new([
      'private_key_bits' => 2048,
      'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    self::assertNotFalse($key);

    $exported = openssl_pkey_export($key, $private_key);
    self::assertTrue($exported);
    self::assertIsString($private_key);

    return $private_key;
  }

  /**
   * Generates an unrelated ephemeral RSA public key.
   */
  private function createPublicKey(): string {
    $key = openssl_pkey_new([
      'private_key_bits' => 2048,
      'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    self::assertNotFalse($key);

    $details = openssl_pkey_get_details($key);
    self::assertIsArray($details);
    self::assertIsString($details['key']);

    return $details['key'];
  }

}
