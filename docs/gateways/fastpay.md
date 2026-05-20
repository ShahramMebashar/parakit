---
title: FastPay
---

# FastPay

FastPay is a hosted-checkout gateway: you create a payment server-side, then send the customer to a FastPay-hosted page to pay. `charge()` returns a `redirectUrl` and nothing else. FastPay also supports refunds.

## Credentials

Every key below lives under `gateways.fastpay` in `config/parakit.php`.

| Config key | Env var | Default | Required | What it is |
| --- | --- | --- | --- | --- |
| `base_url` | `FASTPAY_BASE_URL` | `https://staging-pgw.fast-pay.iq` | No | FastPay API host. Switch to the production host FastPay gives you when you go live. |
| `store_id` | `FASTPAY_STORE_ID` | — | Yes | Merchant store identifier. Sent in the body of every API call. Issued by FastPay. |
| `store_password` | `FASTPAY_STORE_PASSWORD` | — | Yes | Merchant store password. FastPay has no OAuth — `store_id` + `store_password` authenticate every request. |
| `refund_secret_key` | `FASTPAY_REFUND_SECRET_KEY` | — | Only for refunds | A separate secret used solely on the refund call. Required if you intend to refund; not used for charges. Issued by FastPay. |
| `success_url` | `FASTPAY_SUCCESS_URL` | — | No | Browser redirect after a successful payment. Used as the default when a charge does not pass a `returnUrl`. |
| `cancel_url` | `FASTPAY_CANCEL_URL` | — | No | Browser redirect when the customer cancels. |
| `callback_url` | `FASTPAY_CALLBACK_URL` | — | No | Server-to-server IPN URL. Used as the default when a charge does not pass a `callbackUrl`. |

## `.env` example

```env
FASTPAY_BASE_URL=https://staging-pgw.fast-pay.iq
FASTPAY_STORE_ID=your-store-id
FASTPAY_STORE_PASSWORD=your-store-password
FASTPAY_REFUND_SECRET_KEY=your-refund-secret-key
FASTPAY_SUCCESS_URL=https://your-app.test/checkout/success
FASTPAY_CANCEL_URL=https://your-app.test/checkout/cancel
FASTPAY_CALLBACK_URL=https://your-app.test/payments/webhooks/fastpay
```

## Payment flow

Charge an order:

```php
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\Enums\Currency;

$response = Payment::driver('fastpay')
    ->for($order)
    ->amount(25_000, Currency::IQD)   // 25,000 IQD — FastPay settles IQD only
    ->description('Order #1042')
    ->charge();
```

`charge()` derives an 8–32 character alphanumeric `order_id` from the idempotency key and calls FastPay's `payment/initiation` endpoint with the store credentials and a single-line cart. The returned `PaymentResponse` carries:

| Field | Populated | Notes |
| --- | --- | --- |
| `redirectUrl` | Yes | FastPay's `redirect_uri` — the hosted checkout page. Send the customer here. |
| `gatewayTransactionId` | Yes | The derived `order_id`. Store it — IPNs, `status()` and `refund()` are keyed on it. |
| `status` | Yes | Always `PaymentStatus::Pending` at this point. |
| `qrCode`, `readableCode`, `deepLink`, `expiresAt` | No | FastPay does not return these. |

Send the customer to the hosted page:

```php
return redirect()->away($response->redirectUrl);
```

The payment is not final yet — wait for the IPN (below) before marking the order paid.

::: tip Status check
`FastPayGateway` implements `SupportsStatusCheck`. Call `Payment::driver('fastpay')->status($gatewayTransactionId)` to re-fetch the authoritative state. FastPay answers `404` for an order it has not seen a payment for — the gateway maps that to `Pending` rather than treating it as an error.
:::

## Webhook / callback

Point `FASTPAY_CALLBACK_URL` at the package webhook route:

```
POST /payments/webhooks/fastpay
```

That route is registered by the package (name `parakit.webhook`) and handled by `WebhookController`. The `payments/webhooks` prefix comes from `webhooks.route_prefix`.

FastPay's IPN carries **no signature**. `handleWebhook()` reads only `order_id` from the request, then calls FastPay's `payment/validate` endpoint server-to-server and uses that authoritative response. A missing `order_id` or any failure on the `validate` call — including a not-found order — is treated as a verification failure and the controller answers `401`.

## Refunds

FastPay supports refunds — `FastPayGateway` implements `SupportsRefund`. Resolve the gateway, confirm it supports refunds, and call `refund()`:

```php
use Froshly\Parakit\Contracts\SupportsRefund;
use Froshly\Parakit\DTOs\RefundRequest;

$gateway = Payment::driver('fastpay');

if ($gateway instanceof SupportsRefund) {
    $result = $gateway->refund(new RefundRequest(
        transactionId: $gatewayTransactionId,  // the FastPay order_id
        amount: 25_000,                        // minor units — full or partial
        reason: 'Customer returned the item',
    ));

    if ($result->success) {
        // $result->refundId        — FastPay invoice id (may be null)
        // $result->refundedAmount  — amount refunded, in minor units
    } else {
        // $result->error — a PaymentError explaining the rejection
    }
}
```

`refund()` first calls `validate` to read the original payer's mobile number, then pushes the refund to that wallet — funds always return to whoever paid. It needs `refund_secret_key` to be set; FastPay rejects the call without it. The refund is refused before any gateway call if the amount is larger than what was received.

## Gotchas

- **IQD only.** `charge()` throws `InvalidArgumentException` for any currency other than `IQD`.
- **HTTP 200 for everything.** FastPay answers HTTP `200` for both success and failure. The real outcome is the body `code` field — `200` is success; `422` (bad credentials) and `404` (not found) are errors. Non-`200` bodies raise a `FastPayApiException`, which is non-retryable and carries the `apiCode` so a `404` can be told apart from a real failure.
- **`refund_secret_key` is separate.** It is not the same as `store_password` and is used only on the refund call. A charge works without it; a refund does not.
- **Refunds go to the original payer.** `RefundRequest` carries no mobile number — the gateway looks up the payer's `customer_mobile_number` via `validate`. If FastPay returns no payer number, the refund fails with a clear message.
- **Order id format.** The `order_id` is the first 24 characters of the sha256 idempotency key — alphanumeric and within FastPay's 8–32 character limit. It stays stable across charge retries, so a retried charge never double-creates a payment.
- **Error mapping.** FastPay returns no machine error codes, so `FastPayErrorMap` matches substrings of the human message: "store id"/"store password"/"secret key" → `InvalidCredentials`, "already refunded" → `DuplicateTransaction`, "amount" → `InvalidAmount`.
- **Status mapping.** `validate`'s `data.status` is only ever `Success` for a paid order. Any other value logs `parakit.fastpay.unknown_status` and maps to `Pending`; an unpaid order surfaces as a `404` and is also treated as `Pending`.
