<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Plugin\Commerce\PaymentGateway;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\commerce_novapay\Phone\CustomerProfilePhoneInspectorInterface;
use Drupal\commerce_novapay\Phone\CustomerProfilePhoneReadiness;
use Drupal\commerce_novapay\Plugin\Commerce\PaymentGateway\NovaPay;
use Drupal\commerce_novapay\Runtime\RuntimeProfile;
use Drupal\commerce_novapay\Runtime\RuntimeProfileStorageInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
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
   * Tests that the form explains how existing live keys are preserved.
   */
  public function testLiveKeyStatusExplainsKeyPreservation(): void {
    $plugin = new NovaPay([], 'novapay', []);
    $string_translation = $this->createMock(TranslationInterface::class);
    $string_translation->method('translateString')
      ->willReturnCallback(
        static fn (TranslatableMarkup $markup): string =>
          $markup->getUntranslatedString(),
      );
    $plugin->setStringTranslation($string_translation);
    $method = new \ReflectionMethod($plugin, 'buildLiveKeyStatus');

    $status = $method->invoke($plugin, TRUE);

    self::assertSame('Keys installed', (string) $status['#title']);
    self::assertSame(
      ['messages', 'messages--status'],
      $status['#wrapper_attributes']['class'],
    );
    self::assertStringContainsString(
      'Both live keys are installed',
      (string) $status['#markup'],
    );
    self::assertStringContainsString(
      'Leave both upload fields empty to keep them unchanged',
      (string) $status['#markup'],
    );
    self::assertStringContainsString(
      'Changing the Merchant ID does not replace the keys',
      (string) $status['#markup'],
    );
  }

  /**
   * Tests redirect-timeout form defaults, validation, and persistence.
   */
  public function testSuccessRedirectTimeoutConfiguration(): void {
    $storage = $this->createMock(RuntimeProfileStorageInterface::class);
    $storage->method('load')->willReturn(NULL);
    $storage->method('hasValidLiveKeys')->willReturn(FALSE);
    $storage->expects(self::exactly(2))->method('assertWritable')
      ->with('gateway-uuid');
    $storage->expects(self::once())->method('save')
      ->with(
        'gateway-uuid',
        self::callback(static fn (RuntimeProfile $profile): bool =>
          $profile->getSuccessRedirectTimeout() === 0),
        NULL,
        NULL,
      );
    $plugin = $this->createConfigurationPlugin($storage);
    $form = $plugin->buildConfigurationForm(
      ['#parents' => ['configuration', 'novapay']],
      $this->createConfigurationFormState(),
    );
    $form['runtime_settings']['#parents'] = [
      'configuration',
      'novapay',
      'runtime_settings',
    ];
    $form['runtime_settings']['runtime_mode']['#parents'] = [
      'configuration',
      'novapay',
      'runtime_settings',
      'runtime_mode',
    ];
    $form['runtime_settings']['transaction_mode']['#parents'] = [
      'configuration',
      'novapay',
      'runtime_settings',
      'transaction_mode',
    ];
    $form['runtime_settings']['success_redirect_timeout']['#parents'] = [
      'configuration',
      'novapay',
      'runtime_settings',
      'success_redirect_timeout',
    ];
    $element = $form['runtime_settings']['success_redirect_timeout'];

    self::assertSame(
      RuntimeProfile::DEFAULT_SUCCESS_REDIRECT_TIMEOUT,
      $element['#default_value'],
    );
    self::assertSame(0, $element['#min']);
    self::assertSame(
      RuntimeProfile::MAX_SUCCESS_REDIRECT_TIMEOUT,
      $element['#max'],
    );
    self::assertStringContainsString(
      'Use 0 to omit',
      (string) $element['#description'],
    );
    self::assertStringContainsString(
      'newly created NovaPay sessions',
      (string) $element['#description'],
    );

    $invalid_state = $this->createConfigurationFormState('1.5');
    $plugin->validateConfigurationForm($form, $invalid_state);
    self::assertNotSame([], $invalid_state->getErrors());

    $valid_state = $this->createConfigurationFormState('0');
    $plugin->validateConfigurationForm($form, $valid_state);
    self::assertSame([], $valid_state->getErrors());
    $plugin->submitConfigurationForm($form, $valid_state);
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

  /**
   * Creates a fully wired plugin for configuration-form unit coverage.
   */
  private function createConfigurationPlugin(
    RuntimeProfileStorageInterface $storage,
  ): NovaPay {
    $plugin = new NovaPay(
      [
        'display_label' => 'NovaPay',
        'mode' => 'n/a',
        'payment_method_types' => [],
        'collect_billing_information' => FALSE,
      ],
      'novapay',
      [
        'display_label' => 'NovaPay',
        'modes' => ['n/a' => 'Environment-local'],
        'requires_billing_information' => FALSE,
        'libraries' => [],
      ],
    );
    $string_translation = $this->createMock(TranslationInterface::class);
    $string_translation->method('translateString')
      ->willReturnCallback(
        static fn (TranslatableMarkup $markup): string =>
          $markup->getUntranslatedString(),
      );
    $plugin->setStringTranslation($string_translation);

    $gateway = $this->createMock(PaymentGatewayInterface::class);
    $gateway->method('uuid')->willReturn('gateway-uuid');
    $gateway->method('id')->willReturn(NULL);
    $parent_entity = new \ReflectionProperty($plugin, 'parentEntity');
    $parent_entity->setValue($plugin, $gateway);

    $storage_property = new \ReflectionProperty(
      $plugin,
      'runtimeProfileStorage',
    );
    $storage_property->setValue($plugin, $storage);
    $inspector = $this->createMock(
      CustomerProfilePhoneInspectorInterface::class,
    );
    $inspector->method('inspect')->willReturn(
      new CustomerProfilePhoneReadiness([], []),
    );
    $inspector_property = new \ReflectionProperty(
      $plugin,
      'customerProfilePhoneInspector',
    );
    $inspector_property->setValue($plugin, $inspector);

    return $plugin;
  }

  /**
   * Creates submitted test-mode configuration values.
   */
  private function createConfigurationFormState(
    ?string $timeout = NULL,
  ): FormState {
    $state = new FormState();
    $gateway = $this->createMock(PaymentGatewayInterface::class);
    $gateway->method('uuid')->willReturn('gateway-uuid');
    $gateway->method('id')->willReturn(NULL);
    $form_object = $this->createMock(EntityFormInterface::class);
    $form_object->method('getEntity')->willReturn($gateway);
    $state->setFormObject($form_object);
    if ($timeout !== NULL) {
      $state->setValue(
        ['configuration', 'novapay'],
        [
          'display_label' => 'NovaPay',
          'mode' => 'n/a',
          'payment_method_types' => [],
          'collect_billing_information' => FALSE,
          'runtime_settings' => [
            'runtime_mode' => 'test',
            'transaction_mode' => 'direct',
            'recipient_identifier' => '',
            'success_redirect_timeout' => $timeout,
            'logging_enabled' => FALSE,
            'live_credentials' => ['merchant_id' => ''],
          ],
        ],
      );
    }

    return $state;
  }

}
