---
title: ZainCash
---

# ZainCash

[ZainCash](https://zaincash.iq) is a hosted-redirect gateway: a charge returns a `redirectUrl`, and you send the customer to ZainCash's hosted page to complete payment. There is no QR code or deep link to render yourself.

## Credentials

Configure ZainCash under `parakit.gateways.zaincash` in `config/parakit.php`. Every key reads from an environment variable.

| Config key | Env var | Default | Required | What it is |
|---|---|---|---|---|
| `base_url` | `ZAINCASH_BASE_URL` | `https://pg-api-uat.zaincash.iq` | Yes | ZainCash v2 API host. Defaults to the UAT host — set the production host for live payments. |
| `client_id` | `ZAINCASH_CLIENT_ID` | — | Yes | OAuth2 client ID for the `client_credentials` grant on `/oauth2/token`. Issued by ZainCash. |
| `client_secret` | `ZAINCASH_CLIENT_SECRET` | — | Yes | OAuth2 client secret. Authenticates the token endpoint. Distinct from `api_key`. |
| `api_key` | `ZAINCASH_API_KEY` | — | Yes | Merchant API key used to verify callback JWTs (HS256). Distinct from `client_secret`. |
| `scope` | `ZAINCASH_SCOPE` | `payment:read payment:write reverse:write` | No | OAuth2 scopes requested for the access token. `reverse:write` is needed for refunds. |
| `service_type` | `ZAINCASH_SERVICE_TYPE` | `Delivery` | No | ZainCash service type label sent on every charge. A per-charge `metadata(['service_type' => ...])` overrides it. |
| `lang` | `ZAINCASH_LANG` | `en` | No | Hosted-page language. Normalised to `En` / `Ar` / `Ku`; anything else falls back to `En`. |
| `success_url` | `ZAINCASH_SUCCESS_URL` | — | No | Where ZainCash sends the customer after a successful payment. A per-charge `returnUrl()` overrides it. |
| `failure_url` | `ZAINCASH_FAILURE_URL` | — | No | Where ZainCash sends the customer after a failed or cancelled payment. |

## `.env` example

```env
ZAINCASH_BASE_URL=https://pg-api-uat.zaincash.iq
ZAINCASH_CLIENT_ID=your-zaincash-client-id
ZAINCASH_CLIENT_SECRET=your-zaincash-client-secret
ZAINCASH_API_KEY=your-zaincash-api-key
ZAINCASH_SCOPE="payment:read payment:write reverse:write"
ZAINCASH_SERVICE_TYPE=Delivery
ZAINCASH_LANG=en
ZAINCASH_SUCCESS_URL=https://your-app.test/payments/zaincash/success
ZAINCASH_FAILURE_URL=https://your-app.test/payments/zaincash/failure
```

## Payment flow

Charge a customer through the `Payment` facade. ZainCash settles in Iraqi dinars only — pass `Currency::IQD`, which has a minor-unit factor of 1, so the value is whole dinars.

```php
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\Enums\Currency;

$response = Payment::for($order)
    ->driver('zaincash')
    ->amount(25000, Currency::IQD)
    ->description('Order #'.$order->id)
    ->returnUrl(route('checkout.zaincash.return'))
    ->customerPhone('+9647701234567')
    ->charge();
```

`charge()` returns a `PaymentResponse` with `status` of `PaymentStatus::Pending` — the customer has not paid yet. For a ZainCash charge the response carries:

| Field | Populated | Source |
|---|---|---|
| `gatewayTransactionId` | Yes | ZainCash `transactionDetails.transactionId` — store it for status checks and refunds. |
| `redirectUrl` | Yes | ZainCash `redirectUrl` — send the customer here to complete payment. |
| `expiresAt` | When ZainCash returns `expiryTime` | When the hosted payment session expires. |
| `qrCode` / `readableCode` / `deepLink` | No | ZainCash is redirect-only — these stay `null`. |

Redirect the customer to the hosted page:

```php
return redirect()->away($response->redirectUrl);
```

If ZainCash's `init` call returns without a `transactionId` or `redirectUrl`, parakit throws `GatewayUnavailableException` — the charge did not succeed.

The customer pays on ZainCash's page and is then sent to your `success_url` or `failure_url` (or the per-charge `returnUrl()`). Treat that redirect as a UX signal only — the authoritative result comes from the webhook below.

::: tip customerPhone is optional
Passing `customerPhone()` adds a `customer.phone` block to the init payload so ZainCash can pre-fill the customer's number. Leave it out and the customer enters it on the hosted page.
:::

## Webhook / callback

ZainCash delivers two kinds of callback, both handled by the same route:

```
POST /payments/webhooks/zaincash
```

The `payments/webhooks` prefix comes from `parakit.webhooks.route_prefix`; `zaincash` is the driver key. Configure this URL in your ZainCash merchant dashboard as the webhook endpoint.

Both the browser redirect (`?token=`) and the server webhook (`webhook_token`) carry an HS256 JWT signed with your merchant `api_key`. The JWT signature is the trust boundary: `handleWebhook()` decodes the token with your shared secret and the algorithm pinned to `HS256`, which rejects any forged, tampered, or `alg: none` payload. A missing token or an invalid signature raises `InvalidWebhookSignatureException` and the controller responds `401`.

The decoded `eventType` drives the status mapping — `STATUS_CHANGED`, `REFUND_COMPLETED`, and `REFUND_FAILED` are recognised; unknown event types are logged and fall back to the payload's `currentStatus`.

See [Handling webhooks](/guides/handling-webhooks) for how the resulting `WebhookPayload` updates the transaction.

## Refunds

`ZainCashGateway` implements `SupportsRefund`. `Payment::driver('zaincash')` returns the gateway instance; check it for the contract, then call `refund()`.

```php
use Froshly\Parakit\Contracts\SupportsRefund;
use Froshly\Parakit\DTOs\RefundRequest;

$gateway = Payment::driver('zaincash');

if ($gateway instanceof SupportsRefund) {
    $refund = $gateway->refund(new RefundRequest(
        transactionId: $response->gatewayTransactionId,
        amount: 25000, // must equal the original charge amount
        reason: 'Customer cancelled the order',
    ));

    // $refund->success, $refund->refundId, $refund->refundedAmount
}
```

::: warning Full reversals only
ZainCash v2 reverse is a **full reversal** — there is no partial-refund option. Parakit looks up the original charge amount from the stored transaction and throws `InvalidArgumentException` if the `RefundRequest` amount does not match. Always pass the exact original amount.
:::

The reversal must come back with `status: COMPLETED` and a `reversalReferenceId`; anything else raises `GatewayUnavailableException`. The `reverse:write` scope must be present in `ZAINCASH_SCOPE` for the refund call to be authorised.

See [Refunds](/guides/refunds) for the shared refund workflow.

## Gotchas

- **UAT vs production host.** `ZAINCASH_BASE_URL` defaults to the UAT host `https://pg-api-uat.zaincash.iq`. Set the production host explicitly before going live.
- **Two separate secrets.** `client_secret` authenticates the OAuth2 token endpoint; `api_key` verifies inbound callback JWTs. They are different values — do not swap them.
- **IQD only.** ZainCash always settles in Iraqi dinars. Passing another currency throws `InvalidArgumentException` before the OAuth or payment endpoints are contacted.
- **Duplicate external references.** Charge retries reuse a deterministic UUID `externalReferenceId`. When ZainCash reports that reference as already existing and includes its `transactionId`, parakit follows the documented inquiry flow instead of creating another transaction.
- **Refunds are all-or-nothing.** Partial refunds are rejected before the gateway is contacted. See the refund warning above.
- **Language codes.** `lang` is normalised to `En` / `Ar` / `Ku`. The v2 docs are inconsistent on casing — if UAT rejects the title-cased value, raise it with ZainCash.
- **Unknown status strings.** If ZainCash introduces a status parakit does not recognise, it logs `parakit.zaincash.unknown_status` and falls back to `Pending`. Watch your logs for that warning after ZainCash API changes.
