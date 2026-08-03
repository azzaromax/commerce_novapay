<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\PluginForm;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_novapay\Checkout\CheckoutCoordinatorInterface;
use Drupal\commerce_novapay\Exception\CheckoutPreparationException;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds the locked NovaPay GET redirect from the Payment Process pane.
 */
final class NovaPayPaymentOffsiteForm extends PaymentOffsiteForm implements ContainerInjectionInterface {

  /**
   * Constructs the NovaPay off-site payment form.
   */
  public function __construct(
    private readonly CheckoutCoordinatorInterface $checkout_coordinator,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('commerce_novapay.checkout_coordinator'),
      $container->get('logger.channel.commerce_novapay'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @param array<array-key, mixed> $form
   *   The plugin form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return array<array-key, mixed>
   *   The redirect form.
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $form_state,
  ): array {
    $form = parent::buildConfigurationForm($form, $form_state);
    $payment = $this->entity;
    if (!$payment instanceof PaymentInterface) {
      throw new \InvalidArgumentException(
        'The NovaPay off-site form requires a Commerce payment.',
      );
    }

    try {
      $redirect_url = $this->checkout_coordinator->prepareRedirect(
        $payment,
        (string) $form['#return_url'],
        (string) $form['#cancel_url'],
      );
    }
    catch (\Throwable $exception) {
      $diagnostics = $exception instanceof CheckoutPreparationException
        ? $exception
        : CheckoutPreparationException::fromThrowable(
          'offsite_form',
          $exception,
        );
      $context = [
        '@order_id' => (string) ($payment->getOrderId() ?? 'unknown'),
        '@stage' => $diagnostics->getStage(),
        '@source' => $diagnostics->getSourceClass(),
        '@http_status' => (string) ($diagnostics->getHttpStatus() ?? 'none'),
        '@api_detail' => $diagnostics->getApiDetail() ?? 'none',
      ];
      $this->logger->error(
        'NovaPay checkout preparation failed for order @order_id at @stage: @source (HTTP @http_status, API @api_detail).',
        $context,
      );
      throw PaymentGatewayException::createForPayment(
        $payment,
        (string) $this->t(
          'NovaPay checkout could not be prepared. Please try again later.',
        ),
      );
    }

    return $this->buildRedirectForm(
      $form,
      $form_state,
      $redirect_url,
      [],
      self::REDIRECT_GET,
    );
  }

}
