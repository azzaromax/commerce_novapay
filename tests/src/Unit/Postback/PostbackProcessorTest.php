<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Postback;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_novapay\Credential\Credentials;
use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Exception\InvalidPostbackException;
use Drupal\commerce_novapay\Postback\Dto\NormalizedPostbackEvent;
use Drupal\commerce_novapay\Postback\Dto\ParsedPostback;
use Drupal\commerce_novapay\Postback\NovaPayStatus;
use Drupal\commerce_novapay\Postback\Parser\PostbackParserInterface;
use Drupal\commerce_novapay\Postback\PaymentStatusMapperInterface;
use Drupal\commerce_novapay\Postback\PostbackOutcome;
use Drupal\commerce_novapay\Postback\PostbackProcessor;
use Drupal\commerce_novapay\Postback\PostbackVersion;
use Drupal\commerce_novapay\Runtime\RuntimeConfiguration;
use Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface;
use Drupal\commerce_novapay\Runtime\RuntimeProfile;
use Drupal\commerce_novapay\Runtime\TransactionMode;
use Drupal\commerce_novapay\Signature\SandboxLegacyVerifierInterface;
use Drupal\commerce_novapay\Signature\VerifierInterface;
use Drupal\commerce_order\OrderStorageInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\PaymentStorageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests the signature-first postback processing boundary.
 */
#[CoversClass(PostbackProcessor::class)]
#[Group('commerce_novapay')]
final class PostbackProcessorTest extends TestCase {

  /**
   * The exact-byte signature verifier.
   */
  private VerifierInterface&MockObject $verifier;

  /**
   * The test-environment-only legacy signature verifier.
   */
  private SandboxLegacyVerifierInterface&MockObject $sandboxLegacyVerifier;

  /**
   * The verified-body parser.
   */
  private PostbackParserInterface&MockObject $parser;

  /**
   * The entity type manager.
   */
  private EntityTypeManagerInterface&MockObject $entityTypeManager;

  /**
   * The Commerce payment status mapper.
   */
  private PaymentStatusMapperInterface&MockObject $statusMapper;

  /**
   * The current NovaPay payment gateway.
   */
  private PaymentGatewayInterface&MockObject $gateway;

  /**
   * The runtime-aware gateway plugin.
   */
  private RuntimeConfigurationProviderInterface&MockObject $gatewayPlugin;

