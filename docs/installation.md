---
title: Installation
---

# Installation

Install the package, run one command to publish config and create the tables,
add the credentials for the gateway you use, then verify with two Artisan
commands. New to Parakit? Start with the [Introduction](/introduction).

```bash
composer require froshly/parakit
php artisan parakit:install
```

## Requirements

- PHP `^8.2`
- Laravel 12 or 13

## What `parakit:install` does

The command runs three steps:

1. Publishes `config/parakit.php` (tag `parakit-config`). It will not overwrite
   an existing file unless you pass `--force`, so re-running install is safe.
2. Publishes the migrations (tag `parakit-migrations`).
3. Runs `migrate`, creating four tables:
   - `payment_transactions` — one row per charge, with its current status.
   - `payment_webhook_events` — received webhooks, with the unique
     `(gateway, event_id)` index that makes delivery idempotent.
   - `payment_logs` — redacted request/response records.
   - `payment_refunds` — idempotent refund attempts and their provider results.

Receipt Blade templates are **not** published by install. Publish them only if
you want to customise the designs:

```bash
php artisan vendor:publish --tag=parakit-views
```

Translations (`en` / `ar` / `ckb`) are loaded from the package. Publish them
only to override the bundled strings:

```bash
php artisan vendor:publish --tag=parakit-lang
```

## Credentials

Pick the default gateway and set it in `.env`:

```env
PARAKIT_DEFAULT=fib
```

`PARAKIT_DEFAULT` is the gateway used when you call `Payment::charge()` without
`->driver()`. It must match a key under `parakit.gateways` — the bundled config
defines all six (`fib`, `zaincash`, `nass`, `nasswallet`, `fastpay`, `qicard`).

Each gateway needs its own env vars. Rather than list them all here, see the
gateway page for the one you are integrating — it has the full credential table
and where to obtain the keys:

- [FIB](/gateways/fib)
- [ZainCash](/gateways/zaincash)
- [Nass Pay](/gateways/nass)
- [Nass Wallet](/gateways/nasswallet)
- [FastPay](/gateways/fastpay)
- [QiCard](/gateways/qicard)

You only need credentials for the gateways you actually use. For how the
`gateways` block is structured, see [Configuration](/configuration).

## Verify the install

Two commands confirm everything is wired:

```bash
php artisan parakit:doctor --gateway=fib
php artisan parakit:transactions:test-charge fib --amount=1000
```

`parakit:doctor` checks that the required config keys are present and, for FIB,
fetches a fresh OAuth token to confirm connectivity. It exits non-zero on
failure, so it is safe in CI. Drop `--gateway` to check every configured
gateway at once.

`parakit:transactions:test-charge` performs a real sandbox roundtrip against the given
gateway and prints the gateway transaction id plus any redirect URL, readable
code, or deep link. Override the amount and currency with `--amount` and
`--currency` (defaults `1000` and `IQD`).

::: warning
`test-charge` calls the gateway's sandbox for real. Point your `*_BASE_URL`
values at staging/UAT hosts, not production, while testing.
:::

Next: [Configuration](/configuration).
