---
title: Refunds
---

# Refunds

To refund a payment, get the gateway and call `refund()` with a `RefundRequest`.
Refunds are an **optional capability** — not every gateway supports them — so
check capability first.

```php
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\Contracts\SupportsRefund;
use Froshly\Parakit\DTOs\RefundRequest;

$gateway = Payment::driver($transaction->gateway);

if (! $gateway instanceof SupportsRefund) {
    abort(422, 'This gateway does not support refunds.');
}

$response = $gateway->refund(new RefundRequest(
    transactionId: $transaction->gateway_transaction_id,
    amount: $transaction->amount,
    reason: 'Customer requested a refund',
));

if (! $response->success) {
    report($response->error?->rawMessage ?? 'refund failed');
}
```

`refund()` is defined by the `SupportsRefund` contract, not by the base
`PaymentGateway` interface — that is why you check for it.

## Which gateways support refunds

| Gateway | Refunds | Notes |
|---|---|---|
| FIB | Yes | Full refund only; bounded by the `refundable_for` window. |
| ZainCash | Yes | Full reversal only — the refund amount must equal the original charge. |
| FastPay | Yes | Pushed back to the original payer's wallet. |
| QiCard | Yes | Full and partial refunds supported. |
| Nass Pay | **No** | Driver does not implement `SupportsRefund`. |
| Nass Wallet | **No** | Driver does not implement `SupportsRefund`. |

::: warning
FIB and ZainCash refund the **whole** payment. ZainCash rejects a partial
amount outright. Do not assume partial refunds work — confirm against the
gateway you are on.
:::

## Checking capability at runtime

`Payment::driver()` returns the gateway object directly. Test it against
`SupportsRefund` with `instanceof`:

```php
use Froshly\Parakit\Contracts\SupportsRefund;

$canRefund = Payment::driver($transaction->gateway) instanceof SupportsRefund;
```

Use this to show or hide a refund button, and to fail loudly before issuing a
call a gateway cannot honour.

## The `RefundRequest` DTO

`RefundRequest` is a readonly DTO:

| Property | Type | Notes |
|---|---|---|
| `transactionId` | `string` | The **gateway's** transaction id (`gateway_transaction_id`), not your reference. |
| `amount` | `int` | Minor units. Throws `InvalidArgumentException` if not positive. |
| `reason` | `?string` | Optional, free text. |
| `idempotencyKey` | `?string` | Optional. Pass a stable key to make a retried refund safe. |

For gateways that only do full refunds, pass the original charge amount as
`amount`.

## The `RefundResponse` DTO

`refund()` returns a readonly `RefundResponse`:

| Property | Type | Notes |
|---|---|---|
| `success` | `bool` | Whether the refund settled. |
| `refundId` | `?string` | The gateway's refund/reversal id. |
| `refundedAmount` | `int` | Minor units actually refunded. |
| `error` | `?PaymentError` | Set when `success` is `false`. |
| `raw` | `array` | The gateway response body. Fresh refund calls return it untouched; idempotency replays may return a redacted or empty copy according to `parakit.raw_payloads`. |

`RefundResponse` has no `failed()` method — branch on `success` directly.

## FIB's refund window

FIB only allows a refund within a time window after the charge. That window is
set by the `refundable_for` config under the FIB gateway, an ISO-8601 duration
(`P7D` — seven days — by default):

```env
FIB_REFUNDABLE_FOR=P7D
```

You can override it per charge through `metadata`:

```php
Payment::for($order)
    ->driver('fib')
    ->amount(25_000, Currency::IQD)
    ->description("Order #{$order->id}")
    ->metadata(['refundable_for' => 'P30D'])
    ->charge();
```

A refund after the window has closed fails at FIB — `refund()` reports it
through `RefundResponse`.

## After a refund settles

The synchronous `RefundResponse` confirms the gateway accepted the refund. The
transaction's status moves to `Refunded` (or `PartiallyRefunded`) when the
gateway's webhook arrives, and that fires `PaymentRefunded`:

```php
use Froshly\Parakit\Events\PaymentRefunded;

Event::listen(PaymentRefunded::class, function (PaymentRefunded $event) {
    Order::findOrFail($event->transaction->reference)->markRefunded();
});
```

Drive your bookkeeping from `PaymentRefunded`, the same way you handle
[`PaymentSucceeded`](/guides/handling-webhooks) — the webhook, not the
synchronous response, is the source of truth.
