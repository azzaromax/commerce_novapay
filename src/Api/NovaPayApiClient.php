<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Api;

use Drupal\commerce_novapay\Api\Dto\Request\AddPaymentRequest;
use Drupal\commerce_novapay\Api\Dto\Request\CompleteHoldRequest;
use Drupal\commerce_novapay\Api\Dto\Request\CreateSessionRequest;
use Drupal\commerce_novapay\Api\Dto\Request\GetStatusRequest;
use Drupal\commerce_novapay\Api\Dto\Request\NovaPayRequestInterface;
use Drupal\commerce_novapay\Api\Dto\Request\VoidRequest;
use Drupal\commerce_novapay\Api\Dto\Response\AcknowledgementResponse;
use Drupal\commerce_novapay\Api\Dto\Response\PaymentResponse;
use Drupal\commerce_novapay\Api\Dto\Response\SessionResponse;
use Drupal\commerce_novapay\Api\Dto\Response\SessionStatusResponse;
use Drupal\commerce_novapay\Api\Dto\Response\ValidationViolation;
use Drupal\commerce_novapay\Exception\ApiFatalException;
use Drupal\commerce_novapay\Exception\ApiProcessingException;
use Drupal\commerce_novapay\Exception\ApiRequestException;
use Drupal\commerce_novapay\Exception\ApiTransportException;
use Drupal\commerce_novapay\Exception\ApiUnexpectedResponseException;
use Drupal\commerce_novapay\Exception\ApiValidationException;
use Drupal\commerce_novapay\Exception\NovaPayApiException;
use Drupal\commerce_novapay\Logging\NovaPayLoggerInterface;
use Drupal\commerce_novapay\Runtime\RuntimeConfiguration;
use Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface;
use Drupal\commerce_novapay\Signature\SignerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends one-shot signed POST requests to the NovaPay acquiring API.
 */
final class NovaPayApiClient implements NovaPayApiClientInterface {

  private const CONNECT_TIMEOUT_SECONDS = 5.0;

  private const REQUEST_TIMEOUT_SECONDS = 15.0;

  private const MAX_RESPONSE_BYTES = 1048576;

  private const JSON_FLAGS = JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_THROW_ON_ERROR;

