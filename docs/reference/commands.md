---
title: Artisan commands
---

# Artisan commands

Parakit ships seven `parakit:*` Artisan commands. They cover one-time setup,
runtime diagnostics, and the recurring operations that keep payment data
consistent. Run any of them with `php artisan`:

```bash
php artisan parakit:install
php artisan parakit:doctor --gateway=fib
```

## Quick reference

| Command | Group | What it does |
| --- | --- | --- |
| `parakit:install` | Setup | Publishes config and migrations, then runs `migrate`. |
| `parakit:doctor` | Diagnostics | Checks gateway config and FIB token connectivity. |
| `parakit:test-charge` | Diagnostics | Runs a sandbox charge end to end. |
| `parakit:webhook:simulate` | Diagnostics | Posts a signed test webhook to your local app. |
| `parakit:receipt:preview` | Diagnostics | Renders sample receipts to disk for design review. |
| `parakit:sweep-pending` | Operations | Polls pending transactions to recover lost webhooks. |
| `parakit:logs:prune` | Operations | Deletes `payment_logs` rows past the retention window. |

All commands return `0` on success. Failure codes are noted per command below.

## Setup

### `parakit:install`

Publishes the package config and migrations, then migrates the database. Run
this once after installing the Composer package.

```
parakit:install {--force}
```

| Option | Default | Description |
| --- | --- | --- |
| `--force` | off | Overwrite an existing published `config/parakit.php`. |

What it does, in order:

1. Publishes `config/parakit.php` (tag `parakit-config`). Without `--force`,
   an existing config file is left untouched so re-running the command does
   not clobber operator edits.
2. Publishes the migrations (tag `parakit-migrations`). These are always
   force-published — migrations are timestamped and additive, so a republish
   is safe.
3. Runs `migrate`. In the `production` environment it passes `--force` so a
   non-interactive deploy does not hang on the confirmation prompt.

Receipt templates are not published automatically. To customise them, run the
extra step the command prints:

```bash
php artisan vendor:publish --tag=parakit-views
```

```bash
# First install
php artisan parakit:install

# Re-publish config after a package update, overwriting local edits
php artisan parakit:install --force
```

Exit code: always `0`.

::: tip
See [Installation](/installation) and [Configuration](/configuration) for the
full setup walkthrough and the credentials each gateway needs.
:::

## Diagnostics

### `parakit:doctor`

Verifies that each configured gateway has the config keys it needs, and — for
FIB — that the credentials can actually fetch a token.

```
parakit:doctor {--gateway=}
```

| Option | Default | Description |
| --- | --- | --- |
| `--gateway=` | all gateways | Limit the check to a single gateway key. |

For each gateway it checks the required config keys:

| Driver | Required keys |
| --- | --- |
| `fib` | `base_url`, `client_id`, `client_secret`, `callback_url` |
| `zaincash` | `base_url`, `client_id`, `client_secret`, `api_key` |
| `nass` | `base_url`, `username`, `password`, `callback_url` |
| `nasswallet` | `base_url`, `portal_url`, `basic_token`, `username`, `password`, `transaction_pin`, `callback_url` |
| `fastpay` | `base_url`, `store_id`, `store_password`, `callback_url` |
| `qicard` | `base_url`, `username`, `password`, `terminal_id` |
| any other | No built-in check — prints a warning to verify manually. |

When FIB credentials are present, the command clears the cached FIB token
(`parakit:fib:token`) and forces a fresh fetch. A cached token from a rotated
secret would otherwise let the check pass while live charges return `401`.

```bash
# Check every configured gateway
php artisan parakit:doctor

# Check only FIB
php artisan parakit:doctor --gateway=fib
```

| Exit code | Meaning |
| --- | --- |
| `0` | All checks passed. |
| `1` | A gateway is missing config, failed the FIB token fetch, or no gateways are configured. |

::: warning
Drivers registered through `Payment::extend()` have no built-in config check.
The doctor reports a warning for them rather than a false OK.
:::

### `parakit:test-charge`

Runs a real sandbox charge against a gateway to prove the integration works
end to end. Use it after configuring credentials.

```
parakit:test-charge {gateway} {--amount=1000} {--currency=IQD}
```

| Argument | Required | Description |
| --- | --- | --- |
| `gateway` | yes | Gateway key to charge (`fib`, `zaincash`, `nass`, `nasswallet`, `fastpay`, `qicard`). |

| Option | Default | Description |
| --- | --- | --- |
| `--amount=` | `1000` | Charge amount in minor units (whole dinars for IQD, cents for USD). |
| `--currency=` | `IQD` | Currency code — must be a valid `Currency` enum value (`IQD`, `USD`). |

The command builds a `PaymentRequest` with a random `test_*` reference and
charges the gateway. On success it prints the gateway transaction id and any
redirect URL, readable code, or deep link the response carries.

```bash
php artisan parakit:test-charge fib --amount=5000 --currency=IQD
```

| Exit code | Meaning |
| --- | --- |
| `0` | Charge succeeded. |
| `2` | Unknown currency passed to `--currency`. |

::: warning
The random reference suffix is sandbox-only and is not collision-safe at
production volumes. Point this command at sandbox credentials.
:::

### `parakit:webhook:simulate`

Posts a test webhook to your local app, so you can exercise your webhook
handling without waiting on the real gateway.

```
parakit:webhook:simulate {gateway} {--status=paid} {--reference=} {--transaction-id=} {--sign-with=}
```

| Argument | Required | Description |
| --- | --- | --- |
| `gateway` | yes | Gateway key — `fib`, `zaincash`, `nass`, `nasswallet`, `fastpay`, or `qicard`. |

