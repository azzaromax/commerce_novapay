<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Postback;

use Drupal\commerce_novapay\Plugin\Commerce\PaymentGateway\NovaPay;
use Drupal\commerce_novapay\Logging\NovaPayLoggerInterface;
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
        PostbackVersion::V2,
        NovaPayStatus::Paid,
      ),
      Response::HTTP_OK,
    ];
    yield 'duplicate' => [
      PostbackResult::forEvent(
        PostbackOutcome::Duplicate,
        PostbackVersion::V2,
        NovaPayStatus::Paid,
      ),
      Response::HTTP_OK,
    ];
    yield 'ignored' => [
      PostbackResult::forEvent(
        PostbackOutcome::Ignored,
        PostbackVersion::V2,
        NovaPayStatus::Processing,
      ),
      Response::HTTP_OK,
    ];
  }

  /**
   * Tests postback data is delegated only to the sanitizer-enforcing logger.
   */
  public function testLogsSafeIgnoredPostbackDiagnostics(): void {
    $processor = $this->createMock(PostbackProcessorInterface::class);
    $processor->method('process')->willReturn(PostbackResult::forEvent(
      PostbackOutcome::Ignored,
      PostbackVersion::V2,
      NovaPayStatus::Failed,
      [
        'reason' => 'no_permitted_payment_mutation',
        'payment_state' => 'failed',
        'remote_state' => 'failed',
        'pending_operation' => 'none_or_other',
      ],
      TRUE,
    ));
    $logger = $this->createMock(NovaPayLoggerInterface::class);
    $logger->expects(self::once())->method('logDetailedJson')
      ->with(
        TRUE,
        'postback',
        'raw-body',
        self::callback(static function (array $context): bool {
          return $context['gateway'] === 'novapay_test'
            && $context['outcome'] === 'ignored'
            && $context['version'] === 'v2'
            && $context['status'] === 'failed'
            && $context['diagnostics']['reason']
              === 'no_permitted_payment_mutation';
        }),
      );
    $plugin = $this->createPlugin($processor, $logger);

    $plugin->onNotify(Request::create('/', 'POST', [], [], [], [], 'raw-body'));
  }

  /**
   * Tests rejected callbacks always log metadata but never the raw payload.
   */
  public function testAlwaysLogsRejectedPostbackWithoutPayload(): void {
    $processor = $this->createMock(PostbackProcessorInterface::class);
    $processor->method('process')->willReturn(
      PostbackResult::invalidSignature(TRUE),
    );
    $logger = $this->createMock(NovaPayLoggerInterface::class);
    $logger->expects(self::never())->method('logDetailedJson');
    $logger->expects(self::once())->method('logDetailed')
      ->with(
        TRUE,
        'postback',
        self::callback(static function (array $context): bool {
          return $context['gateway'] === 'novapay_test'
            && $context['outcome'] === 'invalid_signature'
            && $context['payload_bytes'] === 32
            && $context['payload_sha256'] === hash(
              'sha256',
              '{"client_phone":"+380501234567"}',
            )
            && !array_key_exists('payload', $context);
        }),
      );
    $logger->expects(self::once())->method('logError')
      ->with('postback_rejected', [
        'gateway' => 'novapay_test',
        'outcome' => 'invalid_signature',
      ]);
    $plugin = $this->createPlugin($processor, $logger);

    $request = Request::create(
      '/',
      'POST',
      [],
      [],
      [],
      [],
      '{"client_phone":"+380501234567"}',
    );
    $request->headers->set('x-sign', 'raw-signature');

    $response = $plugin->onNotify($request);

    self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
  }

  /**
   * Creates a minimally wired gateway plugin for notification tests.
   */
  private function createPlugin(
    PostbackProcessorInterface $processor,
    ?NovaPayLoggerInterface $logger = NULL,
  ): NovaPay {
    $plugin = new NovaPay([], 'novapay', []);
    $gateway = $this->createMock(PaymentGatewayInterface::class);
    $gateway->method('id')->willReturn('novapay_test');
    $logger ??= $this->createMock(NovaPayLoggerInterface::class);
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
