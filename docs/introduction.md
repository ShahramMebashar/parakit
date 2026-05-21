---
title: Introduction
---

# Introduction

Parakit is a Laravel-native payment kit for Iraq and Kurdistan. It puts the
local gateways — FIB, ZainCash, Nass Pay, Nass Wallet, FastPay, and QiCard —
behind one fluent API, so charging a customer looks the same no matter which
gateway you pick.

If you have used Laravel before, Parakit will feel familiar: a facade, a fluent
builder, config in `config/parakit.php`, events you can listen for. The goal is
a first sandbox charge in about 15 minutes — `composer require`, `parakit:install`,
add credentials, run `parakit:test-charge`.

```php
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\Enums\Currency;

$response = Payment::for($order)
    ->driver('fib')
    ->amount(5000, Currency::IQD)
    ->description("Order #{$order->id}")
    ->idempotencyKey($order->id)
    ->charge();
```

That returns a `PaymentResponse` — a redirect URL, or a QR code and deep link,
depending on the gateway. The rest of the docs build on this one call.

## The six gateways

| Gateway          | Driver key   | Flow                                | Refunds | Cancel |
| ---------------- | ------------ | ----------------------------------- | ------- | ------ |
| First Iraqi Bank | `fib`        | QR code + readable code + deep link | Yes     | Yes    |
| ZainCash         | `zaincash`   | Hosted redirect                     | Yes     | No     |
| Nass Pay         | `nass`       | Hosted redirect                     | No      | No     |
| Nass Wallet      | `nasswallet` | Hosted redirect                     | No      | No     |
| FastPay          | `fastpay`    | Hosted redirect                     | Yes     | No     |
| QiCard           | `qicard`     | Hosted card form (3DS)              | Yes     | Yes    |

The driver key is what you pass to `->driver()` and what appears in the webhook
URL (`POST /payments/webhooks/{gateway}`). FIB, ZainCash, FastPay, and QiCard
support refunds; Nass Pay and Nass Wallet do not — calling `refund()` on those
throws. FIB and QiCard additionally support `cancel()` for an unpaid payment.

::: tip
You only configure the gateways you use. Most apps start with one.
:::

## What you get for free

Taking a payment is the easy part. The parts that bite in production are
handled for you:

- **Idempotent webhooks** — a unique DB index on `(gateway, event_id)` makes
  duplicate gateway deliveries race-safe.
- **Retries** — exponential backoff with jitter on transient gateway failures,
  never on 4xx and never without an idempotency key.
- **Circuit breaker** — per-gateway, fails fast after repeated failures and
  recovers automatically after a cooldown.
- **Redacted logging** — requests and responses are written to `payment_logs`
  with secrets stripped by key name and a Luhn-gated PAN regex.
- **Lost-webhook sweeper** — `parakit:sweep-pending` polls stale pending
  transactions so a dropped webhook does not strand an order.
- **PDF receipts** — turn any stored transaction into a branded payment or
  refund PDF, no gateway call involved.
- **Localised UIs** — payment screens and receipts ship in English, Arabic,
  and Sorani Kurdish (`en` / `ar` / `ckb`), RTL-aware.

## Where to go next

- [Installation](/installation) — install the package and create the tables.
- [Configuration](/configuration) — every key in `config/parakit.php`.
- [Charging a customer](/guides/charging-a-customer) — the builder, DTOs, and
  what a `PaymentResponse` carries.
- [Handling webhooks](/guides/handling-webhooks) — the auto-registered route
  and the lifecycle events to listen for.
- [Refunds](/guides/refunds) and [Receipts](/guides/receipts) — refunding a
  charge and generating PDFs.
- [Reliability](/guides/reliability) — how retries, idempotency, and the
  circuit breaker fit together.
- Per-gateway setup: [FIB](/gateways/fib), [ZainCash](/gateways/zaincash),
  [Nass Pay](/gateways/nass), [Nass Wallet](/gateways/nasswallet),
  [FastPay](/gateways/fastpay), [QiCard](/gateways/qicard).
- [Multi-tenant merchants](/guides/multi-tenant-merchants),
  [Writing a custom gateway](/guides/custom-gateway), and
  [Testing & sandbox](/guides/testing-and-sandbox).