| Option | Default | Description |
| --- | --- | --- |
| `--status=` | `paid` | Payment status to report. `paid` maps to each gateway's success status. |
| `--reference=` | empty | Your order reference to put in the payload. |
| `--transaction-id=` | empty | Gateway transaction id to put in the payload. |
| `--sign-with=` | empty | Path to a PEM-encoded RSA private key. QiCard only — signs the canonical payload string and adds the `X-Signature` header so your verification path exercises end to end. Without this flag the qicard simulator posts an unsigned body, exercising the fallback (status re-check) path instead. |

The webhook is posted to `{route_prefix}/{gateway}` on your app URL — except
NassWallet, which is posted to `{route_prefix}/nasswallet/callback`. Each
gateway gets a payload shaped like its real callback:

| Gateway | Payload |
| --- | --- |
| `zaincash` | HS256 JWT signed with the configured `api_key`. |
| `fib` | Flat `id` / `status` form body. |
| `nass` | Flat `orderId` / `responseCode` form body. |
| `nasswallet` | JSON `{ "data": { ... } }` envelope keyed by `InitTransactionId`. |
| `fastpay` | Flat `order_id` / `status` form body. |

The command prints the HTTP status and response body.

::: tip
NassPay, NassWallet, and FastPay re-fetch the authoritative status from the
gateway when a callback arrives. A simulated webhook still exercises your route
and controller, but that server-side re-fetch will hit the real gateway — so
the transition only applies if the gateway recognises the transaction id.
:::

```bash
php artisan parakit:webhook:simulate fib \
  --status=paid \
  --reference=ORD-2048 \
  --transaction-id=pid_5f3a9c2e
```

| Exit code | Meaning |
| --- | --- |
| `0` | The webhook endpoint returned a 2xx response. |
| `1` | The endpoint returned a non-2xx response. |

See [Handling webhooks](/guides/handling-webhooks) for how the endpoint
verifies and processes these requests.

### `parakit:receipt:preview`

Renders sample receipts to disk so you can review template designs without
wiring up a real payment.

```
parakit:receipt:preview {--template=modern} {--type=payment} {--locale=en} {--format=html} {--output=} {--all}
```

| Option | Default | Description |
| --- | --- | --- |
| `--template=` | `modern` | Template to render: `modern`, `classic`, or `minimal`. |
| `--type=` | `payment` | Receipt type: `payment` or `refund`. |
| `--locale=` | `en` | Render locale: `en`, `ar`, or `ckb`. |
| `--format=` | `html` | Output format: `html` or `pdf`. HTML is fastest for design iteration. |
| `--output=` | `storage/parakit-receipts` | Directory to write files into. Created if missing. |
| `--all` | off | Render every template × type × locale combination. |

Each file is written as `{template}-{type}-{locale}.{format}`. The command
uses a built-in sample transaction (a paid `ORD-2048` for `payment`, partially
refunded for `refund`), so no database row is required.

```bash
# Preview one combination as HTML
php artisan parakit:receipt:preview --template=classic --type=refund --locale=ar

# Render every combination as PDF
php artisan parakit:receipt:preview --all --format=pdf
```

| Exit code | Meaning |
| --- | --- |
| `0` | All receipts rendered. |
| `1` | Invalid `--format`, unknown `--template`/`--type`, or the output directory could not be created. |

See [Receipts](/guides/receipts) for the receipt API.

## Operations

These commands are meant to run on a schedule. Register them in your
application's scheduler — for example in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('parakit:sweep-pending')->everyFiveMinutes();
Schedule::command('parakit:logs:prune')->daily();
```

### `parakit:sweep-pending`

Polls the gateway for the status of transactions still in `Pending` or
`Processing`, then reconciles their local status. This recovers transactions
whose webhook was lost or never delivered.

```
parakit:sweep-pending {--gateway=} {--older-than=}
```

| Option | Default | Description |
| --- | --- | --- |
| `--gateway=` | all gateways | Limit the sweep to one gateway key. |
| `--older-than=` | `parakit.sweeper.older_than_minutes` (5) | Only sweep rows untouched for at least this many minutes. |

It selects `payment_transactions` that are `Pending` or `Processing`, were
last updated at least `--older-than` minutes ago, were created within
`parakit.sweeper.max_age_hours` (default 24), and have a
`gateway_transaction_id`. For each row it calls the gateway's status check and
applies any status change inside a row-locked DB transaction, so it never
races with an incoming webhook.

When a status actually changes, it dispatches the matching event:

| New status | Event dispatched |
| --- | --- |
| `Paid` | `PaymentSucceeded` |
| `Failed` | `PaymentFailed` |
| `Cancelled`, `Expired` | `PaymentCancelled` |

Gateways that do not implement `SupportsStatusCheck` are skipped.

```bash
# Sweep everything older than the configured window
php artisan parakit:sweep-pending

# Sweep only FIB rows untouched for 15+ minutes
php artisan parakit:sweep-pending --gateway=fib --older-than=15
```

Exit code: always `0`. The command prints how many transactions it updated.

See [Reliability](/guides/reliability) for how the sweeper fits into
parakit's recovery model.

### `parakit:logs:prune`

Deletes old rows from the `payment_logs` table to keep it from growing
without bound.

```
parakit:logs:prune {--days=}
```

| Option | Default | Description |
| --- | --- | --- |
| `--days=` | `parakit.logging.retention_days` (90) | Delete `payment_logs` rows older than this many days. |

```bash
# Use the configured retention window
php artisan parakit:logs:prune

# Keep only the last 30 days
php artisan parakit:logs:prune --days=30
```

Exit code: always `0`. The command prints how many rows it deleted.
