# Parakit

> پارەکیت — The payment kit for Kurdistan and Iraq, Laravel-native.

[![CI](https://github.com/shah/parakit/actions/workflows/ci.yml/badge.svg)](https://github.com/shah/parakit/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Take payments from Iraqi and Kurdish customers in a Laravel app — **FIB**, **ZainCash** — with idempotent webhooks, retry & circuit-breaker, redacted logging, a sweeper for lost webhooks, and three localised UIs (en/ar/ckb) out of the box.

`composer require` → first sandbox charge in under 15 minutes. That's the goal.

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