  /**
   * The processor under test.
   */
  private PostbackProcessor $processor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->verifier = $this->createMock(VerifierInterface::class);
    $this->sandboxLegacyVerifier = $this->createMock(
      SandboxLegacyVerifierInterface::class,
    );
    $this->parser = $this->createMock(PostbackParserInterface::class);
    $this->entityTypeManager = $this->createMock(
      EntityTypeManagerInterface::class,
    );
    $this->statusMapper = $this->createMock(
      PaymentStatusMapperInterface::class,
    );
    $this->gateway = $this->createMock(PaymentGatewayInterface::class);
    $this->gateway->method('id')->willReturn('novapay_test');
    $this->gatewayPlugin = $this->createMock(
      RuntimeConfigurationProviderInterface::class,
    );
    $this->gatewayPlugin->method('getRuntimeConfiguration')->willReturn(
      $this->createRuntimeConfiguration(),
    );
    $this->processor = new PostbackProcessor(
      $this->verifier,
      $this->sandboxLegacyVerifier,
      $this->parser,
      $this->entityTypeManager,
      $this->statusMapper,
    );
  }

  /**
   * Tests that an invalid signature prevents parsing and entity access.
   */
  public function testInvalidSignatureDoesNotParseOrLoadEntities(): void {
    $this->verifier->expects(self::once())->method('verify')
      ->with('raw-body', 'invalid-signature', 'public-key')
      ->willReturn(FALSE);
    $this->sandboxLegacyVerifier->expects(self::once())->method('verify')
      ->with('raw-body', 'invalid-signature', 'public-key')
      ->willReturn(FALSE);
    $this->parser->expects(self::never())->method('parse');
    $this->entityTypeManager->expects(self::never())->method('getStorage');
    $this->statusMapper->expects(self::never())->method('apply');

    $result = $this->processor->process(
      $this->gateway,
      $this->gatewayPlugin,
      'raw-body',
      'invalid-signature',
    );

    self::assertSame(PostbackOutcome::InvalidSignature, $result->getOutcome());
  }

  /**
   * Tests the explicitly test-only fallback for real sandbox callbacks.
   */
  public function testSandboxAcceptsVerifiedLegacySignature(): void {
    $this->verifier->expects(self::once())->method('verify')
      ->willReturn(FALSE);
    $this->sandboxLegacyVerifier->expects(self::once())->method('verify')
      ->willReturn(TRUE);
    $this->parser->method('parse')->willReturn($this->createParsedPostback());
    $payment = $this->createMock(PaymentInterface::class);
    $payment_storage = $this->createMock(PaymentStorageInterface::class);
    $payment_storage->method('loadByProperties')->willReturn([$payment]);
    $this->entityTypeManager->method('getStorage')
      ->with('commerce_payment')->willReturn($payment_storage);
    $this->statusMapper->expects(self::once())->method('apply')
      ->with($payment, NovaPayStatus::Holded);

    $result = $this->processor->process(
      $this->gateway,
      $this->gatewayPlugin,
      'legacy-sandbox-json',
      'legacy-sandbox-signature',
    );

    self::assertSame(PostbackOutcome::Applied, $result->getOutcome());
  }

  /**
   * Tests that production never attempts the legacy SHA-1 verifier.
   */
  public function testLiveModeRejectsLegacySignature(): void {
    $live_gateway_plugin = $this->createMock(
      RuntimeConfigurationProviderInterface::class,
    );
    $live_gateway_plugin->method('getRuntimeConfiguration')->willReturn(
      $this->createRuntimeConfiguration(NovaPayMode::Live),
    );
    $this->verifier->expects(self::once())->method('verify')
      ->willReturn(FALSE);
    $this->sandboxLegacyVerifier->expects(self::never())->method('verify');
    $this->parser->expects(self::never())->method('parse');
    $this->entityTypeManager->expects(self::never())->method('getStorage');

    $result = $this->processor->process(
      $this->gateway,
      $live_gateway_plugin,
      'legacy-live-json',
      'legacy-live-signature',
    );

    self::assertSame(PostbackOutcome::InvalidSignature, $result->getOutcome());
  }

  /**
   * Tests that unsupported verified JSON is reported without entity access.
   */
  public function testUnsupportedSchemaDoesNotLoadEntities(): void {
    $this->verifier->method('verify')->willReturn(TRUE);
    $this->parser->method('parse')->willThrowException(
      InvalidPostbackException::unsupportedSchema(),
    );
    $this->entityTypeManager->expects(self::never())->method('getStorage');

    $result = $this->processor->process(
      $this->gateway,
      $this->gatewayPlugin,
      'unsupported-json',
      'valid-signature',
    );

    self::assertSame(PostbackOutcome::InvalidPayload, $result->getOutcome());
  }

  /**
   * Tests that an unknown session is acknowledged without payment mutation.
   */
  public function testUnknownSessionDoesNotChangePayment(): void {
    $this->verifier->method('verify')->willReturn(TRUE);
    $this->parser->method('parse')->willReturn($this->createParsedPostback());
    $payment_storage = $this->createMock(PaymentStorageInterface::class);
    $payment_storage->method('loadByProperties')->willReturn([]);
    $order_storage = $this->createMock(OrderStorageInterface::class);
    $this->entityTypeManager->method('getStorage')->willReturnMap([
      ['commerce_payment', $payment_storage],
      ['commerce_order', $order_storage],
    ]);
    $this->statusMapper->expects(self::never())->method('apply');

    $result = $this->processor->process(
      $this->gateway,
      $this->gatewayPlugin,
      'verified-json',
      'valid-signature',
    );

    self::assertSame(PostbackOutcome::UnknownPayment, $result->getOutcome());
    self::assertSame(PostbackVersion::V1, $result->getVersion());
    self::assertSame(NovaPayStatus::Holded, $result->getStatus());
  }

  /**
   * Tests application to a unique same-gateway session payment.
   */
  public function testAppliesVerifiedNormalizedEvent(): void {
    $this->verifier->method('verify')->willReturn(TRUE);
    $this->parser->method('parse')->willReturn($this->createParsedPostback());
    $payment = $this->createMock(PaymentInterface::class);
    $payment_storage = $this->createMock(PaymentStorageInterface::class);
    $payment_storage->expects(self::once())->method('loadByProperties')
      ->with([
        'payment_gateway' => 'novapay_test',
        'remote_id' => 'session-uuid',
      ])
      ->willReturn([1 => $payment]);
    $this->entityTypeManager->method('getStorage')
      ->with('commerce_payment')->willReturn($payment_storage);
    $this->statusMapper->expects(self::once())->method('apply')
      ->with($payment, NovaPayStatus::Holded);

    $result = $this->processor->process(
      $this->gateway,
      $this->gatewayPlugin,
      'verified-json',
      'valid-signature',
    );

    self::assertSame(PostbackOutcome::Applied, $result->getOutcome());
  }

  /**
   * Creates a normalized v1 event for processor tests.
   */
  private function createParsedPostback(): ParsedPostback {
    return new ParsedPostback(
      PostbackVersion::V1,
      NormalizedPostbackEvent::fromValues(
        'session-uuid',
        'holded',
        [],
      ),
    );
  }

  /**
   * Creates a safe test runtime configuration.
   */
  private function createRuntimeConfiguration(
    NovaPayMode $mode = NovaPayMode::Test,
  ): RuntimeConfiguration {
    return new RuntimeConfiguration(
      new RuntimeProfile(
        $mode,
        $mode === NovaPayMode::Live ? 'merchant-live' : NULL,
        TransactionMode::Direct,
        '',
        FALSE,
      ),
      new Credentials(
        $mode,
        $mode === NovaPayMode::Live ? 'merchant-live' : '2',
        'private-key',
        'public-key',
      ),
    );
  }

}
