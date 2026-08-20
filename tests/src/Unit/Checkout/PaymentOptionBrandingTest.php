<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Checkout;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\commerce_novapay\Checkout\PaymentOptionBranding;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\PaymentOption;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\PaymentGatewayInterface as PaymentGatewayPluginInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests checkout payment-option branding.
 */
#[CoversClass(PaymentOptionBranding::class)]
#[Group('commerce_novapay')]
final class PaymentOptionBrandingTest extends TestCase {

  /**
   * Tests that only options backed by the NovaPay gateway are marked.
   */
  public function testMarksOnlyNovaPayPaymentOptions(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects(self::exactly(4))
      ->method('load')
      ->willReturnMap([
        ['novapay_primary', $this->createGateway('novapay')],
        ['novapay_secondary', $this->createGateway('novapay')],
        ['novapay_text', $this->createGateway('novapay', FALSE)],
        ['manual_gateway', $this->createGateway('manual')],
      ]);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')
      ->with('commerce_payment_gateway')
      ->willReturn($storage);

    $branding = $this->createBranding($entity_type_manager);
    $form = [
      'checkout' => [
        'payment_information' => [
          '#payment_options' => [
            'novapay_primary' => new PaymentOption([
              'id' => 'novapay_primary',
              'label' => 'NovaPay',
              'payment_gateway_id' => 'novapay_primary',
            ]),
            'novapay_secondary' => new PaymentOption([
              'id' => 'novapay_secondary',
              'label' => 'NovaPay installments',
              'payment_gateway_id' => 'novapay_secondary',
            ]),
            'manual_gateway' => new PaymentOption([
              'id' => 'manual_gateway',
              'label' => 'Manual',
              'payment_gateway_id' => 'manual_gateway',
            ]),
            'novapay_text' => new PaymentOption([
              'id' => 'novapay_text',
              'label' => 'NovaPay text label',
              'payment_gateway_id' => 'novapay_text',
            ]),
          ],
          'payment_method' => [
            '#type' => 'radios',
            'novapay_primary' => [
              '#attributes' => [
                'class' => ['payment-method--new'],
              ],
            ],
            'novapay_secondary' => [],
            'novapay_text' => [],
            'manual_gateway' => [],
          ],
        ],
      ],
    ];

    $branding->alter($form);
    $branding->alter($form);

    $payment_method = $form['checkout']['payment_information']['payment_method'];
    self::assertSame(
      ['payment-method--new', 'commerce-novapay-payment-option'],
      $payment_method['novapay_primary']['#attributes']['class'],
    );
    self::assertSame(
      'novapay',
      $payment_method['novapay_primary']['#attributes']['data-payment-gateway'],
    );
    self::assertSame(
      ['commerce-novapay-payment-option-wrapper'],
      $payment_method['novapay_primary']['#wrapper_attributes']['class'],
    );
    self::assertSame(
      [
        'uri' => '/store/modules/custom/commerce_novapay/assets/images/logo.svg',
        'alt' => 'NovaPay',
      ],
      $payment_method['novapay_primary']['#commerce_novapay_logo'],
    );
    self::assertSame(
      [
        'uri' => '/store/modules/custom/commerce_novapay/assets/images/logo.svg',
        'alt' => 'NovaPay installments',
      ],
      $payment_method['novapay_secondary']['#commerce_novapay_logo'],
    );
    self::assertSame([], $payment_method['novapay_text']);
    self::assertSame([], $payment_method['manual_gateway']);
  }

  /**
   * Tests that preprocessors replace only a marked radio label title.
   */
  public function testReplacesMarkedLabelTitleWithImage(): void {
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $branding = $this->createBranding($entity_type_manager);
    $marker = [
      'uri' => '/store/modules/custom/commerce_novapay/assets/images/logo.svg',
      'alt' => 'Pay with NovaPay',
    ];
    $form_element_variables = [
      'element' => ['#commerce_novapay_logo' => $marker],
      'label' => [
        '#theme' => 'form_element_label',
        '#title' => 'Pay with NovaPay',
      ],
    ];

    $branding->preprocessFormElement($form_element_variables);
    self::assertSame(
      $marker,
      $form_element_variables['label']['#commerce_novapay_logo'],
    );

    $label_variables = [
      'element' => $form_element_variables['label'],
      'title' => ['#markup' => 'Pay with NovaPay'],
    ];
    $branding->preprocessFormElementLabel($label_variables);

    self::assertSame(
      [
        '#theme' => 'image',
        '#uri' => '/store/modules/custom/commerce_novapay/assets/images/logo.svg',
        '#alt' => 'Pay with NovaPay',
        '#width' => 124,
        '#height' => 25,
        '#attributes' => [
          'class' => ['commerce-novapay-payment-option-logo'],
        ],
      ],
      $label_variables['title'],
    );
  }

