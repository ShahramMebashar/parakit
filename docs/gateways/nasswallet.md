---
title: Nass Wallet
---

# Nass Wallet

[Nass Wallet](https://nw.iq) is a hosted-checkout gateway: you create a transaction server-side, then send the customer to a Nass Wallet checkout portal to pay. `charge()` returns a `redirectUrl` and nothing else.

## Credentials

Every key below lives under `gateways.nasswallet` in `config/parakit.php`.

| Config key | Env var | Default | Required | What it is |
| --- | --- | --- | --- | --- |
| `base_url` | `NASSWALLET_BASE_URL` | `https://uatgw1.nasswallet.com/payment/transaction` | No | Full API endpoint base, including the path. The path differs by environment — see Gotchas. |
| `portal_url` | `NASSWALLET_PORTAL_URL` | `https://uatcheckout1.nasswallet.com` | No | Host of the customer-facing checkout portal. The `redirectUrl` is built from this. |
| `basic_token` | `NASSWALLET_BASIC_TOKEN` | — | Yes | Static HTTP `Basic` token used only on the login call. Nass Wallet may rotate it; configure the current value explicitly. |
| `username` | `NASSWALLET_USERNAME` | — | Yes | Per-merchant login username, also sent as `userIdentifier` on each charge. Issued by Nass Wallet. |
| `password` | `NASSWALLET_PASSWORD` | — | Yes | Per-merchant login password. Used with `basic_token` to fetch a bearer token. |
| `transaction_pin` | `NASSWALLET_TRANSACTION_PIN` | — | Yes | Merchant transaction PIN, sent on every charge to authorise it. Issued by Nass Wallet. |
| `callback_url` | `NASSWALLET_CALLBACK_URL` | — | No | Server-to-server notification URL. See Webhook / callback — Nass Wallet appends `/callback` to whatever you configure. |

## `.env` example

```env
NASSWALLET_BASE_URL=https://uatgw1.nasswallet.com/payment/transaction
NASSWALLET_PORTAL_URL=https://uatcheckout1.nasswallet.com
NASSWALLET_BASIC_TOKEN=your-basic-token
NASSWALLET_USERNAME=your-merchant-username
NASSWALLET_PASSWORD=your-merchant-password
NASSWALLET_TRANSACTION_PIN=your-transaction-pin
NASSWALLET_CALLBACK_URL=https://your-app.test/payments/webhooks/nasswallet
```

## Payment flow

Charge an order:

```php
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\Enums\Currency;

$response = Payment::driver('nasswallet')
    ->for($order)
    ->amount(25_000, Currency::IQD)   // 25,000 IQD — Nass Wallet settles IQD only
    ->description('Order #1042')
    ->charge();
```

`charge()` logs in against Nass Wallet, derives a numeric `orderId` from the idempotency key, and calls the `initTransaction` endpoint with the merchant `userIdentifier` and `transactionPin`. The returned `PaymentResponse` carries:

| Field | Populated | Notes |
| --- | --- | --- |
| `redirectUrl` | Yes | The checkout-portal URL (`{portal_url}/payment-gateway?...`). Send the customer here. |
| `gatewayTransactionId` | Yes | Nass Wallet's `transactionId`. Store it — webhooks and `status()` are keyed on it. |
| `status` | Yes | Always `PaymentStatus::Pending` at this point. |
| `qrCode`, `readableCode`, `deepLink`, `expiresAt` | No | Nass Wallet does not return these. |

Send the customer to the portal:

```php
return redirect()->away($response->redirectUrl);
```

The payment is not final yet — wait for the webhook (below) before marking the order paid.

::: tip Checkout language
Pass `language` in the charge metadata to set the portal language: `->metadata(['language' => 'ar'])`. Accepted values are `en`, `ku`, `ar`; anything else falls back to `en`.
:::

::: tip Status check
`NassWalletGateway` implements `SupportsStatusCheck`. Call `Payment::driver('nasswallet')->status($gatewayTransactionId)` to re-fetch the authoritative state. The `parakit:transactions:sweep-pending` command uses this for stuck transactions.
:::

## Webhook / callback

Nass Wallet appends `/callback` to the notification URL you configure. If you set `NASSWALLET_CALLBACK_URL` to `https://your-app.test/payments/webhooks/nasswallet`, Nass Wallet actually calls:

```
POST /payments/webhooks/nasswallet/callback
```

The package registers an **alias route** for exactly this. Alongside the standard `POST {prefix}/{gateway}` route, there is `POST {prefix}/{gateway}/callback` (route name `parakit.webhook.callback`), which routes to the same `WebhookController` with the `{gateway}` segment unchanged. So `payments/webhooks/nasswallet/callback` resolves correctly with no extra setup — keep the bare `payments/webhooks/nasswallet` value in your config and let Nass Wallet add the suffix.

Nass Wallet callbacks carry **no signature**. `handleWebhook()` reads only `data.InitTransactionId` from the request body, then calls the `checkTransaction` endpoint server-to-server and uses that authoritative response. A missing `InitTransactionId` or any failure on the `checkTransaction` call is treated as a verification failure and the controller answers `401`.

## Refunds

**Nass Wallet does not support refunds.** `NassWalletGateway` does not implement `SupportsRefund`, so there is no `refund()` method. Refund a Nass Wallet payment outside parakit, through Nass Wallet's own tooling.

## Gotchas

- **Environment-specific base URL path.** `base_url` is the *full* endpoint base, and the path differs between environments:
  - UAT — `https://uatgw1.nasswallet.com/payment/transaction`
  - PROD — `https://gw-api.nasswallet.com/phase3/payment/transaction`

  Set the matching `portal_url` too (the UAT portal is `uatcheckout1.nasswallet.com`).
- **IQD only.** `charge()` throws `InvalidArgumentException` for any currency other than `IQD`, rather than silently settling in IQD.
- **Amount units.** The charge sends the amount as a 2-decimal string built straight from the integer you pass to `amount()`. For `IQD` the minor-unit factor is 1, so `amount(25_000, Currency::IQD)` sends `25000.00`.
- **Token TTL.** The login token's cache lifetime comes from Nass Wallet's `accessTokenExpiry` (epoch milliseconds), minus a 60s safety margin, with a 1s minimum. If that field is missing the token is cached for 300s. On an HTTP `401` the client drops the token, re-logs in, and retries once.
- **Success signal.** Nass Wallet returns `errCode` `"1"` even on success — it is ignored. Success is numeric `responseCode` `0` (integer or numeric string); anything else is a non-retryable rejection.
- **Status amount fallback.** Some `checkTransaction` responses omit the amount. Parakit uses the stored transaction amount in that shape so status checks and amount-verification webhooks do not incorrectly report zero.
- **Status mapping.** `transactionStatus` `SUCCESS` maps to `Paid` and `FAILED` to `Failed`. Any other value logs `parakit.nasswallet.unknown_status` and is treated as `Pending`.
- **Idempotency.** The `orderId` is derived from the stable idempotency key (sha256 → first 60 bits as a numeric string), so a retried charge re-sends the same `orderId` and never double-creates a transaction. Free-text caller keys are folded through `IdempotencyKey::forGateway` first.
