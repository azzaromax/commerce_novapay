<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Api;

use Drupal\commerce_novapay\Api\Dto\Request\AddPaymentRequest;
use Drupal\commerce_novapay\Api\Dto\Request\CompleteHoldRequest;
use Drupal\commerce_novapay\Api\Dto\Request\CreateSessionRequest;
use Drupal\commerce_novapay\Api\Dto\Request\VoidRequest;
use Drupal\commerce_novapay\Api\NovaPayApiClient;
use Drupal\commerce_novapay\Credential\Credentials;
use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Exception\ApiFatalException;
use Drupal\commerce_novapay\Exception\ApiProcessingException;
use Drupal\commerce_novapay\Exception\ApiRequestException;
use Drupal\commerce_novapay\Exception\ApiTransportException;
use Drupal\commerce_novapay\Exception\ApiUnexpectedResponseException;
use Drupal\commerce_novapay\Exception\ApiValidationException;
use Drupal\commerce_novapay\Exception\NovaPayApiException;
use Drupal\commerce_novapay\Runtime\RuntimeConfiguration;
use Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface;
use Drupal\commerce_novapay\Runtime\RuntimeProfile;
use Drupal\commerce_novapay\Runtime\TransactionMode;
use Drupal\commerce_novapay\Signature\SignerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\PumpStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests signed one-shot NovaPay API requests and response mapping.
 */
#[CoversClass(NovaPayApiClient::class)]
#[Group('commerce_novapay')]
final class NovaPayApiClientTest extends TestCase {

  /**
   * Tests exact JSON stability, signing, headers, and HTTP policy.
   */
  public function testSignsAndSendsTheSameBodyBytesOnce(): void {
    $history = new \ArrayObject();
    $signer = $this->createRecordingSigner();
    $client = $this->createClient(
      [new Response(200, [], '{"id":"session-123"}')],
      $history,
      $signer,
    );

    $response = $client->createSession(
      $this->createGateway(NovaPayMode::Test),
      new CreateSessionRequest(
        client_phone: '+380501234567',
        client_first_name: 'Іван',
        metadata: ['source' => 'Drupal/Commerce'],
        callback_url: 'https://merchant.example/payment/notify/novapay',
      ),
    );

    self::assertSame('session-123', $response->getSessionId());
    self::assertCount(1, $history);
    /** @var array<mixed, mixed> $transaction */
    $transaction = $history[0];
    $expected_body = '{"merchant_id":"2","client_phone":"+380501234567",'
      . '"client_first_name":"Іван","metadata":{"source":"Drupal/Commerce"},'
      . '"callback_url":"https://merchant.example/payment/notify/novapay"}';

    self::assertSame($expected_body, $signer->rawBody);
    self::assertSame(
      $expected_body,
      (string) $transaction['request']->getBody(),
    );
    self::assertSame(
      'https://api-qecom.novapay.ua/v1/session',
      (string) $transaction['request']->getUri(),
    );
    self::assertSame('test-signature', $transaction['request']->getHeaderLine('x-sign'));
    self::assertSame('application/json', $transaction['request']->getHeaderLine('Accept'));
    self::assertSame('application/json', $transaction['request']->getHeaderLine('Content-Type'));
    self::assertSame(5.0, $transaction['options']['connect_timeout']);
    self::assertSame(15.0, $transaction['options']['timeout']);
    self::assertFalse($transaction['options']['allow_redirects']);
    self::assertFalse($transaction['options']['http_errors']);
  }

