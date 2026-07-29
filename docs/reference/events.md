---
title: Events
---

# Events

Parakit dispatches plain Laravel events at each significant point in a
payment's life — when a charge starts, when it succeeds or fails, when a
webhook arrives, and when the reliability layer trips. Listen for them to run
your own side effects: fulfil orders, notify customers, alert on gateway
trouble.

Every event lives under the `Froshly\Parakit\Events` namespace and uses the
`Illuminate\Foundation\Events\Dispatchable` trait. Register a listener the
usual way:

```php
use Froshly\Parakit\Events\PaymentSucceeded;
use Illuminate\Support\Facades\Event;

Event::listen(function (PaymentSucceeded $event) {
    // $event->transaction is a Froshly\Parakit\Models\PaymentTransaction
    $order = Order::where('reference', $event->transaction->reference)->first();
    $order?->markPaid();
});
```

## Quick reference

| Event | Fires when | Payload |
| --- | --- | --- |
| `PaymentInitiated` | A charge is persisted, before the gateway call. | `gateway`, `request`, `transaction` |
| `PaymentSucceeded` | A transaction reaches `Paid`. | `transaction` |
| `PaymentFailed` | A transaction reaches `Failed`. | `transaction` |
| `PaymentCancelled` | A transaction reaches `Cancelled` or `Expired`. | `transaction` |
| `PaymentRefunded` | A transaction is refunded (full or partial). | `transaction` |
| `WebhookReceived` | A webhook is verified and parsed. | `payload` |
| `WebhookVerificationFailed` | An inbound webhook fails verification. | `gateway`, `reason`, `headers` |
| `GatewayTimeout` | A gateway HTTP call exceeds the timeout. | `gateway`, `endpoint`, `durationMs` |
| `CircuitOpened` | The circuit breaker opens for a gateway. | `gateway` |

All payload properties are `readonly`.

## Payment lifecycle events

### `PaymentInitiated`

`Froshly\Parakit\Events\PaymentInitiated`

Fires after `charge()` has persisted the write-ahead `PaymentTransaction` row
(status `Pending`) and before the gateway HTTP call. The transaction is
available so listeners can link it to their own domain models.

| Property | Type | Description |
| --- | --- | --- |
| `gateway` | `string` | Gateway key handling the charge. |
| `request` | `Froshly\Parakit\DTOs\PaymentRequest` | The request being charged. |
| `transaction` | `Froshly\Parakit\Models\PaymentTransaction` | The persisted `Pending` transaction row. |

### `PaymentSucceeded`

`Froshly\Parakit\Events\PaymentSucceeded`

Fires when a transaction reaches the `Paid` status — whether confirmed by a
webhook or by [`parakit:transactions:sweep-pending`](/reference/commands).

| Property | Type | Description |
| --- | --- | --- |
| `transaction` | `Froshly\Parakit\Models\PaymentTransaction` | The paid transaction. |

### `PaymentFailed`

`Froshly\Parakit\Events\PaymentFailed`

Fires when a transaction reaches the `Failed` status.

| Property | Type | Description |
| --- | --- | --- |
| `transaction` | `Froshly\Parakit\Models\PaymentTransaction` | The failed transaction. |

### `PaymentCancelled`

`Froshly\Parakit\Events\PaymentCancelled`

Fires when a transaction reaches `Cancelled` or `Expired`. The sweeper maps
both statuses to this event.

| Property | Type | Description |
| --- | --- | --- |
| `transaction` | `Froshly\Parakit\Models\PaymentTransaction` | The cancelled or expired transaction. |

### `PaymentRefunded`

`Froshly\Parakit\Events\PaymentRefunded`

Fires when a transaction is refunded, fully or partially. Check the
transaction's `status` and `refunded_amount` to tell the two apart.

| Property | Type | Description |
| --- | --- | --- |
| `transaction` | `Froshly\Parakit\Models\PaymentTransaction` | The refunded transaction. |

See [Refunds](/guides/refunds) for the refund API.

## Webhook events

### `WebhookReceived`

`Froshly\Parakit\Events\WebhookReceived`

Fires when an inbound webhook has been verified and parsed into a
`WebhookPayload`. This is dispatched before parakit applies the status change,
so it reflects what the gateway reported.

| Property | Type | Description |
| --- | --- | --- |
| `payload` | `Froshly\Parakit\DTOs\WebhookPayload` | The verified, parsed webhook payload. |

### `WebhookVerificationFailed`

`Froshly\Parakit\Events\WebhookVerificationFailed`

Fires when an inbound webhook fails verification — a bad signature, an
expired timestamp, or a malformed body. Listen for it to alert on probing or
on a misconfigured gateway secret.

| Property | Type | Description |
| --- | --- | --- |
| `gateway` | `string` | Gateway key the webhook was posted to. |
| `reason` | `string` | Why verification failed. |
| `headers` | `array` | Request headers received (defaults to `[]`). |

See [Handling webhooks](/guides/handling-webhooks).

## Reliability events

These fire from parakit's reliability layer. Listen for them to feed your
monitoring and alerting.

### `GatewayTimeout`

`Froshly\Parakit\Events\GatewayTimeout`

Fires when a charge's gateway HTTP call times out or the connection fails
(the configured timeout is `parakit.reliability.timeout_seconds`). The timeout
stays retryable — this event fires on each timed-out attempt.

| Property | Type | Description |
| --- | --- | --- |
| `gateway` | `string` | Gateway key that timed out. |
| `endpoint` | `string` | The endpoint that was being called. |
| `durationMs` | `int` | How long the call ran before timing out, in milliseconds. |

### `CircuitOpened`

`Froshly\Parakit\Events\CircuitOpened`

Fires when the circuit breaker opens for a gateway after repeated failures
(`parakit.reliability.circuit_breaker.failure_threshold`). While the circuit
is open, calls to that gateway fail fast until the cooldown elapses.

| Property | Type | Description |
| --- | --- | --- |
| `gateway` | `string` | Gateway key whose circuit opened. |

See [Reliability](/guides/reliability) for how timeouts and the circuit
breaker work.

## Registering a listener class

For anything beyond a closure, use a dedicated listener class. In Laravel 12+,
event-to-listener wiring is discovered automatically — type-hint the event in
the listener's `handle()` method:

```php
namespace App\Listeners;

use Froshly\Parakit\Events\PaymentSucceeded;

class FulfilOrder
{
    public function handle(PaymentSucceeded $event): void
    {
        $transaction = $event->transaction;

        Order::where('reference', $transaction->reference)
            ->first()
            ?->fulfil();
    }
}
```

To register listeners explicitly, add them in a service provider's `boot()`
method:

```php
use App\Listeners\FulfilOrder;
use Froshly\Parakit\Events\PaymentSucceeded;
use Illuminate\Support\Facades\Event;

public function boot(): void
{
    Event::listen(PaymentSucceeded::class, FulfilOrder::class);
}
```

::: tip
Webhook and sweeper events can arrive more than once for the same
transaction — gateways retry webhooks, and the sweeper may run before a late
webhook lands. Make listeners that fulfil orders or send mail idempotent.
:::
