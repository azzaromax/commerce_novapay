<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Order;

use Drupal\commerce_novapay\Api\Dto\Request\AddPaymentRequest;
use Drupal\commerce_novapay\Api\Dto\Request\CreateSessionRequest;
use Drupal\commerce_novapay\Runtime\TransactionMode;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;

/**
 * Builds NovaPay request DTOs from a Commerce order.
 */
interface OrderPayloadBuilderInterface {

  /**
   * Builds customer, callback, and correlation data for a new session.
   */
  public function buildSessionRequest(
    OrderInterface $order,
    PaymentGatewayInterface $gateway,
    string $callback_url,
    string $success_url,
    string $fail_url,
  ): CreateSessionRequest;

  /**
   * Builds amount and product data for a NovaPay payment.
   */
  public function buildPaymentRequest(
    OrderInterface $order,
    string $session_id,
    TransactionMode $transaction_mode,
    string $recipient_identifier = '',
  ): AddPaymentRequest;

}
