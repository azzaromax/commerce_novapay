# Commerce NovaPay administration

This guide covers the environment-specific work required after the module has
been installed. Access to payment gateways requires the Commerce permission
`administer commerce_payment_gateway`.

## 1. Prepare private storage

Set Drupal's `file_private_path` to an absolute directory outside the web root.
It must be writable by the PHP process and persistent across application
restarts and deployments. For example, if `DRUPAL_ROOT` points to the public
document root:

```php
$settings['file_private_path'] = dirname(DRUPAL_ROOT) . '/private';
```

Do not use `public://`, a temporary directory, or a release directory that is
replaced on deployment. Verify the directory's ownership and permissions from
the same runtime user that executes Drupal before creating a live gateway.

## 2. Configure the payment-phone source

NovaPay can prefill checkout only from a configurable field of type
`Telephone` that has been explicitly designated for the integration.

For every Commerce customer profile type used by the store:

1. Open **Commerce > Configuration > Orders > Customer profiles**.
2. Open **Manage fields** for the profile type.
3. Add a field of type `Telephone`, or edit an existing field whose Drupal
   field type is already `Telephone`. Plain-text and numeric fields cannot be
   selected as the NovaPay phone source.
4. On the Telephone field edit form, select the module-provided checkbox
   **Use this field as the NovaPay payment phone** and save. Existing Telephone
   fields also receive this checkbox after the module is enabled.
5. Ensure the field is present on the checkout form and collects a Ukrainian
   number. Local and international Ukrainian formats are normalized to E.164.

The resolver checks a marked field on the order, then billing profile, then
customer account. Gateway validation specifically checks every Commerce
customer profile type and prevents saving until each has a marked Telephone
field. Merely having an unmarked telephone or plain-text field is insufficient.

## 3. Create a gateway

Open **Commerce > Configuration > Payment > Payment gateways**
(`/admin/commerce/config/payment-gateways`) and select **Add payment gateway**.

1. Enter a unique name and machine ID.
2. Select the **NovaPay** plugin.
3. Choose the stores and conditions where it may appear.
4. Optionally enable the NovaPay logo.
5. Configure the environment-local fields described below.
6. Enable and save the gateway.

To add another NovaPay gateway, repeat the process. Use a separate gateway for
another merchant account, store, transaction mode, or set of conditions. The
runtime profile is isolated by gateway UUID, so imported or duplicated gateway
configuration has to be initialized independently on every environment.

## 4. Configure test mode

Choose **Test** for local and sandbox validation. The module fixes the endpoint
to `https://api-qecom.novapay.ua`, Merchant ID to `2`, and loads the official
sandbox key fixtures packaged in `resources/test`. No key upload is necessary
or used.

Choose a transaction mode:

- **Direct** creates an immediate charge session;
- **Hold** creates an authorization that must later be captured or voided.

Set **Success redirect delay** to the number of seconds the NovaPay success
page waits before returning to Drupal. `0` omits automatic return timing. This
affects only sessions created after the change.

The public NovaPay sandbox may emit the legacy v1 callback signed with
RSA-SHA1. The module accepts that combination only in Test mode. It also
supports v2/RSA-SHA256 test fixtures.

## 5. Configure live mode and keys

Obtain production credentials through the merchant's NovaPay acquiring
onboarding:

- NovaPay assigns the **Merchant ID**;
- an authorized merchant user starts key generation in the NovaPay Acquiring3
  admin panel; NovaPay registers the matching merchant public key and downloads
  the **private key** to the user's device as a `.pem` file;
- NovaPay supplies its current **public verification key** through official
  acquiring documentation or the onboarding contact.

Verify the NovaPay public-key fingerprint through an approved, independent
channel before production use. Do not copy a key from an old ticket, chat, log,
or source repository.

In the gateway form:

1. Select **Live**.
2. Enter the Merchant ID exactly as NovaPay supplied it.
3. Upload the merchant private PEM and NovaPay public PEM in the same save.
4. Choose Direct or Hold and save.
5. Reopen the form and confirm **Keys installed**. Key contents are never
   displayed.

Leaving both upload fields empty preserves installed keys. Supplying only one
file is rejected. Rotation also requires both files in a single save and is
atomic. The merchant private key and NovaPay public key have different owners,
so they must not be tested as halves of one key pair.

Every local, demo, UAT, and production environment needs its own runtime
profile and appropriate credentials. A Drupal configuration import creates the
gateway entity but does not provision any runtime value or PEM file.

## 6. Recipient identifier

**Recipient identifier** is an optional EDRPOU or tax identifier for a payment
recipient different from the merchant. Leave it empty for the standard
single-merchant flow. Coordinate its value and supported operations with
NovaPay; item-level partial capture/refund scenarios may require it.

## 7. Callback and public URL

The gateway displays a read-only callback URL generated by Drupal Commerce,
normally `/payment/notify/{payment_gateway_id}`. The module includes it when
creating a NovaPay session; there is no separate editable callback setting.

For a development site that is not publicly reachable:

1. expose the site through a stable HTTPS tunnel such as ngrok;
2. configure Drupal so absolute URLs use that public host and HTTPS;
3. reopen the gateway and confirm its displayed callback URL uses the public
   host;
4. ensure the route is reachable without HTTP authentication, IP filtering, or
   a proxy redirect that changes the request body.

NovaPay signs the exact raw body. A proxy, WAF, or middleware must not rewrite
JSON before Drupal verifies it.

## 8. Operate payments

Manage a payment from its Commerce order or payment administration screen.
Operations are offered only when valid for the current payment state.

- **Capture** settles a held amount. Partial capture is supported when the
  NovaPay operation and order data permit it.
- **Void** cancels an authorization before capture.
- **Refund** displays refundable order items and quantities. All remaining
  quantities produce a full refund; a smaller selection produces a partial
  refund tied to the original NovaPay operation.
- **Check NovaPay payment status** performs read-only reconciliation after a
  missing or uncertain direct, hold, capture, or void result.
- **Check refund status** reconciles the one pending refund without sending a
  second refund request.

Wait for a signed callback or a successful status check before treating an
operation as financially final. Never repeat a write operation merely because
the browser timed out.

The public sandbox does not reliably execute refunds. An expected sandbox
`RefundError` is not evidence that production refunds are broken. Validate a
successful refund in an approved UAT/production account with an eligible recent
paid order and the conditions communicated by NovaPay.

## 9. Deploy and rotate safely

Export normal gateway configuration with Drupal, but provision runtime files
separately after import. Under the private scheme they are stored as:

```text
commerce_novapay/{gateway_uuid}/settings.json
commerce_novapay/{gateway_uuid}/private.pem
commerce_novapay/{gateway_uuid}/public.pem
```

Exclude these paths from Git, deployment packages, public file synchronization,
database dumps shared outside the approved environment, and configuration
sync. Restrict filesystem access to the PHP runtime and authorized operators.

After deployment or rotation, create a new low-value UAH session, confirm its
signed v2 callback, and check that no secret material appears in logs. Deleting
the gateway removes its local runtime directory; back up credentials first if
retention policy requires recovery.

## 10. Logging policy

Keep **Enable detailed logging** off unless sanitized diagnostic metadata is
needed. Even when enabled, never log or paste into tickets:

- private/public PEM contents or fingerprints not approved for disclosure;
- signatures, authorization tokens, cookies, or secret headers;
- raw callback/request/response bodies;
- card PAN, CVV, or other payment credentials;
- complete phone numbers, names, addresses, email, or other personal data;
- database rows or screenshots containing customer/payment data.

Use bounded identifiers and timestamps when correlating an incident.
