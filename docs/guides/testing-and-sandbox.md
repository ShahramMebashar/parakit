---
title: Testing & sandbox
---

# Testing & sandbox

Before you point an integration at real money, you want three things confirmed:
the config is complete, the credentials authenticate, and a charge survives the
full roundtrip. Parakit ships three commands for exactly that.

```bash
# 1. Config + connectivity, safe to run anywhere
php artisan parakit:doctor

# 2. A real sandbox charge, end to end
php artisan parakit:test-charge fib --amount=1000 --currency=IQD

# 3. A simulated webhook posted to your local app
php artisan parakit:webhook:simulate fib --transaction-id=pid_1 --status=paid
```

See [Artisan commands](/reference/commands) for every option of every command.

## `parakit:doctor` — config and connectivity

`parakit:doctor` checks each configured gateway. For drivers it knows, it
verifies the required config keys are present, and for FIB it forces a fresh
token fetch to prove the credentials authenticate:

```bash
php artisan parakit:doctor              # all gateways
php artisan parakit:doctor --gateway=fib  # one gateway
```

It makes no charge and moves no money, so it is safe to run anywhere — including
CI. It exits non-zero when any check fails, so a pipeline step against sandbox
credentials catches a broken config before deploy:

```yaml
# In a CI pipeline
- run: php artisan parakit:doctor
```

::: tip
The FIB check clears the cached token first, so a credential rotation cannot be
masked by a stale token. A custom driver type registered via `Payment::extend()`
has no built-in field check — `doctor` reports it as unverified and asks you to
confirm the config manually.
:::

## `parakit:test-charge` — a real sandbox roundtrip

`parakit:test-charge` builds a `PaymentRequest` with a random reference and runs
a genuine `charge()` against the gateway you name:

```bash
php artisan parakit:test-charge fib --amount=1000 --currency=IQD
```

`--amount` defaults to `1000` and `--currency` to `IQD`. On success it prints
the gateway transaction id, plus any redirect URL, readable code, or deep link
the gateway returned:

```
OK: 6f1c2a90-...
readable: 1234 5678
```

This is a real API call to whatever `base_url` the config points at. Run it
against sandbox credentials. The random reference (`test_` plus 8 hex chars) is
fine for sandbox use but is not collision-safe at production volume.

::: warning
`test-charge` charges for real. If the config holds production credentials it
hits the production gateway. Confirm `base_url` and credentials are sandbox
before you run it.
:::

## `parakit:webhook:simulate` — a local webhook

You usually cannot receive a real gateway callback on `localhost`.
`parakit:webhook:simulate` closes that gap by POSTing a webhook to your own
route, so your verification and listeners run for real:

```bash
php artisan parakit:webhook:simulate fib \
  --transaction-id=pid_1 --status=paid
```

It posts to `{route_prefix}/{gateway}` and prints the HTTP status and body it
got back. NassWallet is posted to `{route_prefix}/nasswallet/callback`, the
route that gateway actually calls.

The command builds a faithful, correctly-formed payload for every driver:

- **`fib`** — an `{ id, status }` form body, the shape a FIB callback delivers.
- **`zaincash`** — an HS256 JWT signed with the merchant's `api_key`, wrapping a
  `STATUS_CHANGED` envelope, exactly as ZainCash v2 sends it.
- **`nass`** — an `{ orderId, responseCode }` form body.
- **`nasswallet`** — a JSON `{ "data": { ... } }` envelope keyed by
  `InitTransactionId`.
- **`fastpay`** — an `{ order_id, status }` form body.

`--status` defaults to `paid`; `--reference` and `--transaction-id` are optional.

::: tip
FIB, NassPay, NassWallet, and FastPay all ignore the callback body and re-fetch
status server-to-server, so the transaction id you pass must be one the sandbox
recognises. ZainCash instead verifies the JWT signature against the configured
`api_key` — that config must be set for the simulated webhook to pass.
:::

## Faking events in feature tests

For application tests, you do not need a gateway at all. Fake the events and
assert your own code reacts:

```php
use Froshly\Parakit\Events\PaymentSucceeded;
use Illuminate\Support\Facades\Event;

Event::fake([PaymentSucceeded::class]);

// ... run the code that should mark an order paid ...

Event::assertDispatched(PaymentSucceeded::class);
```

See [Events](/reference/events) for the full list and each event's payload.

## Sandbox endpoints

Every gateway's default `base_url` points at the provider's staging environment
(`fib.stage.fib.iq`, `pg-api-uat.zaincash.iq`, and so on — see
[Configuration](/configuration)). Switch to production URLs only when you flip
to live credentials.
