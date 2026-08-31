<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Plugin\Commerce\PaymentGateway;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\commerce_novapay\Credential\CredentialResolverInterface;
use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Credential\RsaKeyValidatorInterface;
use Drupal\commerce_novapay\Exception\InvalidRuntimeProfileException;
use Drupal\commerce_novapay\Logging\NovaPayLoggerInterface;
use Drupal\commerce_novapay\Payment\AuthorizationOperationManagerInterface;
use Drupal\commerce_novapay\Payment\PaymentStatusCheckManagerInterface;
use Drupal\commerce_novapay\Payment\PaymentStatusCheckResult;
use Drupal\commerce_novapay\Payment\RefundOperationManagerInterface;
use Drupal\commerce_novapay\Payment\RefundStatusCheckResult;
use Drupal\commerce_novapay\Payment\SupportsItemRefundsInterface;
use Drupal\commerce_novapay\Payment\SupportsPaymentStatusChecksInterface;
use Drupal\commerce_novapay\Phone\CustomerProfilePhoneInspectorInterface;
use Drupal\commerce_novapay\PluginForm\NovaPayCaptureForm;
use Drupal\commerce_novapay\PluginForm\NovaPayPaymentOffsiteForm;
use Drupal\commerce_novapay\PluginForm\NovaPayPaymentStatusForm;
use Drupal\commerce_novapay\PluginForm\NovaPayRefundForm;
use Drupal\commerce_novapay\PluginForm\NovaPayRefundStatusForm;
use Drupal\commerce_novapay\PluginForm\NovaPayVoidForm;
use Drupal\commerce_novapay\Postback\PostbackOutcome;
use Drupal\commerce_novapay\Postback\PostbackProcessorInterface;
use Drupal\commerce_novapay\Runtime\RuntimeConfiguration;
use Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface;
use Drupal\commerce_novapay\Runtime\RuntimeProfile;
use Drupal\commerce_novapay\Runtime\RuntimeProfileStorageInterface;
use Drupal\commerce_novapay\Runtime\TransactionMode;
use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_order\Entity\OrderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides the NovaPay off-site payment gateway.
 */
#[CommercePaymentGateway(
  id: 'novapay',
  label: new TranslatableMarkup('NovaPay'),
  display_label: new TranslatableMarkup('NovaPay'),
  modes: [
    'n/a' => new TranslatableMarkup('Environment-local'),
  ],
  forms: [
    'offsite-payment' => NovaPayPaymentOffsiteForm::class,
    'capture-payment' => NovaPayCaptureForm::class,
    'refund-payment' => NovaPayRefundForm::class,
    'check-refund-status' => NovaPayRefundStatusForm::class,
    'check-payment-status' => NovaPayPaymentStatusForm::class,
    'void-payment' => NovaPayVoidForm::class,
  ],
  payment_method_types: ['credit_card'],
  payment_type: 'novapay_payment',
  requires_billing_information: FALSE,
)]
final class NovaPay extends OffsitePaymentGatewayBase implements RuntimeConfigurationProviderInterface, SupportsAuthorizationsInterface, SupportsItemRefundsInterface, SupportsPaymentStatusChecksInterface, SupportsRefundsInterface {

  private const MAX_KEY_BYTES = 65536;

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default payment gateway configuration.
   */
  public function defaultConfiguration() {
    return [
      'display_logo' => TRUE,
    ] + parent::defaultConfiguration();
  }

  /**
   * The environment-local runtime profile storage.
   */
  private RuntimeProfileStorageInterface $runtimeProfileStorage;

  /**
   * The credential resolver.
   */
  private CredentialResolverInterface $credentialResolver;

  /**
   * The independent RSA key validator.
   */
  private RsaKeyValidatorInterface $keyValidator;

  /**
   * The current request stack used for raw upload fallback.
   */
  private RequestStack $requestStack;

  /**
   * The Commerce customer-profile phone inspector.
   */
  private CustomerProfilePhoneInspectorInterface $customerProfilePhoneInspector;

  /**
   * The signature-first NovaPay postback processor.
   */
  private PostbackProcessorInterface $postbackProcessor;

  /**
   * The sanitized NovaPay logger channel.
   */
  private NovaPayLoggerInterface $logger;

  /**
   * The serialized NovaPay capture/void operation manager.
   */
  private AuthorizationOperationManagerInterface $authorizationOperationManager;

