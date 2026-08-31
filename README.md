<p align="center">
  <img src="assets/images/logo.svg" alt="NovaPay" width="220">
</p>

# Commerce NovaPay

Commerce NovaPay adds NovaPay acquiring to Drupal Commerce 3 on Drupal 11. It
creates off-site payment sessions, verifies signed callbacks, and supports
direct payments, holds, captures, voids, and full or item-level refunds.

## Documentation

- [Installation and gateway configuration](#installation-on-a-drupal-site)
- [Administration guide](docs/ADMINISTRATION.md)
- [Troubleshooting](docs/TROUBLESHOOTING.md)

> [!IMPORTANT]
> **Complete these steps before using the NovaPay gateway:**
>
> 1. Every Commerce customer profile type used by the store must have a field
>    whose Drupal field type is **Telephone**. You can add a new field or reuse
>    an existing Telephone field; plain-text and numeric fields are not
>    supported as the NovaPay phone source.
> 2. Edit the chosen Telephone field. The module adds the checkbox
>    **Use this field as the NovaPay payment phone** to its field settings.
>    Select this checkbox and save the field. An existing Telephone field is
>    not used until this option is enabled, and the gateway cannot be saved
>    without a designated field.
> 3. Configure a persistent Drupal private filesystem outside the public web
>    root. Live settings and PEM files are stored there.
> 4. Use `UAH` for payable orders and expose the generated callback URL through
>    public HTTPS.
> 5. Start with **Test** mode. **Live** mode requires the merchant's own Merchant
>    ID and both production PEM files obtained through NovaPay onboarding.
>
> See the [administration guide](docs/ADMINISTRATION.md) for the complete setup.

## Features

- off-site NovaPay checkout for UAH orders;
- Direct and Hold transaction modes;
- full and partial capture, void, and item-aware refunds;
- signed, idempotent postback processing and read-only status recovery;
- isolated Test and Live credentials for each payment gateway;
- Ukrainian interface translations and optional NovaPay checkout branding.

## Requirements and dependencies

- PHP 8.3 with OpenSSL;
- a Composer-managed Drupal 11 site;
- Drupal Commerce 3.3 or newer;
- a writable, persistent Drupal private filesystem outside the web root;
- an HTTPS site URL reachable by NovaPay.

The Composer package declares Drupal Core, Commerce, Entity API, Profile, and
the PHP OpenSSL extension. When the module is enabled, Drupal also requires and
enables the modules it uses directly:

- Field and Telephone from Drupal Core;
- Entity API and Profile;
- Commerce Checkout, Order, Payment, and Price.

The HTTP client, PSR interfaces, and Symfony components used by the module are
provided by the supported Drupal Core version. No separate JavaScript package
manager or external PHP SDK is required.

NovaPay orders must use `UAH`. The module rejects another currency before a
payment session is created.

## Installation on a Drupal site

Run Composer commands from the Drupal project's Composer root, not from the
module directory. The project must use `composer/installers` or an equivalent
Drupal Composer template.

Make the package available through the Composer repository supplied with the
module release, then install it:

```bash
composer require drupal/commerce_novapay:^1.0
```

Composer installs the module and all contributed dependencies into the paths
configured by the Drupal project. Do not copy the module's own `vendor`
directory into the site.

If the release is supplied only as source code, first install its contributed
dependencies in the site's Composer root:

```bash
composer require drupal/commerce:^3.3 drupal/entity:^1.0 drupal/profile:^1.2
```

Then extract the module to
`<docroot>/modules/custom/commerce_novapay`, so that
`commerce_novapay.info.yml` is directly inside that directory. Composer package
installation is preferred because it validates and resolves dependencies.

Configure the private filesystem before enabling the module. For example, in
an environment-specific `settings.php`:

```php
$settings['file_private_path'] = dirname(DRUPAL_ROOT) . '/private';
```

The directory must be outside the public document root, writable by the PHP
process, included in the site's backup strategy, and persistent across
deployments. Enable the module and rebuild caches:

```bash
vendor/bin/drush en commerce_novapay -y
vendor/bin/drush updb -y
vendor/bin/drush cr
```

Without Drush, enable **Commerce NovaPay** at `/admin/modules`, then run the
site's normal database-update and cache-rebuild procedure. Drupal enables the
declared module dependencies automatically; a missing or incompatible package
is reported before enablement.

## Quick test setup

1. For every Commerce customer profile type used by the store, add a field of
   type **Telephone** or edit an existing Telephone field. Select
   **Use this field as the NovaPay payment phone** in that field's settings.
2. Open **Commerce > Configuration > Payment > Payment gateways** or
   `/admin/commerce/config/payment-gateways`.
3. Select **Add payment gateway**, enter a unique name and machine ID, and
   choose the **NovaPay** plugin.
4. Select **Test** API mode and either **Direct** or **Hold** transaction mode.
   Test mode automatically uses the packaged official sandbox fixtures and
   Merchant ID `2`; do not upload keys.
5. Limit the gateway to the intended store and conditions, enable it, and
   save.
6. Place a UAH order containing a valid Ukrainian phone number and complete
   payment on the NovaPay sandbox page.

The generated callback URL is displayed read-only in the gateway form. It is
sent to NovaPay automatically for each session. A development site that is not
publicly reachable needs a stable HTTPS tunnel, such as ngrok, and correct
trusted-host/reverse-proxy configuration so Drupal generates external HTTPS
URLs. Do not configure a callback URL manually in the module.

## Adding another payment gateway

Create another entity at
`/admin/commerce/config/payment-gateways/add`, select **NovaPay**, and give it
a distinct label and machine ID. Configure stores and conditions so that only
the intended gateway is offered at checkout.

Each gateway UUID owns an isolated environment-local runtime profile and key
directory. Duplicating or importing a gateway configuration does not transfer
its Merchant ID, mode, runtime options, or PEM files. Open and save every new
or imported NovaPay gateway separately in each environment.

Use separate gateway entities when stores use different NovaPay merchant
accounts, transaction modes, or availability conditions. Do not reuse a
production gateway profile for local, demo, or UAT.

## Test and live modes

| | Test | Live |
|---|---|---|
| API | NovaPay sandbox (`api-qecom.novapay.ua`) | NovaPay production (`api-ecom.novapay.ua`) |
| Merchant ID | Packaged ID `2` | Assigned to the merchant by NovaPay |
| Keys | Packaged sandbox fixtures | Uploaded separately on this environment |
| Callback signatures | v2/SHA-256 plus sandbox-only legacy v1/SHA-1 compatibility | v2/SHA-256 only |
| Intended use | Local development and functional checks | Approved UAT/production traffic |
| Refund behavior | Sandbox may reject or not complete refunds | Validate with an eligible production UAT order |

Test mode never uses uploaded production credentials. Live mode never falls
back to the bundled sandbox keys. Switching modes changes the endpoint and
credential source for newly created sessions; it does not migrate existing
payments.

## Production credentials

Three values have different origins:

1. **Merchant ID** — NovaPay assigns it during acquiring onboarding.
2. **Merchant private key** — an authorized merchant user starts key generation
   in the NovaPay Acquiring3 admin panel. NovaPay registers the corresponding
   public key and downloads the private key as a `.pem` file to that user's
   device. Follow the current official
   [Acquiring3 key-generation guide](https://nova-pay.atlassian.net/wiki/spaces/EXT/pages/694779954/Acquiring3#%D0%93%D0%B5%D0%BD%D0%B5%D1%80%D0%B0%D1%86%D1%96%D1%8F-%D0%BA%D0%BB%D1%8E%D1%87%D1%96%D0%B2-%D0%B4%D0%BB%D1%8F-%D0%BC%D0%B5%D1%80%D1%87%D0%B0%D0%BD%D1%82%D0%B0)
   or the instructions supplied by the NovaPay onboarding manager.
3. **NovaPay public key** — obtain the current production verification key
   from NovaPay's official acquiring documentation or onboarding contact and
   verify its fingerprint through an approved channel.

On the gateway edit form, select **Live**, enter the Merchant ID, and upload
the merchant private key and NovaPay public key together. Both must be PEM
files. To rotate keys, upload both files in one save; if validation or the
atomic replacement fails, the previous pair remains active. The two files
belong to different parties and are not expected to form one cryptographic
key pair.

Never use the bundled test keys in production. Never substitute an arbitrary
locally generated private key for the file issued through the authorized
Acquiring3 workflow.

## Runtime storage and deployment

Exportable Commerce configuration contains the gateway entity, plugin,
stores, conditions, and display settings. The following values are deliberately
environment-local and are not exported through Drupal configuration:

- API mode and transaction mode;
- Merchant ID and recipient identifier;
- redirect delay and detailed-logging flag;
- merchant private key and NovaPay public key.

They are stored under:

```text
private://commerce_novapay/{gateway_uuid}/settings.json
private://commerce_novapay/{gateway_uuid}/private.pem
private://commerce_novapay/{gateway_uuid}/public.pem
```

Provision and back up these files through the environment's secret-management
process. Exclude the private directory from Git, build artifacts, public file
sync, and Drupal configuration sync. Configure local, demo, UAT, and production
independently after each gateway is imported. Deleting a NovaPay gateway also
deletes its local runtime profile.

## Transaction modes and operations

- **Direct** asks NovaPay to charge immediately. A signed successful callback
  completes the Commerce payment.
- **Hold** authorizes funds first. An administrator can later **Capture** all
  or part of the authorized amount, or **Void** the authorization.
- **Refund** is available for completed payments. Selecting all remaining
  items performs a full refund; selecting lower item quantities creates a
  partial refund. Commerce changes the final state only after NovaPay confirms
  the operation.
- **Check NovaPay payment status** reconciles a payment when a direct, hold,
  capture, or void callback is delayed or missing.
- **Check refund status** reconciles a pending refund without repeating the
  financial POST request.

Do not retry capture, void, or refund by submitting the financial form again
when the response is uncertain. Use the matching read-only status operation.

## Security and logging

The signed raw callback body is the source of truth for financial state. The
customer's browser return does not complete a payment. Production accepts only
the documented v2 callback verified with RSA SHA-256; legacy v1/RSA-SHA1 is a
sandbox compatibility path.

Logs must never contain private or public key contents, signatures, raw
callbacks, complete request or response bodies, PAN/CVV, authorization tokens,
full phone numbers, customer personal data, or secret-bearing headers. Detailed
logging is off by default and records sanitized metadata only.

## License

GPL-2.0-or-later.
