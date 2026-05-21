---
title: Handling webhooks
---

# Handling webhooks

A gateway tells you a payment really completed by calling back to your app.
Parakit registers the route, verifies the call, deduplicates it, updates the
transaction, and fires an event. You write a listener — nothing else.

```php
use Froshly\Parakit\Events\PaymentSucceeded;
use Illuminate\Support\Facades\Event;

Event::listen(PaymentSucceeded::class, function (PaymentSucceeded $event) {
    Order::findOrFail($event->transaction->reference)->markPaid();
});
```

## The auto-registered routes

Parakit registers two `POST` routes for every gateway. With the default
`route_prefix` of `payments/webhooks`:

| Route | Name | Notes |
|---|---|---|
| `POST /payments/webhooks/{gateway}` | `parakit.webhook` | The webhook endpoint. |
| `POST /payments/webhooks/{gateway}/callback` | `parakit.webhook.callback` | Alias — same controller. Some gateways (NassWallet) append `/callback` to the configured callback URL. |

Both routes run the middleware in `parakit.webhooks.middleware` (`['api']` by
default) plus `AssignCorrelationId`.

The `{gateway}` segment is the **config key**, not the driver type. A named
config `fib_branch` is reached at `POST /payments/webhooks/fib_branch`.

Point each gateway's callback URL at the matching route. You can build it with
the route name:

```php
route('parakit.webhook', ['gateway' => 'fib']);
```

## How `WebhookController` handles a call

`WebhookController` is a single-action controller. For every incoming webhook it:

1. Resolves the driver for the `{gateway}` segment. An unknown gateway returns
   `404`.
2. Calls the driver's `handleWebhook()`, which verifies the request and returns
   a `WebhookPayload`. A signature failure throws
   `InvalidWebhookSignatureException`, which fires
   [`WebhookVerificationFailed`](#events) and returns `401`. Any other driver
   exception returns `500`.
3. Rejects stale calls — a payload whose `occurredAt` is older than
   `tolerance_seconds` returns `400` ("stale"). This is replay protection.
4. Fires [`WebhookReceived`](#events).
5. Records the event on the `payment_webhook_events` table. A duplicate
   `(gateway, event_id)` returns `200` ("duplicate") without reprocessing.
6. Applies the payload to the matching `payment_transactions` row and fires the
   matching lifecycle event.

A handled webhook returns `200` ("ok").

::: tip
FIB callbacks deliver only `{ id, status }`. The FIB driver does not trust
that body — it re-fetches the payment from FIB's status endpoint with its own
authenticated client. The status endpoint is the trust boundary.
:::

## The `WebhookPayload` DTO

`handleWebhook()` returns a readonly `WebhookPayload` — the gateway-neutral shape
parakit works with:

| Property | Type | Notes |
|---|---|---|
| `gateway` | `string` | The config key that received it. |
| `gatewayTransactionId` | `string` | The gateway's transaction id. |
| `reference` | `string` | Your reference. |
| `status` | `PaymentStatus` | The new status. |
| `amount` | `int` | Minor units. A `Paid` webhook whose `amount` disagrees with the stored charge — `0` included — is treated as a mismatch (see `on_amount_mismatch`). |
| `currency` | `Currency` | |
| `eventId` | `string` | Drives deduplication. |
| `occurredAt` | `DateTimeImmutable` | Drives the staleness check. |
| `error` | `?PaymentError` | Set on a failure payload. |
| `raw` | `array` | The gateway's untouched body. |

## Idempotent delivery

Gateways redeliver webhooks. Parakit makes that safe at two layers:

- **Database** — `payment_webhook_events` has a unique index on
  `(gateway, event_id)`. A redelivered event hits that index, is recognised as
  a duplicate, and returns `200` without reprocessing or firing events again.
- **State machine** — the transaction status transitions through a fixed graph.
  An illegal transition (e.g. `Paid → Pending`) is logged and skipped, so a
  late or out-of-order webhook never rolls a payment backwards.

The status change, and therefore the lifecycle event, fires only when the
status actually changed. A duplicate `Paid` webhook is a no-op.

## `tolerance_seconds`

`parakit.webhooks.tolerance_seconds` (300 by default) is the replay window. A
webhook whose `occurredAt` is older than this is rejected with `400`. Raise it
if a gateway is slow to deliver; lower it to tighten replay protection.

## `on_amount_mismatch`

When a `Paid` webhook carries an amount that disagrees with the stored charge
amount, parakit logs a `parakit.webhook.amount_mismatch` warning.
`parakit.webhooks.on_amount_mismatch` controls what happens next:

| Value | Behaviour |
|---|---|
| `log` (default) | Logs the warning and applies the status change anyway. |
| `reject` | Logs the warning, marks the event processed, and refuses the status transition. The transaction does **not** move to `Paid`. |

A reported `amount` of `0` is included — a buggy driver claiming "Paid, amount
0" would otherwise silently settle the row for the full charged amount.

## Events {#events}

Parakit ships nine events. Listen anywhere — a service provider, a listener
class, `Event::listen()`.

| Event | Payload | Fires when |
|---|---|---|
| `PaymentInitiated` | `gateway`, `request`, `transaction` | `charge()` persisted the pending transaction row, before the gateway call. |
| `PaymentSucceeded` | `transaction` | A webhook (or the sweeper) moved a transaction to `Paid`. |
| `PaymentFailed` | `transaction` | A transaction moved to `Failed`. |
| `PaymentCancelled` | `transaction` | A transaction moved to `Cancelled` or `Expired`. |
| `PaymentRefunded` | `transaction` | A transaction moved to `Refunded` or `PartiallyRefunded`. |
| `WebhookReceived` | `payload` | Any webhook passed verification, before the status is applied. |
| `WebhookVerificationFailed` | `gateway`, `reason`, `headers` | A webhook failed signature verification. Sensitive headers are redacted. |
| `GatewayTimeout` | `gateway`, `endpoint`, `durationMs` | A gateway HTTP call exceeded its timeout. |
| `CircuitOpened` | `gateway` | The circuit breaker opened for a gateway. |

`PaymentInitiated` carries the `PaymentRequest` and the `PaymentTransaction`.
The five other payment events carry only the `PaymentTransaction` model — read
`->reference`, `->status`, `->amount` from it.

::: warning
`PaymentSucceeded`, `PaymentFailed`, `PaymentCancelled`, and `PaymentRefunded`
fire from both webhook handling and the [sweeper](/guides/reliability). The same
success can be reported twice. Keep listeners idempotent.
:::

### An example listener

```php
namespace App\Listeners;

use Froshly\Parakit\Events\PaymentSucceeded;

class FulfillOrder
{
    public function handle(PaymentSucceeded $event): void
    {
        $order = Order::findOrFail($event->transaction->reference);

        // Idempotent: a redelivered webhook or a sweeper pass must not
        // fulfill the order twice.
        if ($order->isPaid()) {
            return;
        }

        $order->markPaid();
        $order->customer->notify(new OrderConfirmed($order));
    }
}
```

Treat fulfillment as replayable. Make `markPaid()` a no-op when the order is
already paid — webhooks and the sweeper can both report the same outcome.
