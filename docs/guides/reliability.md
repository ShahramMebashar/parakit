---
title: Reliability
---

# Reliability

Networks fail and gateways go down. Parakit assumes both and defends every
charge — idempotency, retries, a circuit breaker, timeouts, and a sweeper for
lost webhooks. These are on by default; tune them under `parakit.reliability`
and `parakit.sweeper`.

```php
// config/parakit.php
'reliability' => [
    'idempotency_ttl' => 86400,
    'retry' => ['max_attempts' => 3, 'base_delay_ms' => 200],
    'circuit_breaker' => ['failure_threshold' => 5, 'cooldown_seconds' => 30],
    'timeout_seconds' => 15,
],
```

The shared mechanics live in `AbstractGateway`, which every shipped driver
extends. A driver can disable charge retries when its provider has no safe
idempotency or reconciliation mechanism.

## Idempotency

`charge()` derives an idempotency key — your `idempotencyKey()` if you passed
one, otherwise a value derived from the gateway, reference, amount, and
currency. Two layers use it:

- **Response cache** — the first successful `PaymentResponse` is cached for
  `idempotency_ttl` seconds (86,400 — 24h — by default). A repeat `charge()`
  with the same key returns the cached response and never calls the gateway.
- **Write-ahead row** — before the gateway call, `charge()` persists a `Pending`
  `payment_transactions` row keyed on the idempotency key with a unique index.
  If the cache entry has expired but the row survives, a repeat `charge()`
  returns the existing transaction's state instead of charging again.

Persisting the row before the gateway call also means a webhook that races the
gateway response lands on a row that already exists.

Pass a stable `idempotencyKey()` — the order id is ideal. See
[Charging a customer](/guides/charging-a-customer#idempotency-keys).

## Retries

When a retry-capable charge throws `GatewayUnavailableException`, parakit runs
up to `retry.max_attempts` attempts (3 by default), with exponential backoff
plus random jitter — `base_delay_ms` (200ms) doubles each attempt.

Two rules keep retries safe:

- Only `GatewayUnavailableException` is retried. Any other exception fails the
  transaction and is rethrown immediately — no retry.
- Shipped retry-capable drivers reuse a stable provider-side request/order
  identifier. Local cache and database idempotency protect repeated
  `charge()` calls, but provider-side identity is what makes an in-flight HTTP
  retry safe.

FIB create does not accept a merchant idempotency identifier, so its driver
does not retry an uncertain create. When a transient create failure is
exhausted, the write-ahead transaction remains `Pending`: a timeout or 5xx may
have happened after the provider accepted the request, so parakit never labels
that unknown remote outcome as a terminal failure. The exception is rethrown.

## Circuit breaker

The circuit breaker is tracked **per named gateway config**, so one failing
config never trips another. Each failed attempt increments a counter. After
`circuit_breaker.failure_threshold` failures (5 by default) the circuit opens.

While the circuit is open, `charge()` fails fast — it throws
`GatewayUnavailableException` immediately, without touching the gateway. That
keeps your request workers from piling up on a dead gateway.

After `circuit_breaker.cooldown_seconds` (30 by default) the circuit closes
again and the next charge is allowed through. A successful charge resets the
failure counter.

::: tip
The `CircuitOpened` event exists for alerting on this transition. Listen for it
to page on a gateway outage. See [the events table](/guides/handling-webhooks#events).
:::

## Timeouts

Every gateway HTTP call is bounded by `parakit.reliability.timeout_seconds` (15
by default). A call that overruns feeds the circuit breaker and, for drivers
with safe provider-side idempotency, the retry loop.

The `GatewayTimeout` event carries the `gateway`, the `endpoint`, and the
`durationMs` for observability.

## The lost-webhook sweeper

If a gateway never delivers a webhook, a transaction would sit `Pending`
forever. The sweeper polls gateway status to recover those:

```bash
php artisan parakit:transactions:sweep-pending
```

It selects `Pending` and `Processing` transactions that are:

- **older than** `sweeper.older_than_minutes` (5) — by `updated_at`, and
- **younger than** `sweeper.max_age_hours` (24) — by `created_at`, and
- have a `gateway_transaction_id` to poll.

For each, it calls the gateway's status endpoint (gateways that implement
`SupportsStatusCheck`) and applies any status change under a row lock, so it
never races webhook processing.

| Config key | Default | Notes |
|---|---|---|
| `sweeper.enabled` | `true` | Set `false` to disable the scheduled run. |
| `sweeper.older_than_minutes` | `5` | Skip transactions touched more recently. |
| `sweeper.max_age_hours` | `24` | Stop chasing transactions older than this. |

`--gateway=` and `--older-than=` flags override the gateway filter and the age
threshold for a single run.

When `sweeper.enabled` is `true`, parakit schedules `parakit:transactions:sweep-pending`
every five minutes (with `withoutOverlapping()`) — no manual cron entry needed,
as long as Laravel's scheduler is running.

::: warning
A payment the sweeper recovers fires the same lifecycle event a webhook would.
The same success can reach your listeners from both the webhook and the
sweeper. Keep listeners idempotent — see
[Handling webhooks](/guides/handling-webhooks).
:::

## Correlation IDs

Every charge is assigned a correlation id that follows it across `charge →
webhook → status → refund` in the `payment_logs` table. The `AssignCorrelationId`
middleware also accepts a caller-supplied `X-Correlation-Id` header when it
looks safe, and echoes it on the response. Grep one id to reconstruct a
payment's whole life.

Under Octane and other long-running runtimes the id is reset after each request,
so workers never leak it into the next request.