  /**
   * Tests all four documented acquiring endpoints and response DTOs.
   */
  public function testMapsSuccessfulEndpointResponsesToDtos(): void {
    $history = new \ArrayObject();
    $client = $this->createClient(
      [
        new Response(200, [], '{"id":"session-live"}'),
        new Response(201, [], '{"id":"operation-1","url":"https://ecom.novapay.ua/external/pay?sid=session-live"}'),
        new Response(200, [], 'null'),
        new Response(204),
      ],
      $history,
      $this->createRecordingSigner(),
    );
    $gateway = $this->createGateway(NovaPayMode::Live);

    $session = $client->createSession(
      $gateway,
      new CreateSessionRequest('+380501234567'),
    );
    $payment = $client->addPayment(
      $gateway,
      new AddPaymentRequest(
        'session-live',
        '1250.00',
        TRUE,
        'ORDER-1001',
        '31316718',
        [['count' => 1, 'price' => '1250.00', 'description' => 'Order']],
      ),
    );
    $capture = $client->completeHold(
      $gateway,
      new CompleteHoldRequest('session-live', '500.00'),
    );
    $void = $client->voidPayment(
      $gateway,
      new VoidRequest('session-live'),
    );

    self::assertSame('session-live', $session->getSessionId());
    self::assertSame('operation-1', $payment->getOperationId());
    self::assertSame(
      'https://ecom.novapay.ua/external/pay?sid=session-live',
      $payment->getPaymentUrl(),
    );
    self::assertSame(200, $capture->getStatusCode());
    self::assertSame(204, $void->getStatusCode());
    self::assertCount(4, $history);
    self::assertSame(
      [
        '/v1/session',
        '/v1/payment',
        '/v1/complete-hold',
        '/v1/void',
      ],
      array_map(
        static fn (array $transaction): string => $transaction['request']
          ->getUri()
          ->getPath(),
        iterator_to_array($history),
      ),
    );
    foreach ($history as $transaction) {
      self::assertSame(
        'api-ecom.novapay.ua',
        $transaction['request']->getUri()->getHost(),
      );
    }
  }

  /**
   * Tests conversion of documented NovaPay error types.
   */
  #[DataProvider('apiErrorProvider')]
  public function testMapsDocumentedApiErrors(
    string $body,
    string $exception_class,
  ): void {
    $history = new \ArrayObject();
    $client = $this->createClient(
      [new Response(400, [], $body)],
      $history,
      $this->createRecordingSigner(),
    );

    try {
      $client->voidPayment(
        $this->createGateway(NovaPayMode::Test),
        new VoidRequest('session-123'),
      );
      self::fail('A NovaPay API error must throw a typed exception.');
    }
    catch (NovaPayApiException $exception) {
      self::assertInstanceOf($exception_class, $exception);
      self::assertSame(400, $exception->getHttpStatus());
      self::assertStringNotContainsString('sensitive server text', $exception->getMessage());
    }
  }

  /**
   * Provides documented API error bodies.
   *
   * @return iterable<string, array{string, class-string}>
   *   Error body and expected exception class.
   */
  public static function apiErrorProvider(): iterable {
    yield 'validation' => [
      '{"type":"validation","errors":[{"message":"sensitive server text","code":"too_small","path":"client_phone"}]}',
      ApiValidationException::class,
    ];
    yield 'processing' => [
      '{"type":"processing","error":"sensitive server text","code":"SessionNotFoundError"}',
      ApiProcessingException::class,
    ];
    yield 'fatal' => [
      '{"type":"fatal","errors":{"en":"sensitive server text"}}',
      ApiFatalException::class,
    ];
  }

  /**
   * Tests safe validation details and exclusion of server messages.
   */
  public function testValidationExceptionContainsOnlyCodesAndPaths(): void {
    $history = new \ArrayObject();
    $client = $this->createClient(
      [new Response(422, [], '{"type":"validation","errors":[{"code":"custom","path":"client_phone","message":"+380501234567"}]}')],
      $history,
      $this->createRecordingSigner(),
    );

    try {
      $client->createSession(
        $this->createGateway(NovaPayMode::Test),
        new CreateSessionRequest('+380501234567'),
      );
      self::fail('Validation errors must throw an exception.');
    }
    catch (ApiValidationException $exception) {
      self::assertCount(1, $exception->getViolations());
      self::assertSame('custom', $exception->getViolations()[0]->getCode());
      self::assertSame('client_phone', $exception->getViolations()[0]->getPath());
      self::assertStringNotContainsString('+380501234567', $exception->getMessage());
    }
  }

  /**
   * Tests the documented empty-body HTTP 400 processing response.
   */
  public function testMapsEmptyBadRequestToProcessingException(): void {
    $history = new \ArrayObject();
    $client = $this->createClient(
      [new Response(400)],
      $history,
      $this->createRecordingSigner(),
    );

    try {
      $client->createSession(
        $this->createGateway(NovaPayMode::Test),
        new CreateSessionRequest('+380501234567'),
      );
      self::fail('The documented empty processing response must be typed.');
    }
    catch (ApiProcessingException $exception) {
      self::assertSame(400, $exception->getHttpStatus());
      self::assertNull($exception->getApiCode());
    }
  }

