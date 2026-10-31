# Paymento for Drupal Commerce

**Step-by-step installation guide:** https://github.com/paymento/paymento-drupal-plugin#installation — the callback URL and the cron jobs are both required, or paid orders stay in the cart.

Version 1.0 supports Drupal 11.4 / Commerce 3.3, PHP 8.4 with bcmath and MySQL-compatible database locking. Validated fixture: Drupal 11.4.11, Commerce 3.3.13, PHP 8.4.27, MariaDB 11.4. Only the default simple non-shippable checkout is supported. Installing Commerce Shipping makes initiation and first completion refuse processing. Custom checkout, fulfillment fields, subscriptions, partial refunds, and shipment adapters require additional validation.

Install this directory as web/modules/custom/commerce_paymento, then run `drush en commerce_paymento` and `drush updatedb`. Enable a Paymento gateway under Commerce > Configuration > Payment gateways. Select Production or Beta and a server-side credential reference. Put credentials in settings.php (supply real values from environment or your secret manager):

```php
$settings['commerce_paymento_credentials']['merchant'] = [
  'api_key' => getenv('PAYMENTO_API_KEY'),
  'ipn_secret' => getenv('PAYMENTO_IPN_SECRET'),
];
```

Set the gateway credential reference to `merchant`. Credentials are never stored in gateway configuration or redisplayed. Keep the reference/environment stable while attempts remain unresolved; changing them holds old work for review. Rotate secret material deliberately with a retained recovery plan.

The callback URL is `/paymento/notify/GATEWAY_ID` on the store's public HTTPS origin. This is the URL to enter as the IPN URL in the Paymento panel — not the admin form address below. POST and PUT are accepted after raw-body HMAC validation. The explicit admin form at `/admin/commerce/config/payment-gateways/GATEWAY_ID/paymento-callback` validates HTTPS, reads existing remote settings, and refuses replacing a different callback. Use a separate Paymento credential/account where callback ownership differs.

Schedule `drush cron` at least every minute to repair queue insertion from committed inbox rows, and `drush queue:run commerce_paymento --time-limit=30` repeatedly. The raw inbox commits before remote verification. Worker failures stay retryable; failed queue items requeue after60 seconds and repair leases expire after5 minutes. A bounded manual retry is available using `drush php:eval "\Drupal::service('commerce_paymento.manager')->retry();"`. Monitor unresolved inbox rows, last_error, retries and next_attempt without exporting payloads or tokens to public logs. Duplicate paid tokens, canceled/edited orders and mismatched native balances require merchant reconciliation; never silently discard actual money.

Browser return cannot authorize payment. Verified callbacks create native Commerce Payment records, update the native paid ledger and let Commerce's own order-paid subscriber place the order. Full provider refunds synchronize the mapped attempt's native refund ledger; refunding a losing token cannot revoke the winning tender. No merchant-initiated or partial refund API is offered. The outbox records durable paid intent/evidence; external emails and third-party fulfillment hooks require their own idempotency. Independent gateways may charge concurrently and do not share Paymento's advisory lock.

Gateway disable preserves callbacks/history. Module uninstall is refused while financial attempt history exists. Back up all four commerce_paymento tables with native order/payment data; do not delete rows to remove an operational warning. PostgreSQL and SQLite are unsupported and fail module requirements.

The repository Docker tests use synthetic Paymento responses. Live Paymento, marketplace approval, arbitrary extensions, currencies other than fixture USD, and production delivery remain separate acceptance gates.
