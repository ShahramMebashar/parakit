---
title: Configuration
---

# Configuration

Every Parakit setting lives in `config/parakit.php`, published by
`parakit:install`. This page documents each key, section by section. If a key
is not here, it is not a real key — check against the published file.

Most values read from `.env`, so you rarely edit the config file itself. The
defaults below are what ships in the package.

## `default`

| Key       | Env var           | Default | Purpose                                                                 |
| --------- | ----------------- | ------- | ----------------------------------------------------------------------- |
| `default` | `PARAKIT_DEFAULT` | `fib`   | Gateway used when `Payment::charge()` is called without `->driver()`. Must match a key under `gateways`. |

## `webhooks`

Controls the auto-registered webhook route and how incoming webhooks are
validated.

| Key                  | Env var                            | Default            | Purpose                                                                 |
| -------------------- | ---------------------------------- | ------------------ | ----------------------------------------------------------------------- |
| `route_prefix`       | —                                  | `payments/webhooks`| URL prefix for the webhook route. The full path is `POST {prefix}/{gateway}`. |
| `middleware`         | —                                  | `['api']`          | Middleware applied to the webhook route.                                |
| `tolerance_seconds`  | —                                  | `300`              | Webhooks whose timestamp is older than this are rejected as replays.    |
| `on_amount_mismatch` | `PARAKIT_WEBHOOK_ON_AMOUNT_MISMATCH`| `log`              | What to do when a `Paid` webhook's amount disagrees with the charged amount. |

`on_amount_mismatch` has two values:

- `log` — records a `parakit.webhook.amount_mismatch` warning and **still**
  applies the status transition.
- `reject` — refuses the transition. The payment stays unconfirmed until you
  investigate.

::: warning
`reject` is the safer choice if your prices are fixed. Use `log` only if you
have a reason to expect legitimate amount differences.
:::

## `reliability`

Retry, idempotency, circuit-breaker, and timeout behaviour. See
[Reliability](/guides/reliability) for how these interact.

| Key                                 | Default | Purpose                                                                 |
| ------------------------------------ | ------- | ----------------------------------------------------------------------- |
| `idempotency_ttl`                    | `86400` | Seconds a cached `charge()` response is replayed for the same idempotency key (24h). |
| `retry.max_attempts`                 | `3`     | Attempts on transient gateway failures.                                 |
| `retry.base_delay_ms`                | `200`   | Base delay for exponential backoff with jitter.                         |
| `circuit_breaker.failure_threshold`  | `5`     | Consecutive failures before the circuit opens for that gateway.         |
| `circuit_breaker.cooldown_seconds`   | `30`    | How long the circuit stays open before a probe request.                 |
| `timeout_seconds`                    | `15`    | HTTP timeout per gateway call.                                          |

None of these read from `.env` — edit `config/parakit.php` to change them.

## `logging`

Controls writes to the `payment_logs` table.

| Key              | Env var               | Default | Purpose                                                          |
| ---------------- | --------------------- | ------- | ---------------------------------------------------------------- |
| `enabled`        | —                     | `true`  | Toggle writes to `payment_logs`.                                 |
| `channel`        | `PARAKIT_LOG_CHANNEL` | `stack` | Laravel log channel for human-readable lines.                    |
| `redact_keys`    | —                     | see below | Payload keys redacted before anything is written to the DB.    |
| `retention_days` | —                     | `90`    | Age after which `parakit:logs:prune` removes log rows.           |

The default `redact_keys` array:

```php
['password', 'token', 'secret', 'key', 'card', 'cvv', 'cvc', 'pan', 'msisdn', 'authorization', 'pin', 'mpin']
```

Any payload key matching one of these word components is redacted. PAN-like
numbers are also redacted by a Luhn-gated regex regardless of the key name, and
sensitive URL query values such as `?token=...` are scrubbed. Add your own keys
to the array if a gateway returns secrets under different names.

## `raw_payloads`

Controls raw gateway bodies that Parakit stores in its own database rows and
idempotency cache. Runtime `PaymentResponse` and `RefundResponse` objects still
carry the gateway response returned by the current call.

| Key      | Env var                       | Default | Purpose |
| -------- | ----------------------------- | ------- | ------- |
| `store`  | `PARAKIT_STORE_RAW_PAYLOADS`  | `true`  | Store gateway raw payloads on `payment_transactions`, `payment_webhook_events`, `payment_refunds`, and idempotency cache entries. When `false`, Parakit stores an empty array. |
| `redact` | `PARAKIT_REDACT_RAW_PAYLOADS` | `true`  | Redact stored payloads using `logging.redact_keys` before persistence. |

## `sweeper`

The lost-webhook recovery sweeper, run by `parakit:transactions:sweep-pending` (scheduled
every five minutes when enabled).

| Key                  | Default | Purpose                                                                 |
| -------------------- | ------- | ----------------------------------------------------------------------- |
| `enabled`            | `true`  | Toggle the sweeper. When `false`, the scheduled job is not registered.  |
| `older_than_minutes` | `5`     | A pending transaction must be at least this old before it is swept.     |
| `max_age_hours`      | `24`    | Transactions older than this are no longer polled — treated as dead.    |

