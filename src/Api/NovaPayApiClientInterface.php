<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Api;

use Drupal\commerce_novapay\Api\Dto\Request\AddPaymentRequest;
use Drupal\commerce_novapay\Api\Dto\Request\CompleteHoldRequest;
use Drupal\commerce_novapay\Api\Dto\Request\CreateSessionRequest;
use Drupal\commerce_novapay\Api\Dto\Request\VoidRequest;
use Drupal\commerce_novapay\Api\Dto\Response\AcknowledgementResponse;
use Drupal\commerce_novapay\Api\Dto\Response\PaymentResponse;
use Drupal\commerce_novapay\Api\Dto\Response\SessionResponse;
use Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface;

/**
 * Sends signed requests to the NovaPay acquiring API.
 */
interface NovaPayApiClientInterface {

  /**
   * Creates a NovaPay payment session.
   */
  public function createSession(
    RuntimeConfigurationProviderInterface $gateway,
    CreateSessionRequest $request,
  ): SessionResponse;

  /**
   * Adds a direct or hold payment to a session.
   */
  public function addPayment(
    RuntimeConfigurationProviderInterface $gateway,
    AddPaymentRequest $request,
  ): PaymentResponse;

  /**
   * Completes all or part of an authorized hold.
   */
  public function completeHold(
    RuntimeConfigurationProviderInterface $gateway,
    CompleteHoldRequest $request,
  ): AcknowledgementResponse;

  /**
   * Voids a hold or refunds all or part of a payment.
   */
  public function voidPayment(
    RuntimeConfigurationProviderInterface $gateway,
    VoidRequest $request,
  ): AcknowledgementResponse;

}