  /**
   * Tests that empty errors remain endpoint-specific.
   */
  public function testRejectsUndocumentedEmptyBadRequest(): void {
    $history = new \ArrayObject();
    $client = $this->createClient(
      [new Response(400)],
      $history,
      $this->createRecordingSigner(),
    );

    $this->expectException(ApiUnexpectedResponseException::class);
    $client->voidPayment(
      $this->createGateway(NovaPayMode::Test),
      new VoidRequest('session-123'),
    );
  }

  /**
   * Tests malformed success and error responses.
   */
  #[DataProvider('unexpectedResponseProvider')]
  public function testRejectsUnexpectedResponses(int $status, string $body): void {
    $history = new \ArrayObject();
    $client = $this->createClient(
      [new Response($status, [], $body)],
      $history,
      $this->createRecordingSigner(),
    );

    $this->expectException(ApiUnexpectedResponseException::class);
    $client->createSession(
      $this->createGateway(NovaPayMode::Test),
      new CreateSessionRequest('+380501234567'),
    );
  }

  /**
   * Provides malformed API responses.
   *
   * @return iterable<string, array{int, string}>
   *   HTTP status and response body.
   */
  public static function unexpectedResponseProvider(): iterable {
    yield 'invalid success JSON' => [200, '{'];
    yield 'missing success fields' => [200, '{}'];
    yield 'unknown API error type' => [400, '{"type":"other"}'];
  }

  /**
   * Tests rejection of payment redirects outside official NovaPay hosts.
   */
  public function testRejectsUntrustedPaymentRedirectHost(): void {
    $history = new \ArrayObject();
    $client = $this->createClient(
      [new Response(200, [], '{"id":"operation-1","url":"https://attacker.example/pay"}')],
      $history,
      $this->createRecordingSigner(),
    );

    $this->expectException(ApiUnexpectedResponseException::class);
    $client->addPayment(
      $this->createGateway(NovaPayMode::Test),
      new AddPaymentRequest('session-123', '10.00', FALSE),
    );
  }

  /**
   * Tests that a payment URL cannot cross resolved API environments.
   */
  public function testRejectsCrossEnvironmentPaymentRedirectHost(): void {
    $history = new \ArrayObject();
    $client = $this->createClient(
      [new Response(200, [], '{"id":"operation-1","url":"https://ecom.novapay.ua/external/pay"}')],
      $history,
      $this->createRecordingSigner(),
    );

    $this->expectException(ApiUnexpectedResponseException::class);
    $client->addPayment(
      $this->createGateway(NovaPayMode::Test),
      new AddPaymentRequest('session-123', '10.00', FALSE),
    );
  }

  /**
   * Tests bounded reads when a chunked response has no declared size.
   */
  public function testBoundsUnknownSizeResponseWhileReading(): void {
    $history = new \ArrayObject();
    $remaining = 1048577;
    $generated = 0;
    $stream = new PumpStream(
      static function (int $length) use (&$remaining, &$generated): ?string {
        if ($remaining === 0) {
          return NULL;
        }

        $size = min($length, $remaining);
        $remaining -= $size;
        $generated += $size;
        return str_repeat('A', $size);
      },
    );
    self::assertNull($stream->getSize());
    $client = $this->createClient(
      [new Response(200, [], $stream)],
      $history,
      $this->createRecordingSigner(),
    );

    try {
      $client->createSession(
        $this->createGateway(NovaPayMode::Test),
        new CreateSessionRequest('+380501234567'),
      );
      self::fail('An oversized unknown-length response must be rejected.');
    }
    catch (ApiUnexpectedResponseException) {
      self::assertSame(1048577, $generated);
    }
  }

  /**
   * Tests early rejection when an oversized response declares its size.
   */
  public function testRejectsOversizedDeclaredResponse(): void {
    $history = new \ArrayObject();
    $client = $this->createClient(
      [new Response(200, [], str_repeat('A', 1048577))],
      $history,
      $this->createRecordingSigner(),
    );

    $this->expectException(ApiUnexpectedResponseException::class);
    $client->createSession(
      $this->createGateway(NovaPayMode::Test),
      new CreateSessionRequest('+380501234567'),
    );
  }

