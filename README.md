# Parakit

> پارەکیت — The payment kit for Kurdistan and Iraq, Laravel-native.

[![CI](https://github.com/shah/parakit/actions/workflows/ci.yml/badge.svg)](https://github.com/shah/parakit/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Take payments from Iraqi and Kurdish customers in a Laravel app — **FIB**, **ZainCash** — with idempotent webhooks, retry & circuit-breaker, redacted logging, a sweeper for lost webhooks, and three localised UIs (en/ar/ckb) out of the box.

`composer require` → first sandbox charge in under 15 minutes. That's the goal.

---

## Contents

- [Install](#install)
- [Charge a customer](#charge-a-customer)
- [Receive a webhook](#receive-a-webhook)
- [What you get for free](#what-you-get-for-free)
- [Operations](#operations)
- [Multi-tenant / multiple merchants](#multi-tenant--multiple-merchants)
  - [Credentials in a database — `resolveMerchantUsing()`](#credentials-in-a-database--resolvemerchantusing)
- [Laravel Octane](#laravel-octane)
- [Security](#security)
- [Versioning](#versioning)
- [License](#license)

---

## Install

```bash
composer require shah/parakit
php artisan parakit:install
```

> Until Packagist registration is complete, add the repository as a VCS
> source in your app's `composer.json`:
>
> ```json
> "repositories": [
>   { "type": "vcs", "url": "https://github.com/shah/parakit" }
> ]
> ```

Add credentials to your `.env`:

```env
PARAKIT_DEFAULT=fib

FIB_BASE_URL=https://fib.stage.fib.iq
FIB_CLIENT_ID=your-fib-client-id
FIB_CLIENT_SECRET=your-fib-client-secret
FIB_CALLBACK_URL=https://yourapp.com/payments/webhooks/fib

ZAINCASH_BASE_URL=https://test.zaincash.iq
ZAINCASH_MERCHANT_ID=your-merchant-id
ZAINCASH_MSISDN=07xxxxxxxxx
ZAINCASH_SECRET=your-32-byte-or-longer-secret
ZAINCASH_REDIRECT_URL=https://yourapp.com/payments/zaincash/return
```

Verify everything is wired:

```bash
php artisan parakit:doctor --gateway=fib
php artisan parakit:test-charge fib --amount=1000
```

---

## Charge a customer

### Fluent builder (recommended)

```php
use Shah\Parakit\Facades\Payment;
use Shah\Parakit\Enums\Currency;

$response = Payment::for($order)
    ->driver('fib')
    ->amount(5000, Currency::IQD)
    ->description("Order #{$order->id}")
    ->idempotencyKey($order->id)
    ->charge();

if ($response->failed()) {
    return back()->with('error', $response->error->message(app()->getLocale()));
}

// FIB returns a QR + readable code + deep link.
return view('checkout.fib', [
    'qrCode'       => $response->qrCode,
    'readableCode' => $response->readableCode,
    'deepLink'     => $response->deepLink,
]);
```

### Explicit DTO

```php
use Shah\Parakit\DTOs\PaymentRequest;

$response = Payment::driver('zaincash')->charge(new PaymentRequest(
    reference: $order->id,
    amount: 5000,
    currency: Currency::IQD,
    description: 'Order #' . $order->id,
));

return redirect()->away($response->redirectUrl);
```

---

## Receive a webhook

Parakit registers `POST /payments/webhooks/{gateway}` automatically. Listen for lifecycle events anywhere in your app:

```php
use Shah\Parakit\Events\PaymentSucceeded;

Event::listen(PaymentSucceeded::class, function ($event) {
    Order::find($event->transaction->reference)->markPaid();
});
```

Other events: `PaymentInitiated`, `PaymentFailed`, `PaymentCancelled`, `PaymentRefunded`, `WebhookReceived`, `WebhookVerificationFailed`, `GatewayTimeout`, `CircuitOpened`.

---

## What you get for free

| Concern | Mechanism |
|---|---|
| **Idempotency** | `charge()` with the same `idempotencyKey` returns a cached response for 24h. DB unique index on `(gateway, event_id)` makes webhook delivery race-safe. |
| **Retries** | Exponential backoff + jitter on transient gateway failures. Never on 4xx, never without an idempotency key. |
| **Circuit breaker** | Per-gateway. Fails fast after N failures, auto-recovers after cooldown. |
| **State machine** | Illegal status transitions (e.g. `Paid → Pending`) throw and are logged — no double-fulfillment. |
| **Webhook replay protection** | Rejects webhooks older than `parakit.webhooks.tolerance_seconds`. |
| **Lost-webhook recovery** | `parakit:sweep-pending` polls status for stale pending transactions every 5 minutes. |
| **Redacted logging** | Request/response written to `payment_logs` with secrets redacted by configured key names + Luhn-gated PAN regex. |
| **Correlation IDs** | One ULID traces a payment across `charge → webhook → status → refund`. |
| **Translations** | en / ar / ckb shipped; publish with `php artisan vendor:publish --tag=parakit-lang`. |

---

## Operations

| Command | Purpose |
|---|---|
| `parakit:install` | Publishes config + migrations, runs `migrate`. |
| `parakit:doctor [--gateway=fib]` | Verifies config + connectivity. Non-zero exit on failure. |
| `parakit:test-charge fib --amount=1000` | Sandbox roundtrip. Proves end-to-end works. |
| `parakit:sweep-pending` | Polls status for pending transactions to recover lost webhooks. Auto-scheduled every 5 min. |
| `parakit:webhook:simulate fib --transaction-id=pid_1 --status=paid` | POSTs a correctly-formed test webhook to your local app. |
| `parakit:logs:prune --days=90` | Trims `payment_logs` per retention policy. Auto-scheduled daily. |

---

## Multi-tenant / multiple merchants

Need FIB credentials for merchant A and a different set for merchant B? Add as many named gateway configs as you need — same driver, different keys:

```php
// config/parakit.php
'gateways' => [
    'fib_main' => [
        'driver'        => 'fib',
        'base_url'      => env('FIB_MAIN_BASE_URL'),
        'client_id'     => env('FIB_MAIN_CLIENT_ID'),
        'client_secret' => env('FIB_MAIN_CLIENT_SECRET'),
        'callback_url'  => env('FIB_MAIN_CALLBACK_URL'),
    ],
    'fib_branch' => [
        'driver'        => 'fib',
        'base_url'      => env('FIB_BRANCH_BASE_URL'),
        'client_id'     => env('FIB_BRANCH_CLIENT_ID'),
        'client_secret' => env('FIB_BRANCH_CLIENT_SECRET'),
        'callback_url'  => env('FIB_BRANCH_CALLBACK_URL'),
    ],
    'zaincash_merchant_b' => [
        'driver'      => 'zaincash',
        'base_url'    => env('ZC_B_BASE_URL'),
        'merchant_id' => env('ZC_B_MERCHANT_ID'),
        'msisdn'      => env('ZC_B_MSISDN'),
        'secret'      => env('ZC_B_SECRET'),
        'redirect_url'=> env('ZC_B_REDIRECT_URL'),
    ],
],
```

Then select the right one per request:

```php
// Resolve at runtime — driven by your tenant lookup, user attribute, etc.
$gateway = $merchant->gateway_key; // e.g. "fib_branch"

$response = Payment::for($order)
    ->driver($gateway)
    ->amount(5000, Currency::IQD)
    ->description("Order #{$order->id}")
    ->idempotencyKey($order->id)
    ->charge();
```

Each named config is fully isolated: its own circuit-breaker state, idempotency cache namespace, webhook route (`POST /payments/webhooks/fib_branch`), and `gateway` column in `payment_transactions`. Two configs sharing the same driver never bleed into each other.

### Credentials in a database — `resolveMerchantUsing()`

When merchants self-onboard and their credentials live in your database — not in `config/parakit.php` — register a resolver once in a service provider. Parakit calls it with the gateway name and expects the same config array shape as a static entry:

```php
// app/Providers/AppServiceProvider.php — boot()
use Shah\Parakit\Facades\Payment;

Payment::resolveMerchantUsing(function (string $gateway): array {
    $merchant = app(TenantManager::class)->current();

    return $merchant->gatewayConfig($gateway); // ['driver' => 'fib', 'base_url' => ..., ...]
});
```

After that, nothing else changes — `Payment::for($order)->driver('fib')->charge()` routes through your resolver. The resolver receives the gateway name, so one tenant can have any number of gateways (`fib`, `zaincash`, `fib_vip`, …), each resolved independently.

> Store credentials **encrypted** (or as secret-manager references) and decrypt inside the resolver — don't keep raw secrets in plain DB columns.

---

## Laravel Octane

Parakit is Octane-safe out of the box — no extra setup, just run `octane:start`:

- **Correlation IDs** — `AssignCorrelationId` runs `CorrelationId::reset()` in its `terminate()` hook, so the per-request ULID is scrubbed from the container before the next request lands on the same worker.
- **Resolved gateways** — `PaymentManager` memoises instantiated drivers for the life of a request. Parakit flushes that cache automatically after every request (on `RequestHandled`, plus Octane's `RequestTerminated`), so a resolver returning per-tenant credentials never leaks one tenant's gateway into the next request on a reused worker.

If you reconfigure gateways manually mid-request, you can still call `app('parakit.manager')->flushResolved()` yourself.

---

## Security

See [SECURITY.md](SECURITY.md). TL;DR: webhook signatures are mandatory, comparisons are constant-time, TLS verification is always on, secrets are redacted before they touch the DB.

Report vulnerabilities privately via **GitHub Security Advisories** on this repository (*Security* tab → *Report a vulnerability*).

---

## Versioning

Semantic versioning. v0.1 is alpha — public API may still shift before v1.0. Subsequent releases will lock the API and follow `^11.0 || ^12.0` for Laravel.

See [CHANGELOG.md](CHANGELOG.md) for the full history.

---

## License

MIT. See [LICENSE](LICENSE).