  /**
   * The read-only NovaPay payment status reconciliation manager.
   */
  private PaymentStatusCheckManagerInterface $paymentStatusCheckManager;

  /**
   * The serialized NovaPay refund manager and confirmed item ledger.
   */
  private RefundOperationManagerInterface $refundOperationManager;

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param array<string, mixed> $plugin_definition
   *   The plugin definition.
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    $instance = parent::create(
      $container,
      $configuration,
      $plugin_id,
      $plugin_definition,
    );
    $instance->runtimeProfileStorage = $container->get(
      'commerce_novapay.runtime_profile_storage',
    );
    $instance->credentialResolver = $container->get(
      'commerce_novapay.credential_resolver',
    );
    $instance->keyValidator = $container->get(
      'commerce_novapay.rsa_key_validator',
    );
    $instance->requestStack = $container->get('request_stack');
    $instance->customerProfilePhoneInspector = $container->get(
      'commerce_novapay.customer_profile_phone_inspector',
    );
    $instance->postbackProcessor = $container->get(
      'commerce_novapay.postback.processor',
    );
    $instance->logger = $container->get(
      'commerce_novapay.logger',
    );
    $instance->authorizationOperationManager = $container->get(
      'commerce_novapay.authorization_operation_manager',
    );
    $instance->paymentStatusCheckManager = $container->get(
      'commerce_novapay.payment_status_check_manager',
    );
    $instance->refundOperationManager = $container->get(
      'commerce_novapay.refund_operation_manager',
    );

    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<array-key, mixed> $form
   *   The plugin configuration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   *
   * @return array<string, mixed>
   *   The completed plugin configuration form.
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $form_state,
  ) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['display_logo'] = [
      '#type' => 'checkbox',
      '#title' => $this->t(
        'Display the NovaPay logo instead of the payment method name',
      ),
      '#description' => $this->t(
        'When disabled, checkout displays the configured display name.',
      ),
      '#default_value' => $this->configuration['display_logo'] ?? TRUE,
    ];
    $profile = NULL;
    $profile_error = FALSE;
    $has_live_keys = NULL;

    try {
      $gateway_uuid = $this->getGatewayUuid($form_state);
      $profile = $this->runtimeProfileStorage->load($gateway_uuid);
      $has_live_keys = $this->runtimeProfileStorage
        ->hasValidLiveKeys($gateway_uuid);
    }
    catch (\RuntimeException) {
      $profile_error = TRUE;
    }

    $runtime_mode = $profile?->getMode() ?? NovaPayMode::Test;
    $form['runtime_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Environment-local NovaPay settings'),
      '#open' => TRUE,
      '#description' => $this->t(
        'These values are stored in the Drupal private filesystem and are not exported with configuration.',
      ),
    ];
    if ($profile_error) {
      $form['runtime_settings']['profile_warning'] = [
        '#type' => 'item',
        '#markup' => $this->t(
          'The current local settings are unavailable. Saving requires writable private storage.',
        ),
      ];
    }

    $form['runtime_settings']['runtime_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('NovaPay API mode'),
      '#options' => [
        NovaPayMode::Test->value => $this->t('Test'),
        NovaPayMode::Live->value => $this->t('Live'),
      ],
      '#default_value' => $runtime_mode->value,
      '#required' => TRUE,
    ];
    $form['runtime_settings']['test_credentials'] = [
      '#type' => 'item',
      '#title' => $this->t('Sandbox credentials'),
      '#markup' => $this->t(
        'Test mode always uses the packaged NovaPay sandbox keys and Merchant ID 2.',
      ),
      '#states' => [
        'visible' => [
          ':input[name="configuration[novapay][runtime_settings][runtime_mode]"]' => [
            'value' => NovaPayMode::Test->value,
          ],
        ],
      ],
    ];

    $form['runtime_settings']['live_credentials'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="configuration[novapay][runtime_settings][runtime_mode]"]' => [
            'value' => NovaPayMode::Live->value,
          ],
        ],
      ],
    ];
    $form['runtime_settings']['live_credentials']['merchant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant ID'),
      '#default_value' => $profile?->getMerchantId() ?? '',
      '#maxlength' => 128,
    ];
    $form['runtime_settings']['live_credentials']['keys'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Live keys'),
    ];
    $form['runtime_settings']['live_credentials']['keys']['key_status'] =
      $this->buildLiveKeyStatus($has_live_keys);
    $form['runtime_settings']['live_credentials']['keys']['private_key_upload'] = [
      '#type' => 'file',
      '#parents' => ['novapay_private_key_upload'],
      '#title' => $this->t('Merchant private key'),
      '#description' => $this->t(
        'Select this file together with the NovaPay public key to install or replace both keys.',
      ),
      '#accept' => '.pem,.key,text/plain,application/x-pem-file',
    ];
    $form['runtime_settings']['live_credentials']['keys']['public_key_upload'] = [
      '#type' => 'file',
      '#parents' => ['novapay_public_key_upload'],
      '#title' => $this->t('NovaPay public key'),
      '#description' => $this->t(
        'Select this file together with the merchant private key to install or replace both keys.',
      ),
      '#accept' => '.pem,.key,text/plain,application/x-pem-file',
    ];

    $form['runtime_settings']['transaction_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Transaction mode'),
      '#options' => [
        TransactionMode::Direct->value => $this->t('Direct'),
        TransactionMode::Hold->value => $this->t('Hold'),
      ],
      '#default_value' => $profile?->getTransactionMode()->value
        ?? TransactionMode::Direct->value,
      '#required' => TRUE,
    ];
    $form['runtime_settings']['recipient_identifier'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Recipient identifier'),
      '#description' => $this->t(
        'Optional EDRPOU or tax identifier of a payment recipient different from the merchant.',
      ),
      '#default_value' => $profile?->getRecipientIdentifier() ?? '',
      '#maxlength' => 128,
    ];
    $form['runtime_settings']['success_redirect_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Success redirect delay'),
      '#description' => $this->t(
        'Seconds NovaPay displays its success page before automatically returning the customer to checkout. Use 0 to omit the automatic redirect timeout. Changes apply only to newly created NovaPay sessions.',
      ),
      '#default_value' => $profile?->getSuccessRedirectTimeout()
        ?? RuntimeProfile::DEFAULT_SUCCESS_REDIRECT_TIMEOUT,
      '#min' => 0,
      '#max' => RuntimeProfile::MAX_SUCCESS_REDIRECT_TIMEOUT,
      '#step' => 1,
      '#required' => TRUE,
    ];
    $form['runtime_settings']['logging_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable detailed logging'),
      '#description' => $this->t(
        'Only sanitized request and response metadata may be logged.',
      ),
      '#default_value' => $profile?->isLoggingEnabled() ?? FALSE,
    ];

    $form['notify_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Postback URL'),
      '#default_value' => $this->getNotifyUrlValue($form_state),
      '#disabled' => TRUE,
      '#description' => $this->t(
        'Commerce generates this URL. It cannot be entered manually.',
      ),
    ];

    return $form;
  }

  /**
   * Builds a secret-free explanation of the current live key state.
   *
   * @param bool|null $has_live_keys
   *   Whether both valid live keys are installed, or NULL when unavailable.
   *
   * @return array<string, mixed>
   *   The key status form element.
   */
  private function buildLiveKeyStatus(?bool $has_live_keys): array {
    if ($has_live_keys === TRUE) {
      $title = $this->t('Keys installed');
      $message = $this->t(
        'Both live keys are installed for this payment gateway. Leave both upload fields empty to keep them unchanged. To replace them, upload both files together. Changing the Merchant ID does not replace the keys.',
      );
      $message_type = 'status';
    }
    elseif ($has_live_keys === FALSE) {
      $title = $this->t('Keys not installed');
      $message = $this->t(
        'Live keys are not installed. Upload both key files together before saving live mode. After installation, leave both upload fields empty to keep the keys unchanged. Changing the Merchant ID does not replace the keys.',
      );
      $message_type = 'warning';
    }
    else {
      $title = $this->t('Key status unavailable');
      $message = $this->t(
        'The live key status is unavailable because the local settings could not be read. Existing keys are never displayed. Upload both files together only when installing or replacing the keys.',
      );
      $message_type = 'warning';
    }

    return [
      '#type' => 'item',
      '#title' => $title,
      '#markup' => $message,
      '#wrapper_attributes' => [
        'class' => ['messages', 'messages--' . $message_type],
        'role' => 'status',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * @param array<array-key, mixed> $form
   *   The plugin configuration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   */
  public function validateConfigurationForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    parent::validateConfigurationForm($form, $form_state);
    $values = $this->getRuntimeValues($form, $form_state);
    $mode = NovaPayMode::tryFrom((string) ($values['runtime_mode'] ?? ''));
    $transaction_mode = TransactionMode::tryFrom(
      (string) ($values['transaction_mode'] ?? ''),
    );

    if ($mode === NULL) {
      $form_state->setError(
        $form['runtime_settings']['runtime_mode'],
        $this->t('Select a valid NovaPay API mode.'),
      );
    }
    if ($transaction_mode === NULL) {
      $form_state->setError(
        $form['runtime_settings']['transaction_mode'],
        $this->t('Select a valid transaction mode.'),
      );
    }

    $success_redirect_timeout = filter_var(
      $values['success_redirect_timeout'] ?? NULL,
      FILTER_VALIDATE_INT,
    );
    if (
      $success_redirect_timeout === FALSE
      || $success_redirect_timeout < 0
      || $success_redirect_timeout > RuntimeProfile::MAX_SUCCESS_REDIRECT_TIMEOUT
    ) {
      $form_state->setError(
        $form['runtime_settings']['success_redirect_timeout'],
        $this->t('Enter a success redirect delay from 0 to @maximum seconds.', [
          '@maximum' => RuntimeProfile::MAX_SUCCESS_REDIRECT_TIMEOUT,
        ]),
      );
    }

    $this->validateCustomerProfilePhones($form, $form_state);

    $live_values = $values['live_credentials'] ?? [];
    $merchant_id = is_array($live_values)
      ? trim((string) ($live_values['merchant_id'] ?? ''))
      : '';
    if ($mode === NovaPayMode::Live && $merchant_id === '') {
      $form_state->setError(
        $form['runtime_settings']['live_credentials']['merchant_id'],
        $this->t('Merchant ID is required in live mode.'),
      );
    }

    try {
      $gateway_uuid = $this->getGatewayUuid($form_state);
      $this->runtimeProfileStorage->assertWritable($gateway_uuid);
      if ($mode === NovaPayMode::Live) {
        $this->validateLiveUploads($form, $form_state, $gateway_uuid);
      }
    }
    catch (InvalidRuntimeProfileException $exception) {
      $form_state->setError(
        $form['runtime_settings'],
        $this->t('NovaPay local settings cannot be saved securely.'),
      );
    }
  }

  /**
   * Validates phone sources on all Commerce customer profile types.
   *
   * @param array<array-key, mixed> $form
   *   The plugin configuration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   */
  private function validateCustomerProfilePhones(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    $readiness = $this->customerProfilePhoneInspector->inspect();
    if ($readiness->isReady()) {
      return;
    }

    $messages = [];
    if ($readiness->getMissingTelephone() !== []) {
      $messages[] = (string) $this->t(
        'Add a Telephone field to these Commerce customer profile types and mark it as the NovaPay payment phone: @types.',
        ['@types' => implode(', ', $readiness->getMissingTelephone())],
      );
    }
    if ($readiness->getUnmarkedTelephone() !== []) {
      $messages[] = (string) $this->t(
        'A Telephone field exists on these Commerce customer profile types. Edit its field settings and select “Use this field as the NovaPay payment phone”: @types.',
        ['@types' => implode(', ', $readiness->getUnmarkedTelephone())],
      );
    }

    $form_state->setError(
      $form['runtime_settings'],
      implode(' ', $messages),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @param array<array-key, mixed> $form
   *   The plugin configuration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   */
  public function submitConfigurationForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    parent::submitConfigurationForm($form, $form_state);
    if ($form_state->getErrors()) {
      return;
    }

    $configuration_values = $form_state->getValue($form['#parents']);
    if (is_array($configuration_values)) {
      $this->configuration['display_logo'] =
        !empty($configuration_values['display_logo']);
    }

    $values = $this->getRuntimeValues($form, $form_state);
    $mode = NovaPayMode::from((string) $values['runtime_mode']);
    $live_values = $values['live_credentials'] ?? [];
    $merchant_id = is_array($live_values)
      ? trim((string) ($live_values['merchant_id'] ?? ''))
      : '';
    $profile = new RuntimeProfile(
      $mode,
      $mode === NovaPayMode::Live
        ? $merchant_id
        : NULL,
      TransactionMode::from((string) $values['transaction_mode']),
      trim((string) ($values['recipient_identifier'] ?? '')),
      !empty($values['logging_enabled']),
      (int) $values['success_redirect_timeout'],
    );

    $private_key = NULL;
    $public_key = NULL;
    if ($mode === NovaPayMode::Live) {
      $private_key = $this->readUploadedPem(
        $this->getUploadedFile($form_state, 'novapay_private_key_upload'),
      );
      $public_key = $this->readUploadedPem(
        $this->getUploadedFile($form_state, 'novapay_public_key_upload'),
      );
    }

    $this->runtimeProfileStorage->save(
      $this->getGatewayUuid($form_state),
      $profile,
      $private_key,
      $public_key,
    );
  }

  /**
   * Resolves settings, credentials, and the matching API endpoint.
   */
  public function getRuntimeConfiguration(): RuntimeConfiguration {
    return $this->credentialResolver->resolveRuntimeConfiguration(
      $this->getGatewayUuid(),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function canCapturePayment(PaymentInterface $payment): bool {
    return $this->authorizationOperationManager->canCapture($payment);
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(
    PaymentInterface $payment,
    ?Price $amount = NULL,
  ): void {
    $this->authorizationOperationManager->capture($payment, $this, $amount);
  }

  /**
   * {@inheritdoc}
   */
  public function canVoidPayment(PaymentInterface $payment): bool {
    return $this->authorizationOperationManager->canVoid($payment);
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment): void {
    $this->authorizationOperationManager->void($payment, $this);
  }

  /**
   * {@inheritdoc}
   */
  public function canRefundPayment(PaymentInterface $payment): bool {
    return $this->refundOperationManager->canRefund($payment);
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(
    PaymentInterface $payment,
    ?Price $amount = NULL,
  ): void {
    $balance = $payment->getBalance();
    if (
      $amount !== NULL
      && (!$balance instanceof Price || !$amount->equals($balance))
    ) {
      throw InvalidRequestException::createForPayment(
        $payment,
        (string) $this->t('Use the NovaPay item quantities for a partial refund.'),
      );
    }
    $this->refundOperationManager->refund($payment, $this);
  }

  /**
   * {@inheritdoc}
   */
  public function getRefundableItems(PaymentInterface $payment): array {
    return $this->refundOperationManager->getRefundableItems($payment);
  }

  /**
   * {@inheritdoc}
   */
  public function refundItems(
    PaymentInterface $payment,
    array $quantities,
  ): void {
    $this->refundOperationManager->refund($payment, $this, $quantities);
  }

  /**
   * {@inheritdoc}
   */
  public function canCheckRefundStatus(PaymentInterface $payment): bool {
    return $this->refundOperationManager->canCheckStatus($payment);
  }

  /**
   * {@inheritdoc}
   */
  public function checkRefundStatus(
    PaymentInterface $payment,
  ): RefundStatusCheckResult {
    return $this->refundOperationManager->checkStatus($payment, $this);
  }

  /**
   * Returns whether the payment can be reconciled with a read-only status call.
   */
  public function canCheckPaymentStatus(PaymentInterface $payment): bool {
    return $this->paymentStatusCheckManager->canCheckStatus($payment);
  }

  /**
   * Reconciles a payment with NovaPay without submitting a financial command.
   */
  public function checkPaymentStatus(
    PaymentInterface $payment,
  ): PaymentStatusCheckResult {
    return $this->paymentStatusCheckManager->checkStatus($payment, $this);
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, array<string, mixed>>
   *   Payment operations keyed by operation ID.
   */
  public function buildPaymentOperations(PaymentInterface $payment): array {
    $operations = parent::buildPaymentOperations($payment);
    $operations['check_payment_status'] = [
      'title' => $this->t('Check NovaPay payment status'),
      'page_title' => $this->t('Check NovaPay payment status'),
      'plugin_form' => 'check-payment-status',
      'access' => $this->canCheckPaymentStatus($payment),
    ];
    $operations['check_refund_status'] = [
      'title' => $this->t('Check refund status'),
      'page_title' => $this->t('Check NovaPay refund status'),
      'plugin_form' => 'check-refund-status',
      'access' => $this->canCheckRefundStatus($payment),
    ];
    return $operations;
  }

  /**
   * {@inheritdoc}
   *
   * Browser returns are never a source of financial payment state.
   */
  public function onReturn(OrderInterface $order, Request $request): void {}

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request): Response {
    $gateway = $this->parentEntity;
    $raw_body = $request->getContent();
    try {
      $result = $this->postbackProcessor->process(
        $gateway,
        $this,
        $raw_body,
        (string) $request->headers->get('x-sign', ''),
      );
    }
    catch (\Throwable $exception) {
      $this->logger->logError('postback_processing_failed', [
        'gateway' => (string) $gateway->id(),
        'source' => $exception::class,
      ]);
      return new Response('', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    $version = $result->getVersion();
    $status = $result->getStatus();
    $context = [
      'gateway' => (string) $gateway->id(),
      'outcome' => $result->getOutcome()->value,
      'version' => $version === NULL ? 'unknown' : $version->value,
      'status' => $status === NULL ? 'unknown' : $status->value,
      'diagnostics' => $result->getDiagnostics(),
    ];
    if ($result->getOutcome() === PostbackOutcome::InvalidSignature) {
      $this->logger->logDetailed(
        $result->isDetailedLoggingEnabled(),
        'postback',
        $context + [
          'payload_bytes' => strlen($raw_body),
          'payload_sha256' => hash('sha256', $raw_body),
        ],
      );
    }
    else {
      $this->logger->logDetailedJson(
        $result->isDetailedLoggingEnabled(),
        'postback',
        $raw_body,
        $context,
      );
    }
    if (in_array(
      $result->getOutcome(),
      [
        PostbackOutcome::InvalidSignature,
        PostbackOutcome::InvalidPayload,
      ],
      TRUE,
    )) {
      $this->logger->logError('postback_rejected', [
        'gateway' => $context['gateway'],
        'outcome' => $context['outcome'],
      ]);
    }

    return new Response('', match ($result->getOutcome()) {
      PostbackOutcome::InvalidSignature => Response::HTTP_FORBIDDEN,
      PostbackOutcome::InvalidPayload => Response::HTTP_BAD_REQUEST,
      PostbackOutcome::Applied,
      PostbackOutcome::Duplicate,
      PostbackOutcome::Ignored,
      PostbackOutcome::UnknownPayment => Response::HTTP_OK,
    });
  }

  /**
   * Validates optional live rotation uploads.
   *
   * @param array<array-key, mixed> $form
   *   The plugin configuration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   * @param string $gateway_uuid
   *   The Commerce payment gateway UUID.
   */
  private function validateLiveUploads(
    array &$form,
    FormStateInterface $form_state,
    string $gateway_uuid,
  ): void {
    $private_upload = $this->getUploadedFile(
      $form_state,
      'novapay_private_key_upload',
    );
    $public_upload = $this->getUploadedFile(
      $form_state,
      'novapay_public_key_upload',
    );

    if (($private_upload === NULL) !== ($public_upload === NULL)) {
      $message = $this->t('Upload both live key files together.');
      $form_state->setError(
        $form['runtime_settings']['live_credentials']['keys']['private_key_upload'],
        $message,
      );
      $form_state->setError(
        $form['runtime_settings']['live_credentials']['keys']['public_key_upload'],
        $message,
      );
      return;
    }

    if ($private_upload === NULL && $public_upload === NULL) {
      if (!$this->runtimeProfileStorage->hasValidLiveKeys($gateway_uuid)) {
        $form_state->setError(
          $form['runtime_settings']['live_credentials']['keys']['private_key_upload'],
          $this->t('Upload both live key files.'),
        );
      }
      return;
    }

    try {
      $private_key = $this->readUploadedPem($private_upload);
      $public_key = $this->readUploadedPem($public_upload);
      if ($private_key === NULL || $public_key === NULL) {
        throw InvalidRuntimeProfileException::incompleteKeyUpload();
      }
      $this->keyValidator->validatePrivateKey($private_key);
      $this->keyValidator->validatePublicKey($public_key);
    }
    catch (\RuntimeException) {
      $form_state->setError(
        $form['runtime_settings']['live_credentials']['keys'],
        $this->t('Upload valid RSA private and public PEM files.'),
      );
    }
  }

  /**
   * Gets the submitted values inside the runtime settings container.
   *
   * @param array<array-key, mixed> $form
   *   The plugin configuration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   *
   * @return array<string, mixed>
   *   Submitted runtime values.
   */
  private function getRuntimeValues(
    array $form,
    FormStateInterface $form_state,
  ): array {
    $parents = $form['#parents'] ?? [];
    $values = $form_state->getValue($parents);
    if (!is_array($values)) {
      return [];
    }

    $runtime_values = $values['runtime_settings'] ?? NULL;
    return is_array($runtime_values) ? $runtime_values : [];
  }

  /**
   * Gets the first uploaded file from a Form API file element.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   * @param string $key
   *   The top-level upload element key.
   */
  private function getUploadedFile(
    FormStateInterface $form_state,
    string $key,
  ): ?UploadedFile {
    $uploads = $form_state->getValue($key);
    if ($uploads instanceof UploadedFile) {
      return $uploads;
    }
    if (is_array($uploads)) {
      foreach ($uploads as $upload) {
        if ($upload instanceof UploadedFile) {
          return $upload;
        }
      }
    }

    // Drupal Core's single-file value callback casts the Symfony
    // UploadedFile object to an array in some supported Core/Symfony
    // combinations. Read the exact allow-listed field from the request as a
    // compatibility fallback; size and upload validity are checked separately.
    $request = $this->requestStack->getCurrentRequest();
    $request_uploads = $request?->files->get('files', []);
    if (!is_array($request_uploads)) {
      return NULL;
    }

    $upload = $request_uploads[$key] ?? NULL;
    if ($upload instanceof UploadedFile) {
      return $upload;
    }
    if (is_array($upload)) {
      foreach ($upload as $candidate) {
        if ($candidate instanceof UploadedFile) {
          return $candidate;
        }
      }
    }

    return NULL;
  }

  /**
   * Reads a bounded valid HTTP upload without persisting it.
   */
  private function readUploadedPem(
    ?UploadedFile $upload,
  ): ?string {
    if ($upload === NULL) {
      return NULL;
    }

    $size = $upload->getSize();
    if (
      !$upload->isValid()
      || !is_int($size)
      || $size < 1
      || $size > self::MAX_KEY_BYTES
    ) {
      throw InvalidRuntimeProfileException::incompleteKeyUpload();
    }

    $contents = @file_get_contents($upload->getPathname());
    if (!is_string($contents) || strlen($contents) !== $size) {
      throw InvalidRuntimeProfileException::incompleteKeyUpload();
    }

    return $contents;
  }

  /**
   * Gets the current Commerce payment gateway UUID.
   */
  private function getGatewayUuid(
    ?FormStateInterface $form_state = NULL,
  ): string {
    // The Commerce plugin-configuration inline form creates a standalone
    // plugin instance, so it has no parent entity while the gateway admin
    // form is being built or submitted. Resolve that entity from the form
    // object for admin operations.
    $form_gateway = $this->getFormGateway($form_state);
    if ($form_gateway !== NULL) {
      $uuid = $form_gateway->uuid();
      if (is_string($uuid) && $uuid !== '') {
        return $uuid;
      }
    }

    // Commerce documents this property as non-null, but its own base class
    // leaves it unavailable when a plugin is configured without a parent.
    // @phpstan-ignore-next-line isset.property
    if (!isset($this->parentEntity)) {
      throw InvalidRuntimeProfileException::invalidProfile();
    }

    $uuid = $this->parentEntity->uuid();
    if (!is_string($uuid) || $uuid === '') {
      throw InvalidRuntimeProfileException::invalidProfile();
    }

    return $uuid;
  }

  /**
   * Gets a safe read-only callback URL value.
   */
  private function getNotifyUrlValue(
    FormStateInterface $form_state,
  ): string {
    $form_gateway = $this->getFormGateway($form_state);
    if ($form_gateway !== NULL && $form_gateway->id()) {
      return Url::fromRoute(
        'commerce_payment.notify',
        ['commerce_payment_gateway' => $form_gateway->id()],
        ['absolute' => TRUE],
      )->toString();
    }

    // @phpstan-ignore-next-line isset.property
    if (!isset($this->parentEntity) || !$this->parentEntity->id()) {
      return (string) $this->t('Available after the gateway is saved.');
    }

    return $this->getNotifyUrl()->toString();
  }

  /**
   * Gets the gateway entity from an administrative entity form.
   */
  private function getFormGateway(
    ?FormStateInterface $form_state,
  ): ?PaymentGatewayInterface {
    $form_object = $form_state?->getFormObject();
    if (!$form_object instanceof EntityFormInterface) {
      return NULL;
    }

    $entity = $form_object->getEntity();
    return $entity instanceof PaymentGatewayInterface ? $entity : NULL;
  }

}
