<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Api\Dto\Response;

use Drupal\commerce_novapay\Credential\NovaPayMode;

/**
 * Represents a successful NovaPay add-payment response.
 */
final class PaymentResponse {

  /**
   * Constructs a payment response.
   *
   * @param string $operation_id
   *   NovaPay operation identifier.
   * @param string $payment_url
   *   HTTPS payment page URL.
   */
  private function __construct(
    private readonly string $operation_id,
    private readonly string $payment_url,
  ) {}

  /**
   * Builds the DTO from a decoded API response.
   *
   * @param array<string, mixed> $data
   *   The decoded response data.
   * @param \Drupal\commerce_novapay\Credential\NovaPayMode $mode
   *   The resolved API environment that produced the response.
   */
  public static function fromArray(array $data, NovaPayMode $mode): self {
    $operation_id = $data['id'] ?? NULL;
    $payment_url = $data['url'] ?? NULL;
    $payment_host = is_string($payment_url)
      ? strtolower((string) parse_url($payment_url, PHP_URL_HOST))
      : '';
    $payment_port = is_string($payment_url)
      ? parse_url($payment_url, PHP_URL_PORT)
      : FALSE;
    $expected_host = match ($mode) {
      NovaPayMode::Test => 'qecom.novapay.ua',
      NovaPayMode::Live => 'ecom.novapay.ua',
    };
    if (
      !is_string($operation_id)
      || trim($operation_id) === ''
      || !is_string($payment_url)
      || filter_var($payment_url, FILTER_VALIDATE_URL) === FALSE
      || strtolower((string) parse_url($payment_url, PHP_URL_SCHEME)) !== 'https'
      || $payment_host !== $expected_host
      || ($payment_port !== NULL && $payment_port !== 443)
      || parse_url($payment_url, PHP_URL_USER) !== NULL
      || parse_url($payment_url, PHP_URL_PASS) !== NULL
    ) {
      throw new \InvalidArgumentException('Payment response is invalid.');
    }

    return new self(trim($operation_id), $payment_url);
  }

  /**
   * Gets the NovaPay operation identifier.
   */
  public function getOperationId(): string {
    return $this->operation_id;
  }

  /**
   * Gets the HTTPS payment page URL.
   */
  public function getPaymentUrl(): string {
    return $this->payment_url;
  }

}
