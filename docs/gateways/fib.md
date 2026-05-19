---
title: FIB
---

# FIB

[First Iraqi Bank (FIB)](https://fib.iq) is a QR-driven gateway: a charge returns a QR code, a short human-readable code, and a deep link into the FIB mobile app. The customer pays inside their FIB app — there is no hosted checkout page to redirect to.

## Credentials

Configure FIB under `parakit.gateways.fib` in `config/parakit.php`. Every key reads from an environment variable.

| Config key | Env var | Default | Required | What it is |
|---|---|---|---|---|
| `base_url` | `FIB_BASE_URL` | `https://fib.stage.fib.iq` | Yes | FIB API host. Defaults to the staging host — set the production host for live payments. |
| `client_id` | `FIB_CLIENT_ID` | — | Yes | OAuth2 client ID for the `client_credentials` grant. Issued by FIB. |
| `client_secret` | `FIB_CLIENT_SECRET` | — | Yes | OAuth2 client secret. Issued by FIB. Keep it out of version control. |
| `currency` | `FIB_CURRENCY` | `IQD` | No | Default currency label for the gateway. The actual charge currency comes from the `amount()` call. |
| `refundable_for` | `FIB_REFUNDABLE_FOR` | `P7D` | No | ISO-8601 duration the payment stays refundable (e.g. `P7D` = 7 days, `PT12H` = 12 hours). Sent to FIB at charge time. |
| `expires_in` | `FIB_EXPIRES_IN` | — | No | ISO-8601 duration the QR/code stays payable. Omitted when unset — FIB applies its own default. |
| `category` | `FIB_CATEGORY` | — | No | FIB transaction category (`ERP`, `POS`, `ECOMMERCE`, …). Omitted when unset — FIB records it as `UNKNOWN`. |
| `callback_url` | `FIB_CALLBACK_URL` | — | No | URL FIB POSTs to on status changes. Point it at the FIB webhook route (see below). A per-charge `callbackUrl()` overrides it. |

## `.env` example

```env
FIB_BASE_URL=https://fib.stage.fib.iq
FIB_CLIENT_ID=your-fib-client-id
FIB_CLIENT_SECRET=your-fib-client-secret
FIB_CURRENCY=IQD
FIB_REFUNDABLE_FOR=P7D
FIB_EXPIRES_IN=
FIB_CATEGORY=ECOMMERCE
FIB_CALLBACK_URL=https://your-app.test/payments/webhooks/fib
```

## Payment flow

Charge a customer through the `Payment` facade. FIB amounts in `IQD` are whole dinars — `Currency::IQD` has a minor-unit factor of 1, so pass the dinar value directly.

```php
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\Enums\Currency;

$response = Payment::driver('fib')
    ->for($order)
    ->amount(25000, Currency::IQD)
    ->description('Order #'.$order->id)
    ->charge();
```

`charge()` returns a `PaymentResponse`. FIB's create-payment call is asynchronous, so the response comes back with `status` of `PaymentStatus::Pending` — the customer has not paid yet. For a FIB charge the response carries:

| Field | Populated | Source |
|---|---|---|
| `gatewayTransactionId` | Yes | FIB `paymentId` — store it; you need it for status checks, cancels and refunds. |
| `qrCode` | Yes | FIB `qrCode` — render this for the customer to scan. |
| `readableCode` | Yes | FIB `readableCode` — a short code the customer can type into the FIB app by hand. |
| `deepLink` | Yes | FIB `personalAppLink` — opens the FIB app straight onto this payment. Use it on mobile. |
| `expiresAt` | When FIB returns `validUntil` | When the QR/code stops being payable. |
| `redirectUrl` | No | FIB has no hosted page — this stays `null`. |

Show the customer whichever field fits the surface they are on:

```php
return view('checkout.fib', [
    'qrCode'        => $response->qrCode,        // scan with the FIB app
    'readableCode'  => $response->readableCode,  // type into the FIB app
    'deepLink'      => $response->deepLink,      // tap on mobile
    'expiresAt'     => $response->expiresAt,
    'transactionId' => $response->gatewayTransactionId,
]);
```

The payment completes when the customer pays in the FIB app. Your app learns about it through the webhook below, or by polling `status()`.

::: tip Description length
FIB caps the description at 50 characters. Parakit truncates anything longer instead of failing the charge — keep descriptions short if the exact text matters.
:::

## Webhook / callback

Point `FIB_CALLBACK_URL` at the FIB webhook route:

```
POST /payments/webhooks/fib
```

The `payments/webhooks` prefix comes from `parakit.webhooks.route_prefix`; `fib` is the driver key. The package registers this route automatically.

FIB callbacks deliver only `{ id, status }`, which is not enough to trust. Parakit does not trust the callback body. On every callback `handleWebhook()` takes the `id` and re-fetches the authoritative payment state from FIB's status endpoint server-to-server, using the authenticated client. The status response is the trust boundary.

If the callback has no `id`, or the status re-fetch fails for any reason, parakit raises `InvalidWebhookSignatureException` and the webhook controller responds `401`. FIB then retries delivery.

See [Handling webhooks](/guides/handling-webhooks) for how the resulting `WebhookPayload` updates the transaction.

## Refunds

`FibGateway` implements `SupportsRefund`. `Payment::driver('fib')` returns the gateway instance; check it for the contract, then call `refund()` with a `RefundRequest`.

```php
use Froshly\Parakit\Contracts\SupportsRefund;
use Froshly\Parakit\DTOs\RefundRequest;
use Froshly\Parakit\Enums\Currency;

$gateway = Payment::driver('fib');

if ($gateway instanceof SupportsRefund) {
    $refund = $gateway->refund(new RefundRequest(
        transactionId: $response->gatewayTransactionId, // FIB paymentId
        amount: 25000,
        reason: 'Customer returned the item',
    ));

    // $refund->success, $refund->refundId, $refund->refundedAmount
}
```

FIB's refund endpoint is a full refund of the payment — it takes no amount. Parakit's `RefundResponse` reports `refundedAmount` as the amount you passed in the request, so pass the original charge amount. If FIB returns `200` without a `refundId`, parakit treats it as a failure and throws `GatewayUnavailableException`.

A payment is refundable only inside the `refundable_for` window — an ISO-8601 duration sent to FIB at charge time, default `P7D` (7 days). After the window closes FIB rejects the refund. Override it per charge with `metadata(['refundable_for' => 'PT48H'])`, or globally via `FIB_REFUNDABLE_FOR`.

See [Refunds](/guides/refunds) for the shared refund workflow.

## Gotchas

- **Staging vs production host.** `FIB_BASE_URL` defaults to the staging host `https://fib.stage.fib.iq`. Set the production host explicitly before going live.
- **Access tokens are short-lived.** FIB OAuth2 tokens last about 60 seconds. Parakit caches them per host + client ID and refreshes automatically — but a misconfigured cache store will mean a token call on every request.
- **Currency.** `Currency::IQD` has a minor-unit factor of 1, so `amount(25000, Currency::IQD)` is 25,000 dinars. For `USD` the factor is 100, so pass cents.
- **No redirect URL.** FIB never returns a hosted checkout URL. `redirectUrl` is always `null`; build your UI around `qrCode`, `readableCode` and `deepLink`.
- **`returnUrl()` is optional.** If you pass `returnUrl()`, it becomes FIB's `redirectUri` — where the FIB app sends the user after they finish or cancel. Leave it unset and FIB stays in-app.
- **Unknown status strings.** If FIB introduces a status parakit does not recognise, it logs `parakit.fib.unknown_status` and falls back to `Pending`. Watch your logs for that warning after FIB API changes.
