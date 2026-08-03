<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Api\Dto\Request;

/**
 * Contains data used to create a NovaPay acquiring session.
 */
final class CreateSessionRequest implements NovaPayRequestInterface {

  /**
   * Constructs a create-session request.
   *
   * @param string $client_phone
   *   Customer phone in international format.
   * @param string|null $client_first_name
   *   Optional customer first name.
   * @param string|null $client_last_name
   *   Optional customer last name.
   * @param string|null $client_patronymic
   *   Optional customer patronymic.
   * @param string|null $client_email
   *   Optional customer email address.
   * @param array<string, mixed> $metadata
   *   Metadata returned by NovaPay in postbacks.
   * @param string|null $callback_url
   *   Optional absolute postback URL.
   * @param string|null $success_url
   *   Optional absolute success return URL.
   * @param string|null $fail_url
   *   Optional absolute failure return URL.
   * @param int|null $success_redirect_timeout
   *   Optional automatic return timeout in seconds.
   */
  public function __construct(
    #[\SensitiveParameter]
    private readonly string $client_phone,
    #[\SensitiveParameter]
    private readonly ?string $client_first_name = NULL,
    #[\SensitiveParameter]
    private readonly ?string $client_last_name = NULL,
    #[\SensitiveParameter]
    private readonly ?string $client_patronymic = NULL,
    #[\SensitiveParameter]
    private readonly ?string $client_email = NULL,
    #[\SensitiveParameter]
    private readonly array $metadata = [],
    private readonly ?string $callback_url = NULL,
    private readonly ?string $success_url = NULL,
    private readonly ?string $fail_url = NULL,
    private readonly ?int $success_redirect_timeout = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    $payload = ['client_phone' => $this->client_phone];
    $optional = [
      'client_first_name' => $this->client_first_name,
      'client_last_name' => $this->client_last_name,
      'client_patronymic' => $this->client_patronymic,
      'client_email' => $this->client_email,
    ];

    foreach ($optional as $key => $value) {
      if ($value !== NULL) {
        $payload[$key] = $value;
      }
    }

    if ($this->metadata !== []) {
      $payload['metadata'] = $this->metadata;
    }
    if ($this->callback_url !== NULL) {
      $payload['callback_url'] = $this->callback_url;
    }
    if ($this->success_url !== NULL) {
      $payload['success_url'] = $this->success_url;
    }
    if ($this->fail_url !== NULL) {
      $payload['fail_url'] = $this->fail_url;
    }
    if ($this->success_redirect_timeout !== NULL) {
      $payload['success_redirect_timeout'] = $this->success_redirect_timeout;
    }

    return $payload;
  }

  /**
   * Redacts customer data from debug output.
   *
   * @return array<string, string>
   *   Safe diagnostic fields.
   */
  public function __debugInfo(): array {
    return ['request' => 'create_session', 'customer_data' => '[redacted]'];
  }

}