  /**
   * Tests that JSON encoding fails before signing or sending.
   */
  public function testRejectsUnencodableRequestBeforeSending(): void {
    $history = new \ArrayObject();
    $signer = $this->createRecordingSigner();
    $client = $this->createClient(
      [new Response(200, [], '{"id":"unused"}')],
      $history,
      $signer,
    );

    $this->expectException(ApiRequestException::class);
    try {
      $client->createSession(
        $this->createGateway(NovaPayMode::Test),
        new CreateSessionRequest(
          '+380501234567',
          metadata: ['invalid' => "\xB1\x31"],
        ),
      );
    }
    finally {
      self::assertNull($signer->rawBody);
      self::assertCount(0, $history);
    }
  }

  /**
   * Tests that a failed financial POST is not retried automatically.
   */
  public function testDoesNotRetryTransportFailures(): void {
    $history = new \ArrayObject();
    $client = $this->createClient(
      [
        new ConnectException(
          'sensitive transport detail',
          new Request('POST', 'https://api-qecom.novapay.ua/v1/payment'),
        ),
        new Response(200, [], '{"id":"must-not-be-used"}'),
      ],
      $history,
      $this->createRecordingSigner(),
    );

    try {
      $client->addPayment(
        $this->createGateway(NovaPayMode::Test),
        new AddPaymentRequest('session-123', '10.00', FALSE),
      );
      self::fail('A transport failure must be reported.');
    }
    catch (ApiTransportException $exception) {
      self::assertCount(1, $history);
      self::assertStringNotContainsString(
        'sensitive transport detail',
        $exception->getMessage(),
      );
      self::assertNull($exception->getPrevious());
    }
  }

  /**
   * Creates a Guzzle client backed by deterministic queued responses.
   *
   * @param list<\Psr\Http\Message\ResponseInterface|\Throwable> $queue
   *   Mock handler responses or failures.
   * @param \ArrayObject<int, array<mixed, mixed>> $history
   *   Captured request transactions.
   * @param \Drupal\commerce_novapay\Signature\SignerInterface $signer
   *   Request signer used by the client.
   */
  private function createClient(
    array $queue,
    \ArrayObject $history,
    SignerInterface $signer,
  ): NovaPayApiClient {
    $stack = HandlerStack::create(new MockHandler($queue));
    $stack->push(Middleware::history($history));

    return new NovaPayApiClient(new Client(['handler' => $stack]), $signer);
  }

  /**
   * Creates a signer that records the exact input body.
   */
  private function createRecordingSigner(): RecordingSigner {
    return new RecordingSigner();
  }

  /**
   * Creates a gateway-like runtime provider for one API mode.
   */
  private function createGateway(
    NovaPayMode $mode,
  ): RuntimeConfigurationProviderInterface {
    $profile = new RuntimeProfile(
      $mode,
      $mode === NovaPayMode::Live ? 'merchant-live' : NULL,
      TransactionMode::Direct,
      '',
      FALSE,
    );
    $credentials = new Credentials(
      $mode,
      $mode === NovaPayMode::Live ? 'merchant-live' : '2',
      'private-key-not-used-by-test-signer',
      'public-key-not-used-by-api-client',
    );
    $configuration = new RuntimeConfiguration($profile, $credentials);

    return new class($configuration) implements RuntimeConfigurationProviderInterface {

      /**
       * Constructs a fixed runtime provider.
       */
      public function __construct(
        private readonly RuntimeConfiguration $configuration,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function getRuntimeConfiguration(): RuntimeConfiguration {
        return $this->configuration;
      }

    };
  }

}

/**
 * Records exact bytes supplied by the API client for signing.
 */
final class RecordingSigner implements SignerInterface {

  /**
   * The exact body received by the signer.
   */
  public ?string $rawBody = NULL;

  /**
   * {@inheritdoc}
   */
  public function sign(
    #[\SensitiveParameter]
    string $raw_body,
    #[\SensitiveParameter]
    string $private_key_pem,
  ): string {
    $this->rawBody = $raw_body;
    return 'test-signature';
  }

}
