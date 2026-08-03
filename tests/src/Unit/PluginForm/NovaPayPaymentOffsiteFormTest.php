<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\PluginForm;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Utility\UnroutedUrlAssemblerInterface;
use Drupal\commerce\Response\NeedsRedirectException;
use Drupal\commerce_novapay\Checkout\CheckoutCoordinatorInterface;
use Drupal\commerce_novapay\Exception\ApiTransportException;
use Drupal\commerce_novapay\Exception\CheckoutPreparationException;
use Drupal\commerce_novapay\PluginForm\NovaPayPaymentOffsiteForm;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Tests the NovaPay off-site redirect form boundary.
 */
#[CoversClass(NovaPayPaymentOffsiteForm::class)]
#[Group('commerce_novapay')]
final class NovaPayPaymentOffsiteFormTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $assembler = $this->createMock(UnroutedUrlAssemblerInterface::class);
    $assembler->method('assemble')->willReturnCallback(
      static fn (string $uri): string => $uri,
    );
    $container = new ContainerBuilder();
    $container->set('unrouted_url_assembler', $assembler);
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Tests that a prepared URL produces a Commerce trusted GET redirect.
   */
  public function testBuildRedirectsWithGet(): void {
    $coordinator = $this->createMock(CheckoutCoordinatorInterface::class);
    $payment = $this->createMock(PaymentInterface::class);
    $coordinator->expects(self::once())->method('prepareRedirect')
      ->with(
        $payment,
        'https://merchant.example/return',
        'https://merchant.example/cancel',
      )
      ->willReturn('https://qecom.novapay.ua/session-id');
    $form = $this->createForm($coordinator, $payment);

    try {
      $form->buildConfigurationForm($this->getForm(), new FormState());
      self::fail('A GET redirect exception was not thrown.');
    }
    catch (NeedsRedirectException $exception) {
      $response = $exception->getResponse();
      self::assertInstanceOf(RedirectResponse::class, $response);
      self::assertSame(
        'https://qecom.novapay.ua/session-id',
        $response->getTargetUrl(),
      );
    }
  }

  /**
   * Tests safe conversion and logging of a NovaPay API failure.
   */
  public function testApiFailureBecomesSafeGatewayException(): void {
    $coordinator = $this->createMock(CheckoutCoordinatorInterface::class);
    $payment = $this->createMock(PaymentInterface::class);
    $payment->method('getOrderId')->willReturn(10);
    $coordinator->method('prepareRedirect')->willThrowException(
      CheckoutPreparationException::fromThrowable(
        'create_session',
        ApiTransportException::requestFailed(),
      ),
    );
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects(self::once())->method('error')
      ->with(
        self::stringContains('NovaPay checkout preparation failed'),
        self::callback(static function (array $context): bool {
          return $context['@order_id'] === '10'
            && $context['@stage'] === 'create_session'
            && $context['@source'] === ApiTransportException::class
            && $context['@http_status'] === 'none'
            && $context['@api_detail'] === 'none';
        }),
      );
    $form = $this->createForm($coordinator, $payment, $logger);

    try {
      $form->buildConfigurationForm($this->getForm(), new FormState());
      self::fail('A payment gateway exception was not thrown.');
    }
    catch (PaymentGatewayException $exception) {
      self::assertSame($payment, $exception->getPayment());
      self::assertSame(
        'NovaPay checkout could not be prepared. Please try again later.',
        $exception->getMessage(),
      );
      self::assertNull($exception->getPrevious());
    }
  }

  /**
   * Creates the tested form with a Commerce payment entity.
   */
  private function createForm(
    CheckoutCoordinatorInterface $coordinator,
    PaymentInterface $payment,
    ?LoggerInterface $logger = NULL,
  ): NovaPayPaymentOffsiteForm {
    $logger ??= $this->createMock(LoggerInterface::class);
    $form = new NovaPayPaymentOffsiteForm($coordinator, $logger);
    $form->setEntity($payment);
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')->willReturnCallback(
      static fn (TranslatableMarkup $markup): string =>
        $markup->getUntranslatedString(),
    );
    $form->setStringTranslation($translation);
    return $form;
  }

  /**
   * Gets the minimum valid Commerce off-site form structure.
   *
   * @return array<string, string>
   *   The form values.
   */
  private function getForm(): array {
    return [
      '#return_url' => 'https://merchant.example/return',
      '#cancel_url' => 'https://merchant.example/cancel',
    ];
  }

}
