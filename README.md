# Paymento for Drupal Commerce

Accept crypto payments in Drupal Commerce with [Paymento](https://paymento.io), a non-custodial crypto payment gateway: payments go straight from the customer to your own wallet.

At checkout the customer is sent to the hosted Paymento payment page. Your order is marked **paid** only after Paymento's signed callback has been received and confirmed with Paymento's Verify API — never because the browser came back.

## Requirements

- Drupal **11.4** with Drupal Commerce **3.3.13** or later
- PHP **8.4** with the `bcmath` extension
- **MySQL or MariaDB** (PostgreSQL and SQLite are not supported)
- The default checkout for **non-shippable** products (Commerce Shipping is not supported yet)
- A public **HTTPS** address, so Paymento can reach your site
- Access to **cron** on your server
- A Paymento merchant account with a store, its **API key** and its **Secret key**

## Installation

Do every step. Steps 5 and 6 are the ones people miss — without them orders stay in the cart even though the customer paid.

### 1. Install the module

Download **`paymento-drupal-1.0.0.zip`** from the [latest release](https://github.com/paymento/paymento-drupal-plugin/releases/latest) and unzip it into `web/modules/custom/`, so that you end up with:

```
web/modules/custom/commerce_paymento
```

(Do not use GitHub's "Source code" zip — it contains the whole development repository, not just the module.)

Then enable it:

```bash
drush en commerce_paymento
drush updatedb
drush cr
```

### 2. Add your Paymento keys to `settings.php`

In the Paymento merchant panel, open your store and copy its **API key** and **Secret key** (the secret signs callbacks). Add them to `web/sites/default/settings.php`:

```php
$settings['commerce_paymento_credentials']['merchant'] = [
  'api_key' => 'YOUR_API_KEY',
  'ipn_secret' => 'YOUR_SECRET_KEY',
];
```

Better still, read them from environment variables (`getenv('PAYMENTO_API_KEY')`) or a file outside the web root. The keys are never entered in the Drupal admin screens.

### 3. Create the payment gateway

Go to **Commerce → Configuration → Payment gateways → Add payment gateway**:

| Field | Value |
|-------|-------|
| Name | e.g. `Paymento` — note the **machine name** (e.g. `paymento`), you need it in step 4 |
| Plugin | **Paymento** |
| Paymento environment | **Production**, or **Beta** for testing with testnet coins |
| Server-side credential reference | `merchant` (the name used in step 2) |
| Status | Enabled |

### 4. Set the callback URL in Paymento

Paymento must know where to send payment updates. Your callback URL is:

```
https://YOUR-SITE/paymento/notify/GATEWAY_MACHINE_NAME
```

for example `https://shop.example.com/paymento/notify/paymento`.

Set it in one of two ways:

- **From Drupal:** open `https://YOUR-SITE/admin/commerce/config/payment-gateways/GATEWAY_MACHINE_NAME/paymento-callback` and press the button. This only works if the store has **no** callback URL yet — it will not overwrite another one.
- **From the Paymento panel:** in your store's payment settings, set the IPN / callback URL to the address above and the method to **POST**.

> ⚠️ The callback URL is `/paymento/notify/...`. Do **not** paste the `/admin/.../paymento-callback` address into Paymento — that is only the setup page, and every callback sent there is rejected.

### 5. Add the cron jobs ⚠️ required

A callback is stored the moment it arrives, but the order is marked paid by a background job. That job runs from cron. Add these two lines to the crontab of the user that owns your Drupal files (`crontab -e`), changing the path and address:

```cron
* * * * * cd /path/to/drupal && vendor/bin/drush --uri=https://YOUR-SITE cron >/dev/null 2>&1
* * * * * cd /path/to/drupal && vendor/bin/drush --uri=https://YOUR-SITE queue:run commerce_paymento --time-limit=30 >/dev/null 2>&1
```

If your server's default `php` is not 8.4, call it explicitly, e.g. `/usr/bin/php8.4 vendor/drush/drush/drush.php ...`.

**Why a background job?** Paymento's Verify call is final: once your site verifies a paid order, Paymento stops resending its callback. If your site crashed between verifying and saving the order, nothing would ever retry it. So the plugin stores each callback first and retries from cron until the order is saved.

### 6. Test one payment

1. Create a product (**Commerce → Products → Add product**) with a small price.
2. Buy it as a customer and choose **Crypto with Paymento**. Use the **Beta** environment and testnet coins first.
3. After paying you return to a "pending" page — that is expected.
4. Within about a minute of the payment confirming, the order appears under **Commerce → Orders** as **Completed** with a completed payment.

## Troubleshooting

**The customer paid but the order is still in the cart.**
Cron is not running (step 5). Run the queue once by hand to check:

```bash
drush queue:run commerce_paymento
```

If that completes the order, fix your crontab.

**No callbacks arrive at all.**
Check the callback URL saved in Paymento (step 4) and look in your web server's access log for `POST /paymento/notify/...`. A `401` means the Secret key in `settings.php` does not match the store; a `503` means the keys are missing.

**See what the plugin received:**

```sql
SELECT id, received, processed, retries, last_error FROM commerce_paymento_inbox ORDER BY id DESC;
```

`processed` empty with `retries` 0 → cron is not running. A value in `last_error` → processing failed and will be retried; contact support if it stays.

**The return page shows "Access denied".**
The customer returned in a different browser session than the one used for checkout. The payment is not affected.

More detail on operations, refunds and limits: [module README](commerce_paymento/README.md). Paymento API documentation: https://docs.paymento.io

## Development

`npm test` needs Docker and Node 22, and ports 18100/18101 free. It builds a pinned Drupal + Commerce + MariaDB environment from an empty database and runs native, HTTP checkout and concurrency tests against a local Paymento mock. It never contacts the real Paymento API.

`npm run package` builds an installable zip of the module into `artifacts/`.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
