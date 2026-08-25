<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Functional;

use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;
use Drupal\commerce_novapay\Runtime\RuntimeProfile;
use Drupal\commerce_novapay\Runtime\RuntimeProfileStorageInterface;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests environment-local NovaPay gateway administration in a browser.
 */
#[Group('commerce_novapay')]
final class GatewayConfigurationTest extends CommerceBrowserTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'commerce_checkout',
    'commerce_order',
    'commerce_payment',
    'commerce_novapay',
    'profile',
    'telephone',
  ];

  /**
   * The functional site's private directory.
   */
  private string $privateDirectory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->privateDirectory = $this->siteDirectory . '/private';
    if (!is_dir($this->privateDirectory)) {
      self::assertTrue(mkdir($this->privateDirectory, 0700, TRUE));
    }
    self::assertTrue(chmod($this->privateDirectory, 0700));
    $this->writeSettings([
      'settings' => [
        'file_private_path' => (object) [
          'value' => $this->privateDirectory,
          'required' => TRUE,
        ],
      ],
    ]);
    $this->rebuildContainer();
    $this->createDesignatedCustomerPhone();
  }

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions(): array {
    return array_merge(parent::getAdministratorPermissions(), [
      'administer commerce_payment_gateway',
    ]);
  }

  /**
   * Tests upload, failed one-file rotation, permissions, and secret isolation.
   */
  public function testLiveKeyUploadAndAtomicRotation(): void {
    $this->createTestGateway();
    $gateway = PaymentGateway::load('novapay_browser');
    self::assertInstanceOf(PaymentGateway::class, $gateway);
    $profile = $this->getRuntimeStorage()->load((string) $gateway->uuid());
    self::assertInstanceOf(RuntimeProfile::class, $profile);
    self::assertSame('test', $profile->getMode()->value);

    [$private_path, $public_path, $private_pem, $public_pem] =
      $this->createKeyPairFiles('initial');
    $this->drupalGet($gateway->toUrl('edit-form'));
    $this->submitForm([
      'configuration[novapay][runtime_settings][runtime_mode]' => 'live',
      'configuration[novapay][runtime_settings][live_credentials][merchant_id]' => 'merchant-browser',
      'files[novapay_private_key_upload]' => $private_path,
      'files[novapay_public_key_upload]' => $public_path,
    ], 'Save');
    $this->assertSession()->pageTextContains(
      'Saved the NovaPay browser payment gateway.',
    );

    $directory = $this->privateDirectory
      . '/commerce_novapay/' . $gateway->uuid();
    self::assertSame($private_pem, file_get_contents($directory . '/private.pem'));
    self::assertSame($public_pem, file_get_contents($directory . '/public.pem'));
    self::assertSame(0700, fileperms($directory) & 0777);
    foreach (['settings.json', 'private.pem', 'public.pem'] as $filename) {
      self::assertSame(0600, fileperms($directory . '/' . $filename) & 0777);
    }
    $this->assertSecretsAreEnvironmentLocal($private_pem, $public_pem);

    [$next_private_path, $next_public_path, $next_private, $next_public] =
      $this->createKeyPairFiles('rotated');
    $this->drupalGet($gateway->toUrl('edit-form'));
    $this->submitForm([
      'files[novapay_private_key_upload]' => $next_private_path,
    ], 'Save');
    $this->assertSession()->pageTextContains(
      'Upload both live key files together.',
    );
    self::assertSame($private_pem, file_get_contents($directory . '/private.pem'));
    self::assertSame($public_pem, file_get_contents($directory . '/public.pem'));

    $this->drupalGet($gateway->toUrl('edit-form'));
    $this->submitForm([
      'files[novapay_private_key_upload]' => $next_private_path,
      'files[novapay_public_key_upload]' => $next_public_path,
    ], 'Save');
    $this->assertSession()->pageTextContains(
      'Saved the NovaPay browser payment gateway.',
    );
    self::assertSame($next_private, file_get_contents($directory . '/private.pem'));
    self::assertSame($next_public, file_get_contents($directory . '/public.pem'));
    $this->assertSecretsAreEnvironmentLocal($next_private, $next_public);
  }

  /**
   * Tests that an unavailable private scheme blocks gateway persistence.
   */
  public function testMissingPrivateStorageFailsClosed(): void {
    $gateway = $this->createGatewayEntity();
    $this->writeSettings([
      'settings' => [
        'file_private_path' => (object) [
          'value' => '',
          'required' => TRUE,
        ],
      ],
    ]);
    $this->rebuildContainer();
    $this->drupalGet($gateway->toUrl('edit-form'));
    $this->submitForm($this->getRuntimeValues(), 'Save');

    $this->assertSession()->pageTextContains(
      'NovaPay local settings cannot be saved securely.',
    );
    self::assertNull($this->getRuntimeStorage()->load((string) $gateway->uuid()));
    self::assertDirectoryDoesNotExist($this->root . '/private:');
  }

  /**
   * Creates a test-mode gateway through the real administration form.
   */
  private function createTestGateway(): void {
    $gateway = $this->createGatewayEntity();
    $this->drupalGet($gateway->toUrl('edit-form'));
    $this->assertSession()->pageTextContains('Environment-local NovaPay settings');
    $this->assertSession()->pageTextContains('Keys not installed');
    $this->assertSession()->fieldExists('files[novapay_private_key_upload]');
    $this->assertSession()->fieldExists('files[novapay_public_key_upload]');
    $this->submitForm($this->getRuntimeValues(), 'Save');
    $this->assertSession()->pageTextContains(
      'Saved the NovaPay browser payment gateway.',
    );
  }

  /**
   * Gets valid test-mode values for the gateway add form.
   *
   * @return array<string, string>
   *   Browser form values.
   */
  private function getRuntimeValues(): array {
    return [
      'status' => '1',
      'configuration[novapay][display_logo]' => '1',
      'configuration[novapay][runtime_settings][runtime_mode]' => 'test',
      'configuration[novapay][runtime_settings][transaction_mode]' => 'direct',
      'configuration[novapay][runtime_settings][recipient_identifier]' => '',
      'configuration[novapay][runtime_settings][success_redirect_timeout]' => '0',
      'configuration[novapay][runtime_settings][logging_enabled]' => '0',
    ];
  }

  /**
   * Creates the gateway entity whose real plugin form is exercised.
   */
  private function createGatewayEntity(): PaymentGateway {
    $gateway = PaymentGateway::create([
      'id' => 'novapay_browser',
      'label' => 'NovaPay browser',
      'plugin' => 'novapay',
      'status' => TRUE,
    ]);
    $gateway->save();

    return $gateway;
  }

  /**
   * Adds the telephone field required by NovaPay gateway validation.
   */
  private function createDesignatedCustomerPhone(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_payment_phone',
      'entity_type' => 'profile',
      'type' => 'telephone',
    ])->save();
    $field = FieldConfig::create([
      'field_name' => 'field_payment_phone',
      'entity_type' => 'profile',
      'bundle' => 'customer',
      'label' => 'Payment phone',
    ]);
    $field->setThirdPartySetting('commerce_novapay', 'payment_phone', TRUE);
    $field->save();
  }

  /**
   * Creates an RSA pair and two uploadable PEM files.
   *
   * @return array{string, string, string, string}
   *   Private path, public path, private PEM, and public PEM.
   */
  private function createKeyPairFiles(string $prefix): array {
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    self::assertNotFalse($key);
    self::assertTrue(openssl_pkey_export($key, $private_pem));
    $details = openssl_pkey_get_details($key);
    self::assertIsArray($details);
    self::assertIsString($details['key'] ?? NULL);
    $public_pem = $details['key'];
    $private_path = tempnam('/tmp', 'novapay-' . $prefix . '-private-');
    $public_path = tempnam('/tmp', 'novapay-' . $prefix . '-public-');
    self::assertIsString($private_path);
    self::assertIsString($public_path);
    self::assertNotFalse(file_put_contents($private_path, $private_pem));
    self::assertNotFalse(file_put_contents($public_path, $public_pem));

    return [$private_path, $public_path, $private_pem, $public_pem];
  }

  /**
   * Asserts that key material is absent from config, State, and module tables.
   */
  private function assertSecretsAreEnvironmentLocal(
    string $private_pem,
    string $public_pem,
  ): void {
    $gateway_config = $this->container->get('config.storage')->read(
      'commerce_payment.commerce_payment_gateway.novapay_browser',
    );
    $state = $this->container->get('keyvalue')->get('state')->getAll();
    $database_rows = [
      $this->container->get('database')
        ->select('commerce_novapay_postback_event', 'event')
        ->fields('event')
        ->execute()
        ->fetchAll(),
      $this->container->get('database')
        ->select('commerce_novapay_refund_ledger', 'ledger')
        ->fields('ledger')
        ->execute()
        ->fetchAll(),
    ];
    foreach ([$gateway_config, $state, $database_rows] as $stored_values) {
      $serialized = serialize($stored_values);
      self::assertStringNotContainsString($private_pem, $serialized);
      self::assertStringNotContainsString($public_pem, $serialized);
      self::assertStringNotContainsString('BEGIN PRIVATE KEY', $serialized);
      self::assertStringNotContainsString('BEGIN PUBLIC KEY', $serialized);
    }
  }

  /**
   * Gets the real runtime storage service.
   */
  private function getRuntimeStorage(): RuntimeProfileStorageInterface {
    $storage = $this->container->get(
      'commerce_novapay.runtime_profile_storage',
    );
    self::assertInstanceOf(RuntimeProfileStorageInterface::class, $storage);

    return $storage;
  }

}