## `receipts`

Defaults for PDF receipt generation. See [Receipts](/guides/receipts) for the
generation API.

| Key        | Env var                     | Default                | Purpose                                                              |
| ---------- | --------------------------- | ---------------------- | -------------------------------------------------------------------- |
| `template` | `PARAKIT_RECEIPTS_TEMPLATE` | `modern`               | Template when none is passed at runtime: `modern`, `classic`, or `minimal`. |
| `disk`     | `PARAKIT_RECEIPTS_DISK`     | `local`                | Filesystem disk for `ReceiptDocument::save()`.                       |
| `path`     | —                           | `receipts`             | Directory on that disk where saved receipts land.                    |
| `filename` | —                           | `{type}-{reference}.pdf` | Filename pattern. Tokens: `{type}`, `{reference}`, `{id}`.          |
| `locale`   | —                           | `app`                  | Locale the receipt renders in. `app` uses the current app locale.    |

### `receipts.metadata`

When you do not pass customer details explicitly, the receipt falls back to
these keys on the transaction's `metadata`.

| Key          | Default          | Purpose                                  |
| ------------ | ---------------- | ---------------------------------------- |
| `name_key`   | `customer_name`  | Metadata key holding the customer name.  |
| `email_key`  | `customer_email` | Metadata key holding the customer email. |
| `phone_key`  | `customer_phone` | Metadata key holding the customer phone. |
| `locale_key` | `locale`         | Metadata key holding the receipt locale. |

### `receipts.merchant`

The merchant block printed on every receipt.

| Key             | Env var                        | Default            | Purpose                                      |
| --------------- | ------------------------------ | ------------------ | -------------------------------------------- |
| `name`          | `PARAKIT_MERCHANT_NAME`        | `config('app.name')` | Merchant name shown on the receipt.        |
| `address`       | `PARAKIT_MERCHANT_ADDRESS`     | `null`             | Merchant address line.                       |
| `logo`          | `PARAKIT_MERCHANT_LOGO`        | `null`             | Logo as an absolute path or data URI.        |
| `support_email` | `PARAKIT_MERCHANT_SUPPORT_EMAIL`| `null`            | Support email printed for the customer.      |

### `receipts.pdf`

dompdf rendering options.

| Key                     | Default        | Purpose                                              |
| ----------------------- | -------------- | ---------------------------------------------------- |
| `paper`                 | `a4`           | Paper size.                                          |
| `orientation`           | `portrait`     | Page orientation.                                    |
| `options.defaultFont`   | `DejaVu Sans`  | Default font (see note below).                       |
| `options.isRemoteEnabled` | `false`      | Whether dompdf may load remote assets.               |

::: warning DejaVu Sans is required for Arabic / Kurdish
`DejaVu Sans` is the only bundled dompdf font with Arabic and Kurdish glyph
coverage. Changing `defaultFont` will break `ar` / `ckb` receipts unless the
replacement font covers those scripts.
:::

## `gateways`

A map of **named gateway configs**. The array key is the name you pass to
`->driver()` and the name that appears in the webhook URL. The `driver` key
inside each entry selects the implementation (`fib`, `zaincash`, `nass`,
`nasswallet`, `fastpay`, `qicard`).

```php
'gateways' => [
    'fib' => [
        'driver'   => 'fib',
        'base_url' => env('FIB_BASE_URL', 'https://fib.stage.fib.iq'),
        // ...credential keys...
    ],
],
```

Because the name and the driver are separate, you can register several named
configs for the same driver — `fib_main`, `fib_branch`, each with its own
credentials, circuit-breaker state, and webhook route. See
[Multi-tenant merchants](/guides/multi-tenant-merchants) for that pattern.

The bundled config defines all six gateways. Each one has its own set of
credential keys and env vars — see the per-gateway page for the full table and
where to obtain the values:

- [FIB](/gateways/fib) — `base_url`, `client_id`, `client_secret`, `currency`,
  `refundable_for`, `expires_in`, `category`, `callback_url`.
- [ZainCash](/gateways/zaincash) — `base_url`, `client_id`, `client_secret`,
  `api_key`, `scope`, `service_type`, `lang`, `success_url`, `failure_url`.
- [Nass Pay](/gateways/nass) — `base_url`, `username`, `password`, `token_ttl`,
  `transaction_type`, `callback_url`, `return_url`.
- [Nass Wallet](/gateways/nasswallet) — `base_url`, `portal_url`,
  `basic_token`, `username`, `password`, `transaction_pin`, `callback_url`.
- [FastPay](/gateways/fastpay) — `base_url`, `store_id`, `store_password`,
  `refund_secret_key`, `success_url`, `cancel_url`, `callback_url`.
- [QiCard](/gateways/qicard) — `base_url`, `username`, `password`,
  `terminal_id`, `locale`, `public_key`, `finish_payment_url`,
  `notification_url`.

::: tip
The default `base_url` for every gateway points at its staging/UAT host. Set
the production URL in `.env` when you go live — for Nass Wallet the path
itself differs between environments.
:::
