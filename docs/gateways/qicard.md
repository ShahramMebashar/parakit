---
title: QiCard
---

# QiCard

[QiCard](https://www.qi.iq) is a 3DS-enabled card payment gateway used widely across Iraq. A charge returns a hosted payment-form URL — the customer enters their card details on QiCard's page (with 3D Secure if their issuer requires it). When the payment terminates, QiCard POSTs a signed notification to your webhook and redirects the customer to your finish URL.

QiCard is parakit's first gateway with a **provable** webhook signature — every callback is signed with QiCard's RSA-2048 private key, and parakit verifies it against the public key you register.

## Credentials

Configure QiCard under `parakit.gateways.qicard` in `config/parakit.php`. Every key reads from an environment variable.

| Config key | Env var | Default | Required | What it is |
|---|---|---|---|---|
| `base_url` | `QICARD_BASE_URL` | `https://uat-sandbox-3ds-api.qi.iq` | Yes | QiCard API host. Defaults to the sandbox — set the production host for live payments. |
| `username` | `QICARD_USERNAME` | — | Yes | HTTP Basic Auth username (the integration's `client_id`). Issued by QiCard. |
| `password` | `QICARD_PASSWORD` | — | Yes | HTTP Basic Auth password. Issued by QiCard. Keep it out of version control. |
| `terminal_id` | `QICARD_TERMINAL_ID` | — | Yes | Merchant terminal identifier. Sent on every request as the `X-Terminal-Id` header. |
| `locale` | `QICARD_LOCALE` | `en_US` | No | UI locale for the hosted payment form (`en_US`, `ar_IQ`, …). Omit to use the terminal's default. |
| `public_key` | `QICARD_PUBLIC_KEY` | — | No, but **strongly recommended** | QiCard's RSA-2048 public key in PEM format. Used to verify webhook `X-Signature` headers. Obtain it from QiCard support. |
| `finish_payment_url` | `QICARD_FINISH_PAYMENT_URL` | — | No | Default URL QiCard redirects the customer to after the payment finishes. A per-charge `returnUrl()` overrides it. |
| `notification_url` | `QICARD_NOTIFICATION_URL` | — | No | Default webhook URL — point it at the QiCard webhook route below. A per-charge `callbackUrl()` overrides it. |

## `.env` example

```env
QICARD_BASE_URL=https://uat-sandbox-3ds-api.qi.iq
QICARD_USERNAME=paymentgatewaytest
QICARD_PASSWORD=WHaNFE5C3qlChqNbAzH4
QICARD_TERMINAL_ID=237984
QICARD_LOCALE=en_US
QICARD_FINISH_PAYMENT_URL=https://your-app.test/checkout/finish
QICARD_NOTIFICATION_URL=https://your-app.test/payments/webhooks/qicard

# Multi-line PEM via .env. Wrap in quotes and use \n for newlines, or
# load from a file path and resolve in config/parakit.php.
QICARD_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqh...\n-----END PUBLIC KEY-----"
```

::: tip Sandbox credentials
The example values above are QiCard's **sandbox** credentials. The matching test card is `5213720304238582`, CVV `642`, expiry `01/32`, OTP `123123`. None of these work in production.
:::

## Payment flow

```php
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\Enums\Currency;

$response = Payment::driver('qicard')
    ->for($order)
    ->amount(25000, Currency::IQD)
    ->description('Order #'.$order->id)
    ->returnUrl('https://your-app.test/checkout/finish/'.$order->id)
    ->charge();
```

`charge()` returns a `PaymentResponse` with `status = PaymentStatus::Pending`. The customer hasn't paid yet — your job is to send them to QiCard's hosted form.

| Field | Populated | Source |
|---|---|---|
| `gatewayTransactionId` | Yes | QiCard `paymentId` — store it; you need it for status checks, cancels and refunds. |
| `redirectUrl` | Yes | QiCard `formUrl` — the hosted card-input page. **Redirect the customer here.** |
| `qrCode` / `readableCode` / `deepLink` | No | QiCard is a redirect-only flow. |
| `expiresAt` | No | QiCard's form has its own expiry on the gateway side; parakit doesn't track it. |

```php
return redirect()->away($response->redirectUrl);
```

The customer pays on QiCard's page. If their card requires 3D Secure, QiCard handles the challenge inside the same hosted form — parakit doesn't see the intermediate `AUTHENTICATION_REQUIRED` / `AUTHENTICATED` states; they all map to `Pending` until QiCard reports a terminal `SUCCESS` / `FAILED` / `EXPIRED`.

::: warning IQD only
QiCard settles **IQD** only. Passing `Currency::USD` raises an `InvalidArgumentException` at charge time rather than silently re-tagging the amount.
:::

## Webhook / callback

Point `QICARD_NOTIFICATION_URL` at the QiCard webhook route:

```
POST /payments/webhooks/qicard
```

QiCard delivers a JSON body that includes `paymentId`, `status`, `amount`, `currency`, and `creationDate`. The HTTP request also carries an `X-Signature` header — a base64-encoded RSA-SHA256 signature of the canonical string `paymentId|amount.000|currency|creationDate|status`.

There are two operating modes depending on whether you've configured `public_key`:

### 1. Verified mode (recommended)

When `QICARD_PUBLIC_KEY` is set, every webhook **must** carry a valid `X-Signature`. Parakit verifies it with `openssl_verify($data, $signature, $publicKey, OPENSSL_ALGO_SHA256)` — algorithm pinned, no key-type discovery. Tampered or missing signatures raise `InvalidWebhookSignatureException`; the controller returns `401` and fires `WebhookVerificationFailed` (with sensitive headers redacted). QiCard then retries delivery.

### 2. Fallback mode (when no public key is configured)

If `public_key` is left blank, parakit cannot prove the webhook's authenticity from the body alone. Rather than trust the inbound payload, it logs `parakit.qicard.webhook.unverified` and re-fetches the authoritative payment state from QiCard's `/status` endpoint server-to-server. The fetched body becomes the source of truth.

This mode is useful in development but **not recommended for production** — the round-trip latency is real, and you lose the audit trail that signed webhooks give you.

### Replays & duplicates

QiCard repeats notifications until it gets a `200 OK`. Parakit's webhook processor deduplicates on `(gateway, event_id)` where `event_id` is `paymentId:status` — so a re-delivery of the same final state is recognised, returned `200`, and not re-applied. See [Handling webhooks](/guides/handling-webhooks) for the broader replay-tolerance and amount-integrity guarantees.

## Status checks

```php
$response = Payment::driver('qicard')->status($paymentId);
// $response->status — Paid / Failed / Expired / Pending / Cancelled
```

The status response carries QiCard's `confirmedAmount` (the settled amount after 3DS) when present, falling back to the original `amount`.

## Cancel

`QiCardGateway` implements `SupportsCancel`. Cancel a payment that hasn't been processed yet:

```php
use Froshly\Parakit\Contracts\SupportsCancel;

$gateway = Payment::driver('qicard');

if ($gateway instanceof SupportsCancel) {
    $response = $gateway->cancel($paymentId);
    // $response->status === PaymentStatus::Cancelled on success
}
```

QiCard returns the full payment object on cancel. Parakit looks at the top-level `canceled` flag combined with the last `cancels[].successfully` to decide whether the payment is now terminally Cancelled or still in its prior state.

## Refunds

`QiCardGateway` implements `SupportsRefund` — both full and partial refunds are supported.

```php
use Froshly\Parakit\Contracts\SupportsRefund;
use Froshly\Parakit\DTOs\RefundRequest;

$gateway = Payment::driver('qicard');

if ($gateway instanceof SupportsRefund) {
    $refund = $gateway->refund(new RefundRequest(
        transactionId: $paymentId,
        amount: 25000,                       // can be less than the original for partial refunds
        reason: 'Customer returned the item', // sent as QiCard's `message` field
    ));
}
```

When QiCard answers `status: "SUCCESS"`, the refund is final and the `RefundResponse` carries the QiCard `refundId`. A `status: "FAILED"` produces a failed `RefundResponse` with the QiCard `details.resultCode` and `resultDescription` preserved on `PaymentError`. A `status: "PROCESSING"` indicates an in-flight refund; surface it as a failure and re-poll, or treat it as success-pending depending on your business logic.

## Gotchas

- **Amounts in REST bodies use 2 decimal places** (`"5000.00"`), but the **webhook signature canonical string uses 3** (`"5000.000"`). Parakit handles the format split internally — don't reach into either.
- **`requestId` ≤ 36 characters.** Parakit derives a deterministic 36-char request ID from the parakit idempotency key so a retried charge sends the same ID twice (avoiding a duplicate payment on QiCard's side).
- **`additionalInfo` is capped at 10 string-typed properties.** Anything passed via `PaymentRequest::metadata` is cast to string and truncated to the first 10 keys.
- **No `confirmedAmount` for pre-3DS states.** Until QiCard reports `SUCCESS`, the status response carries only the original `amount`.
- **The sandbox is shared.** Don't rely on the sample card number being available exclusively — flaky tests in CI may be sandbox congestion, not your code.