  /**
   * Tests that review shows the logo for the selected NovaPay gateway.
   */
  public function testBrandsSelectedNovaPayGatewayOnReview(): void {
    $gateway = $this->createGateway('novapay');
    $gateway->method('id')->willReturn('novapay_primary');
    $gateway->method('getPlugin')->willReturn($this->createGatewayPlugin());
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects(self::once())
      ->method('load')
      ->with('novapay_primary')
      ->willReturn($gateway);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')
      ->with('commerce_payment_gateway')
      ->willReturn($storage);
    $branding = $this->createBranding($entity_type_manager);
    $form = $this->reviewForm('NovaPay checkout');

    $branding->alter($form, $gateway);

    self::assertSame(
      [
        '#theme' => 'image',
        '#uri' => '/store/modules/custom/commerce_novapay/assets/images/logo.svg',
        '#alt' => 'NovaPay checkout',
        '#width' => 124,
        '#height' => 25,
        '#attributes' => [
          'class' => ['commerce-novapay-payment-summary-logo'],
        ],
      ],
      $form['review']['payment_information']['summary']['payment_gateway'],
    );
  }

  /**
   * Tests that review branding obeys the per-gateway display setting.
   */
  public function testReviewKeepsLabelWhenLogoIsDisabled(): void {
    $gateway = $this->createGateway('novapay', FALSE);
    $gateway->method('id')->willReturn('novapay_text');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects(self::once())
      ->method('load')
      ->with('novapay_text')
      ->willReturn($gateway);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')
      ->with('commerce_payment_gateway')
      ->willReturn($storage);
    $branding = $this->createBranding($entity_type_manager);
    $form = $this->reviewForm('NovaPay text label');

    $branding->alter($form, $gateway);

    self::assertSame(
      ['#markup' => 'NovaPay text label'],
      $form['review']['payment_information']['summary']['payment_gateway'],
    );
  }

  /**
   * Tests that a similarly shaped summary outside review is not changed.
   */
  public function testDoesNotBrandSelectedGatewayOutsideReview(): void {
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects(self::never())->method('getStorage');
    $branding = $this->createBranding($entity_type_manager);
    $gateway = $this->createGateway('novapay');
    $gateway->method('id')->willReturn('novapay_primary');
    $form = $this->reviewForm('NovaPay');
    $form['#step_id'] = 'order_information';

    $branding->alter($form, $gateway);

    self::assertSame(
      ['#markup' => 'NovaPay'],
      $form['review']['payment_information']['summary']['payment_gateway'],
    );
  }

  /**
   * Tests that unrelated and incomplete form elements are ignored.
   */
  public function testIgnoresNonPaymentForms(): void {
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects(self::never())->method('getStorage');
    $branding = $this->createBranding($entity_type_manager);
    $form = [
      'status' => [
        '#type' => 'radios',
        '#options' => ['open' => 'Open'],
      ],
    ];

    $original = $form;
    $branding->alter($form);

    self::assertSame($original, $form);
  }

  /**
   * Creates the branding service with a subdirectory-aware asset URL.
   */
  private function createBranding(
    EntityTypeManagerInterface $entity_type_manager,
  ): PaymentOptionBranding {
    $module_extension_list = $this->createMock(ModuleExtensionList::class);
    $module_extension_list->method('getPath')
      ->with('commerce_novapay')
      ->willReturn('modules/custom/commerce_novapay');
    $request = $this->createMock(Request::class);
    $request->method('getBasePath')->willReturn('/store');
    $request_stack = new RequestStack();
    $request_stack->push($request);

    return new PaymentOptionBranding(
      $entity_type_manager,
      $module_extension_list,
      $request_stack,
    );
  }

  /**
   * Creates a payment gateway mock.
   */
  private function createGateway(
    string $plugin_id,
    ?bool $display_logo = NULL,
  ): PaymentGatewayInterface&MockObject {
    $gateway = $this->createMock(PaymentGatewayInterface::class);
    $gateway->method('getPluginId')->willReturn($plugin_id);
    $configuration = $display_logo === NULL
      ? []
      : ['display_logo' => $display_logo];
    $gateway->method('getPluginConfiguration')->willReturn($configuration);

    return $gateway;
  }

  /**
   * Creates a selected-gateway plugin mock with its checkout label.
   */
  private function createGatewayPlugin(): PaymentGatewayPluginInterface&MockObject {
    $plugin = $this->createMock(PaymentGatewayPluginInterface::class);
    $plugin->method('getDisplayLabel')->willReturn('NovaPay checkout');

    return $plugin;
  }

  /**
   * Builds the relevant part of Commerce's review render array.
   *
   * @return array<array-key, mixed>
   *   A checkout review form.
   */
  private function reviewForm(string $label): array {
    return [
      '#step_id' => 'review',
      'review' => [
        'payment_information' => [
          'summary' => [
            'payment_gateway' => [
              '#markup' => $label,
            ],
          ],
        ],
      ],
    ];
  }

}
