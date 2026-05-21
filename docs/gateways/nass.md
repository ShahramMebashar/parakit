---
title: Nass Pay
---

# Nass Pay

[Nass Pay](https://nass.iq) is a hosted-checkout gateway: you create a transaction server-side, then send the customer to a Nass-hosted page to pay. `charge()` returns a `redirectUrl` and nothing else.

## Credentials

Every key below lives under `gateways.nass` in `config/parakit.php`.

| Config key | Env var | Default | Required | What it is |
| --- | --- | --- | --- | --- |
| `base_url` | `NASS_BASE_URL` | `https://uat-gateway.nass.iq:9746` | No | Nass API host. Note the `:9746` port — it is part of the URL and must stay. Switch to the production host Nass gives you when you go live. |
| `username` | `NASS_USERNAME` | — | Yes | Merchant login username. Issued by Nass when your account is set up. |
| `password` | `NASS_PASSWORD` | — | Yes | Merchant login password. Issued by Nass. Used to fetch a bearer token. |
| `token_ttl` | `NASS_TOKEN_TTL` | `3000` | No | Seconds the fetched access token is cached. The cache expires 60s early, with a 30s floor. |
| `transaction_type` | `NASS_TRANSACTION_TYPE` | `1` | No | Nass transaction type sent on every charge. Leave at `1` unless Nass tells you otherwise. |
| `callback_url` | `NASS_CALLBACK_URL` | — | No | Server-to-server notification URL. Used as the default `notifyUrl` when a charge does not pass one. |
| `return_url` | `NASS_RETURN_URL` | — | No | Browser return URL after checkout. Used as the default `backRef` when a charge does not pass one. |

## `.env` example

```env
NASS_BASE_URL=https://uat-gateway.nass.iq:9746
NASS_USERNAME=your-merchant-username
NASS_PASSWORD=your-merchant-password
NASS_TOKEN_TTL=3000
NASS_TRANSACTION_TYPE=1
NASS_CALLBACK_URL=https://your-app.test/payments/webhooks/nass
NASS_RETURN_URL=https://your-app.test/checkout/return
```

## Payment flow

Charge an order:

```php
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\Enums\Currency;

$response = Payment::driver('nass')
    ->for($order)
    ->amount(25_000, Currency::IQD)   // 25,000 IQD — IQD is whole dinars
    ->description('Order #1042')
    ->charge();
```

`charge()` logs the customer in against Nass, derives a numeric `orderId` from the idempotency key, and calls Nass's `POST /transaction`. The returned `PaymentResponse` carries:

| Field | Populated | Notes |
| --- | --- | --- |
| `redirectUrl` | Yes | The Nass-hosted checkout page. Send the customer here. |
| `gatewayTransactionId` | Yes | The numeric `orderId`. Store it — webhooks and `status()` are keyed on it. |
| `status` | Yes | Always `PaymentStatus::Pending` at this point. |
| `qrCode`, `readableCode`, `deepLink`, `expiresAt` | No | Nass Pay does not return these. |

Send the customer to the hosted page:

```php
return redirect()->away($response->redirectUrl);
```

The payment is not final yet. Nass calls your `notifyUrl` and the customer returns to your `backRef` — wait for the webhook (below) before marking the order paid.

::: tip Status check
`NassGateway` implements `SupportsStatusCheck`. You can poll Nass at any time with `Payment::driver('nass')->status($gatewayTransactionId)`, which re-fetches the authoritative state. The `parakit:sweep-pending` command uses this to resolve stuck transactions.
:::

## Webhook / callback

Point `NASS_CALLBACK_URL` (the `notifyUrl`) at the package webhook route:

```
POST /payments/webhooks/nass
```

That route is registered by the package (name `parakit.webhook`) and handled by `WebhookController`. The `payments/webhooks` prefix comes from `webhooks.route_prefix`.

Nass callbacks carry **no signature**, so the callback body is never trusted on its own. `handleWebhook()` reads only the `orderId` from the request, then calls Nass's `checkStatus` endpoint server-to-server and uses that authoritative response. A missing `orderId` or any failure on the `checkStatus` call is treated as a verification failure and the controller answers `401`.

## Refunds

**Nass Pay does not support refunds.** `NassGateway` does not implement `SupportsRefund`, so there is no `refund()` method. Refund a Nass payment outside parakit, through Nass's own tooling.

## Gotchas

- **Port in the base URL.** `base_url` includes `:9746`. Keep the port when you set a production host.
- **Currency mapping.** Nass expects an ISO 4217 *numeric* currency code, not a letter code. `NassCurrencyMap` translates the `Currency` enum: `IQD` → `368`, `USD` → `840`. Status responses are mapped back the same way; an unknown numeric code falls back to `IQD`.
- **Amount units.** The charge sends the amount in *major* units as a string (`Money::format`). You still pass minor units to `amount()` — for `IQD` the minor-unit factor is 1, so dinars in equal dinars out.
- **Token TTL.** The login token is cached for `token_ttl` seconds minus a 60s safety margin, with a 30s floor. On an HTTP `401` the client drops the token, re-logs in, and retries the call once.
- **Status codes.** `responseCode` `00` is paid and `-25` is cancelled. Codes `-33`, `-39`, `-40`, `-47` mean "still processing" and map to `Pending`. Other negative codes are `Failed`. An unrecognised code logs `parakit.nass.unknown_status` and is treated as `Pending` rather than guessed.
- **Idempotency.** The `orderId` is `crc32` of the stable idempotency key, so a retried charge re-sends the same `orderId` and never double-creates a Nass transaction. Pass your own `idempotencyKey()` if you want to control it.
