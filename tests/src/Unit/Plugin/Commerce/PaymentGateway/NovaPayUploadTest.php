<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Plugin\Commerce\PaymentGateway;

use Drupal\Core\Form\FormState;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\commerce_novapay\Phone\CustomerProfilePhoneInspectorInterface;
use Drupal\commerce_novapay\Phone\CustomerProfilePhoneReadiness;
use Drupal\commerce_novapay\Plugin\Commerce\PaymentGateway\NovaPay;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests NovaPay key upload extraction compatibility.
 */
#[CoversClass(NovaPay::class)]
#[Group('commerce_novapay')]
final class NovaPayUploadTest extends TestCase {

  private const UPLOAD_KEY = 'novapay_private_key_upload';

  /**
   * The temporary uploaded file path.
   */
  private string $temporaryFile;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $temporary_file = tempnam(sys_get_temp_dir(), 'novapay-upload-');
    self::assertIsString($temporary_file);
    self::assertNotFalse(file_put_contents($temporary_file, 'test-key'));
    $this->temporaryFile = $temporary_file;
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_file($this->temporaryFile)) {
      unlink($this->temporaryFile);
    }

    parent::tearDown();
  }

  /**
   * Tests fallback when Core casts a single UploadedFile to scalar values.
   */
  public function testExtractsSingleUploadFromRequestFallback(): void {
    $upload = new UploadedFile(
      $this->temporaryFile,
      'private-key.pem',
      'text/plain',
      UPLOAD_ERR_OK,
      TRUE,
    );
    $request = new Request(
      files: ['files' => [self::UPLOAD_KEY => $upload]],
    );
    $request_stack = new RequestStack();
    $request_stack->push($request);

    $form_state = new FormState();
    $form_state->setValue(self::UPLOAD_KEY, (array) $upload);
    $plugin = new NovaPay([], 'novapay', []);
    $property = new \ReflectionProperty($plugin, 'requestStack');
    $property->setValue($plugin, $request_stack);
    $method = new \ReflectionMethod($plugin, 'getUploadedFile');

    self::assertSame(
      $upload,
      $method->invoke($plugin, $form_state, self::UPLOAD_KEY),
    );
  }

  /**
   * Tests that the request fallback ignores a different upload field.
   */
  public function testIgnoresUploadFromDifferentRequestField(): void {
    $upload = new UploadedFile(
      $this->temporaryFile,
      'unrelated-key.pem',
      'text/plain',
      UPLOAD_ERR_OK,
      TRUE,
    );
    $request = new Request(
      files: ['files' => ['unrelated_upload' => $upload]],
    );
    $request_stack = new RequestStack();
    $request_stack->push($request);

    $form_state = new FormState();
    $form_state->setValue(self::UPLOAD_KEY, (array) $upload);
    $plugin = new NovaPay([], 'novapay', []);
    $property = new \ReflectionProperty($plugin, 'requestStack');
    $property->setValue($plugin, $request_stack);
    $method = new \ReflectionMethod($plugin, 'getUploadedFile');

    self::assertNull(
      $method->invoke($plugin, $form_state, self::UPLOAD_KEY),
    );
  }

  /**
   * Tests the normal Form API array value path.
   */
  public function testExtractsUploadFromFormState(): void {
    $upload = new UploadedFile(
      $this->temporaryFile,
      'private-key.pem',
      'text/plain',
      UPLOAD_ERR_OK,
      TRUE,
    );
    $form_state = new FormState();
    $form_state->setValue(self::UPLOAD_KEY, [$upload]);
    $plugin = new NovaPay([], 'novapay', []);
    $property = new \ReflectionProperty($plugin, 'requestStack');
    $property->setValue($plugin, new RequestStack());
    $method = new \ReflectionMethod($plugin, 'getUploadedFile');

    self::assertSame(
      $upload,
      $method->invoke($plugin, $form_state, self::UPLOAD_KEY),
    );
  }

  /**
   * Tests actionable errors for missing and unmarked profile phone fields.
   */
  public function testCustomerProfilePhoneValidationMessages(): void {
    $plugin = $this->createPhoneValidationPlugin(
      new CustomerProfilePhoneReadiness(
        ['Customer'],
        ['Wholesale customer'],
      ),
    );
    $form = [
      'runtime_settings' => [
        '#parents' => ['configuration', 'novapay', 'runtime_settings'],
      ],
    ];
    $form_state = new FormState();
    $method = new \ReflectionMethod(
      $plugin,
      'validateCustomerProfilePhones',
    );
    $arguments = [&$form, $form_state];

    $method->invokeArgs($plugin, $arguments);

    $errors = $form_state->getErrors();
    self::assertCount(1, $errors);
    $message = (string) reset($errors);
    self::assertStringContainsString(
      'Add a Telephone field',
      $message,
    );
    self::assertStringContainsString('Customer', $message);
    self::assertStringContainsString(
      'Use this field as the NovaPay payment phone',
      $message,
    );
    self::assertStringContainsString('Wholesale customer', $message);
  }

  /**
   * Tests that ready customer profile types produce no form error.
   */
  public function testReadyCustomerProfilePhonesPassValidation(): void {
    $plugin = $this->createPhoneValidationPlugin(
      new CustomerProfilePhoneReadiness([], []),
    );
    $form = [
      'runtime_settings' => [
        '#parents' => ['configuration', 'novapay', 'runtime_settings'],
      ],
    ];
    $form_state = new FormState();
    $method = new \ReflectionMethod(
      $plugin,
      'validateCustomerProfilePhones',
    );
    $arguments = [&$form, $form_state];

    $method->invokeArgs($plugin, $arguments);

    self::assertSame([], $form_state->getErrors());
  }

  /**
   * Creates a plugin with phone readiness and string translation dependencies.
   */
  private function createPhoneValidationPlugin(
    CustomerProfilePhoneReadiness $readiness,
  ): NovaPay {
    $plugin = new NovaPay([], 'novapay', []);
    $inspector = $this->createMock(
      CustomerProfilePhoneInspectorInterface::class,
    );
    $inspector->method('inspect')->willReturn($readiness);
    $property = new \ReflectionProperty(
      $plugin,
      'customerProfilePhoneInspector',
    );
    $property->setValue($plugin, $inspector);
    $string_translation = $this->createMock(TranslationInterface::class);
    $string_translation->method('translateString')
      ->willReturnCallback(
        static fn (TranslatableMarkup $markup): string =>
          $markup->getUntranslatedString(),
      );
    $plugin->setStringTranslation($string_translation);

    return $plugin;
  }

}
