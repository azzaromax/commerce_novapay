<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Postback;

use Drupal\commerce_novapay\Plugin\Commerce\PaymentGateway\NovaPay;
use Drupal\commerce_novapay\Postback\NovaPayStatus;
use Drupal\commerce_novapay\Postback\PostbackOutcome;
use Drupal\commerce_novapay\Postback\PostbackProcessorInterface;
use Drupal\commerce_novapay\Postback\PostbackResult;
use Drupal\commerce_novapay\Postback\PostbackVersion;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests HTTP response mapping at the Commerce notification boundary.
 */
#[CoversClass(NovaPay::class)]
#[Group('commerce_novapay')]
final class NovaPayNotifyTest extends TestCase {

  /**
   * Tests expected HTTP responses for bounded processor outcomes.
   */
  #[DataProvider('outcomeProvider')]
  public function testMapsOutcomeToHttpStatus(
    PostbackResult $result,
    int $expected_status,
  ): void {
    $processor = $this->createMock(PostbackProcessorInterface::class);
    $processor->expects(self::once())->method('process')
      ->with(
        self::isInstanceOf(PaymentGatewayInterface::class),
        self::isInstanceOf(NovaPay::class),
        'raw-body',
        'signature',
      )
      ->willReturn($result);
    $plugin = $this->createPlugin($processor);
    $request = Request::create('/', 'POST', [], [], [], [], 'raw-body');
    $request->headers->set('x-sign', 'signature');

    $response = $plugin->onNotify($request);

    self::assertSame($expected_status, $response->getStatusCode());
    self::assertSame('', $response->getContent());
  }

  /**
   * Provides processor outcomes and required HTTP response statuses.
   *
   * @return iterable<string, array{\Drupal\commerce_novapay\Postback\PostbackResult, int}>
   *   Postback result and HTTP status.
   */
  public static function outcomeProvider(): iterable {
    yield 'invalid signature' => [
      PostbackResult::invalidSignature(),
      Response::HTTP_FORBIDDEN,
    ];
    yield 'unsupported schema' => [
      PostbackResult::invalidPayload(),
      Response::HTTP_BAD_REQUEST,
    ];
    yield 'unknown payment' => [
      PostbackResult::forEvent(
        PostbackOutcome::UnknownPayment,
        PostbackVersion::V2,
        NovaPayStatus::Holded,
      ),
      Response::HTTP_OK,
    ];
    yield 'applied' => [
      PostbackResult::forEvent(
        PostbackOutcome::Applied,
        PostbackVersion::V1,
        NovaPayStatus::Paid,
      ),
      Response::HTTP_OK,
    ];
    yield 'duplicate' => [
      PostbackResult::forEvent(
        PostbackOutcome::Duplicate,
        PostbackVersion::V1,
        NovaPayStatus::Paid,
      ),
      Response::HTTP_OK,
    ];
    yield 'ignored' => [
      PostbackResult::forEvent(
        PostbackOutcome::Ignored,
        PostbackVersion::V1,
        NovaPayStatus::Processing,
      ),
      Response::HTTP_OK,
    ];
  }

  /**
   * Tests sanitized details are logged without postback identifiers or body.
   */
  public function testLogsSafeIgnoredPostbackDiagnostics(): void {
    $processor = $this->createMock(PostbackProcessorInterface::class);
    $processor->method('process')->willReturn(PostbackResult::forEvent(
      PostbackOutcome::Ignored,
      PostbackVersion::V1,
      NovaPayStatus::Failed,
      [
        'reason' => 'no_permitted_payment_mutation',
        'payment_state' => 'failed',
        'remote_state' => 'failed',
        'pending_operation' => 'none_or_other',
      ],
    ));
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects(self::exactly(2))->method('notice')
      ->willReturnCallback(
        static function (string $message, array $context): void {
          self::assertStringNotContainsString('session', $message);
          self::assertStringNotContainsString('raw-body', $message);
          self::assertStringNotContainsString('signature', $message);
          self::assertArrayNotHasKey('@session_id', $context);
          self::assertSame('novapay_test', $context['@gateway']);
        },
      );
    $plugin = $this->createPlugin($processor, $logger);

    $plugin->onNotify(Request::create('/', 'POST', [], [], [], [], 'raw-body'));
  }

  /**
   * Creates a minimally wired gateway plugin for notification tests.
   */
  private function createPlugin(
    PostbackProcessorInterface $processor,
    ?LoggerInterface $logger = NULL,
  ): NovaPay {
    $plugin = new NovaPay([], 'novapay', []);
    $gateway = $this->createMock(PaymentGatewayInterface::class);
    $gateway->method('id')->willReturn('novapay_test');
    $logger ??= $this->createMock(LoggerInterface::class);
    $wire = \Closure::bind(
      function () use ($gateway, $processor, $logger): void {
        $this->parentEntity = $gateway;
        $this->postbackProcessor = $processor;
        $this->logger = $logger;
      },
      $plugin,
      NovaPay::class,
    );
    self::assertInstanceOf(\Closure::class, $wire);
    $wire();

    return $plugin;
  }

}