  /**
   * Constructs the NovaPay API client.
   */
  public function __construct(
    private readonly ClientInterface $http_client,
    private readonly SignerInterface $signer,
    private readonly NovaPayLoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function createSession(
    RuntimeConfigurationProviderInterface $gateway,
    CreateSessionRequest $request,
  ): SessionResponse {
    $configuration = $gateway->getRuntimeConfiguration();
    $response = $this->post($configuration, '/v1/session', $request);
    $data = $this->decodeSuccessObject($response);

    try {
      return SessionResponse::fromArray($data);
    }
    catch (\InvalidArgumentException) {
      throw ApiUnexpectedResponseException::invalidSuccess(
        $response->getStatusCode(),
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function addPayment(
    RuntimeConfigurationProviderInterface $gateway,
    AddPaymentRequest $request,
  ): PaymentResponse {
    $configuration = $gateway->getRuntimeConfiguration();
    $response = $this->post($configuration, '/v1/payment', $request);
    $data = $this->decodeSuccessObject($response);

    try {
      return PaymentResponse::fromArray(
        $data,
        $configuration->getProfile()->getMode(),
      );
    }
    catch (\InvalidArgumentException) {
      throw ApiUnexpectedResponseException::invalidSuccess(
        $response->getStatusCode(),
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function completeHold(
    RuntimeConfigurationProviderInterface $gateway,
    CompleteHoldRequest $request,
  ): AcknowledgementResponse {
    $configuration = $gateway->getRuntimeConfiguration();
    return $this->createAcknowledgement(
      $this->post($configuration, '/v1/complete-hold', $request),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(
    RuntimeConfigurationProviderInterface $gateway,
    VoidRequest $request,
  ): AcknowledgementResponse {
    $configuration = $gateway->getRuntimeConfiguration();
    return $this->createAcknowledgement(
      $this->post($configuration, '/v1/void', $request),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(
    RuntimeConfigurationProviderInterface $gateway,
    GetStatusRequest $request,
  ): SessionStatusResponse {
    $configuration = $gateway->getRuntimeConfiguration();
    $response = $this->post($configuration, '/v1/get-status', $request);
    $data = $this->decodeSuccessObject($response);

    try {
      return SessionStatusResponse::fromArray($data);
    }
    catch (\InvalidArgumentException) {
      throw ApiUnexpectedResponseException::invalidSuccess(
        $response->getStatusCode(),
      );
    }
  }

  /**
   * Sends a single signed POST request without automatic retries.
   */
  private function post(
    RuntimeConfiguration $configuration,
    string $path,
    NovaPayRequestInterface $request,
  ): ResponseInterface {
    $credentials = $configuration->getCredentials();
    $payload = ['merchant_id' => $credentials->getMerchantId()]
      + $request->toArray();
    try {
      $body = $this->encodeBody($payload);
    }
    catch (ApiRequestException $exception) {
      $this->logger->logError('api_request_rejected', [
        'endpoint' => $path,
        'source' => $exception::class,
      ]);
      throw $exception;
    }
    $detailed_logging = $configuration->getProfile()->isLoggingEnabled();
    $this->logger->logDetailed(
      $detailed_logging,
      'api_request',
      [
        'endpoint' => $path,
        'payload' => $payload,
      ],
    );
    try {
      $signature = $this->signer->sign(
        $body,
        $credentials->getPrivateKeyPem(),
      );
    }
    catch (\Throwable $exception) {
      $this->logger->logError('api_signing_error', [
        'endpoint' => $path,
        'source' => $exception::class,
      ]);
      throw $exception;
    }

    try {
      $response = $this->http_client->request(
        'POST',
        $this->buildUrl($configuration, $path),
        [
          'headers' => [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'x-sign' => $signature,
          ],
          'body' => $body,
          'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
          'timeout' => self::REQUEST_TIMEOUT_SECONDS,
          'allow_redirects' => FALSE,
          'http_errors' => FALSE,
          'synchronous' => TRUE,
        ],
      );
    }
    catch (GuzzleException $exception) {
      $this->logger->logError('api_transport_error', [
        'endpoint' => $path,
        'source' => $exception::class,
      ]);
      // Do not retain a request exception: it may contain signed request data.
      throw ApiTransportException::requestFailed();
    }

    try {
      $response_body = $this->readResponseBody($response);
    }
    catch (ApiUnexpectedResponseException $exception) {
      $this->logger->logError('api_response_rejected', [
        'endpoint' => $path,
        'http_status' => $response->getStatusCode(),
        'source' => $exception::class,
      ]);
      throw $exception;
    }
    $this->logger->logDetailedJson(
      $detailed_logging,
      'api_response',
      $response_body,
      [
        'endpoint' => $path,
        'http_status' => $response->getStatusCode(),
      ],
    );
    $response = $response->withBody(Utils::streamFor($response_body));

    if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
      try {
        $this->throwApiError($response, $path);
      }
      catch (ApiProcessingException $exception) {
        // Persist only stable, non-PII diagnostic fields. The response body
        // remains available exclusively through explicitly enabled detailed
        // logging.
        $this->logger->logError('api_http_error', [
          'endpoint' => $path,
          'http_status' => $response->getStatusCode(),
          'api_error_code' => $exception->getApiCode(),
          'request_uuid' => $exception->getRequestUuid(),
        ]);
        throw $exception;
      }
      catch (ApiValidationException $exception) {
        $this->logger->logError('api_http_error', [
          'endpoint' => $path,
          'http_status' => $response->getStatusCode(),
          'request_uuid' => $exception->getRequestUuid(),
          'validation_violations' => array_map(
            static fn (ValidationViolation $violation): array => [
              'code' => $violation->getCode(),
              'path' => $violation->getPath(),
            ],
            $exception->getViolations(),
          ),
        ]);
        throw $exception;
      }
      catch (NovaPayApiException $exception) {
        $this->logger->logError('api_http_error', [
          'endpoint' => $path,
          'http_status' => $response->getStatusCode(),
          'source' => $exception::class,
        ]);
        throw $exception;
      }
    }

    return $response;
  }

  /**
   * Encodes the final body exactly once before signing and sending.
   *
   * @param array<string, mixed> $payload
   *   The complete request payload.
   */
  private function encodeBody(
    #[\SensitiveParameter]
    array $payload,
  ): string {
    try {
      return json_encode($payload, self::JSON_FLAGS, 32);
    }
    catch (\JsonException) {
      throw ApiRequestException::encodingFailed();
    }
  }

  /**
   * Builds an endpoint exclusively from the resolved runtime environment.
   */
  private function buildUrl(
    RuntimeConfiguration $configuration,
    string $path,
  ): string {
    return rtrim($configuration->getApiBaseUrl(), '/') . $path;
  }

  /**
   * Decodes a successful response that must contain a JSON object.
   *
   * @return array<string, mixed>
   *   The decoded response object.
   */
  private function decodeSuccessObject(ResponseInterface $response): array {
    $data = $this->decodeJson($response, TRUE);
    if (!is_array($data)) {
      throw ApiUnexpectedResponseException::invalidSuccess(
        $response->getStatusCode(),
      );
    }

    return $data;
  }

  /**
   * Creates a DTO for a successful empty, null, or object response.
   */
  private function createAcknowledgement(
    ResponseInterface $response,
  ): AcknowledgementResponse {
    $body = $this->readResponseBody($response);
    if (trim($body) === '') {
      return new AcknowledgementResponse($response->getStatusCode());
    }

    try {
      $data = json_decode($body, TRUE, 32, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      throw ApiUnexpectedResponseException::invalidSuccess(
        $response->getStatusCode(),
      );
    }

    if ($data !== NULL && !is_array($data)) {
      throw ApiUnexpectedResponseException::invalidSuccess(
        $response->getStatusCode(),
      );
    }

    return new AcknowledgementResponse($response->getStatusCode());
  }

  /**
   * Converts documented NovaPay error shapes to typed safe exceptions.
   */
  private function throwApiError(
    ResponseInterface $response,
    string $path,
  ): never {
    $body = $this->readResponseBody($response);
    if (
      trim($body) === ''
      && $response->getStatusCode() === 400
      && $path === '/v1/session'
    ) {
      throw new ApiProcessingException(400, NULL);
    }

    try {
      $data = json_decode($body, TRUE, 32, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      throw ApiUnexpectedResponseException::invalidError(
        $response->getStatusCode(),
      );
    }

    if (!is_array($data) || !is_string($data['type'] ?? NULL)) {
      throw ApiUnexpectedResponseException::invalidError(
        $response->getStatusCode(),
      );
    }

    $status = $response->getStatusCode();
    switch (strtolower($data['type'])) {
      case 'validation':
        throw new ApiValidationException(
          $status,
          $this->getValidationViolations($data),
          $this->getUuid($data['uuid'] ?? NULL),
        );

      case 'processing':
        throw new ApiProcessingException(
          $status,
          $this->getSafeString($data['code'] ?? NULL, 128),
          $this->getUuid($data['uuid'] ?? NULL),
        );

      case 'fatal':
        throw new ApiFatalException($status);

      default:
        throw ApiUnexpectedResponseException::invalidError($status);
    }
  }

  /**
   * Decodes a bounded JSON response.
   */
  private function decodeJson(
    ResponseInterface $response,
    bool $success,
  ): mixed {
    $body = $this->readResponseBody($response);
    try {
      return json_decode($body, TRUE, 32, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      throw $success
        ? ApiUnexpectedResponseException::invalidSuccess(
          $response->getStatusCode(),
        )
        : ApiUnexpectedResponseException::invalidError(
          $response->getStatusCode(),
        );
    }
  }

  /**
   * Reads a size-limited response without exposing it in exceptions.
   */
  private function readResponseBody(ResponseInterface $response): string {
    $stream = $response->getBody();
    $declared_size = $stream->getSize();
    if (
      $declared_size !== NULL
      && $declared_size > self::MAX_RESPONSE_BYTES
    ) {
      $this->throwUnexpectedResponse($response->getStatusCode());
    }

    $body = '';
    while (!$stream->eof() && strlen($body) <= self::MAX_RESPONSE_BYTES) {
      $remaining = self::MAX_RESPONSE_BYTES + 1 - strlen($body);
      $chunk = $stream->read(min(8192, $remaining));
      if ($chunk === '') {
        break;
      }
      $body .= $chunk;
    }

    if (strlen($body) > self::MAX_RESPONSE_BYTES || !$stream->eof()) {
      $this->throwUnexpectedResponse($response->getStatusCode());
    }

    return $body;
  }

  /**
   * Throws the response exception matching the HTTP status class.
   */
  private function throwUnexpectedResponse(int $http_status): never {
    throw $http_status >= 200 && $http_status < 300
      ? ApiUnexpectedResponseException::invalidSuccess($http_status)
      : ApiUnexpectedResponseException::invalidError($http_status);
  }

  /**
   * Extracts only bounded codes and paths from validation errors.
   *
   * @param array<string, mixed> $data
   *   The decoded API error.
   *
   * @return list<\Drupal\commerce_novapay\Api\Dto\Response\ValidationViolation>
   *   Safe validation details.
   */
  private function getValidationViolations(array $data): array {
    $errors = $data['errors'] ?? NULL;
    if (!is_array($errors)) {
      return [];
    }

    $violations = [];
    foreach ($errors as $error) {
      if (!is_array($error)) {
        continue;
      }

      $code = $this->getSafeString(
        $error['code'] ?? $error['keyword'] ?? NULL,
        128,
      );
      $path = $this->getSafeString(
        $error['path'] ?? $error['instancePath'] ?? NULL,
        256,
        TRUE,
      );
      $violations[] = new ValidationViolation($code, $path);
    }

    return $violations;
  }

  /**
   * Returns a bounded machine-readable value without arbitrary API text.
   */
  private function getSafeString(
    mixed $value,
    int $maximum_length,
    bool $allow_path = FALSE,
  ): ?string {
    if (!is_string($value) || strlen($value) > $maximum_length) {
      return NULL;
    }

    $pattern = $allow_path
      ? '/^[A-Za-z0-9_.\/-]*$/D'
      : '/^[A-Za-z0-9_.:-]*$/D';
    return preg_match($pattern, $value) === 1 ? $value : NULL;
  }

  /**
   * Returns a strictly validated NovaPay request UUID.
   */
  private function getUuid(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }

    return preg_match(
      '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di',
      $value,
    ) === 1 ? strtolower($value) : NULL;
  }

}
