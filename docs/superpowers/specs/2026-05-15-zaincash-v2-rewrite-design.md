# ZainCash Payment Gateway v2 — Rewrite Design

**Date:** 2026-05-15
**Status:** Approved
**Scope:** Replace the legacy ZainCash v1 integration with the v2 Payment Gateway API.

## Background

The current ZainCash gateway targets the legacy v1 API: a merchant-signed HS256
JWT posted as a form field to `/transaction/init`, a `/transaction/get` status
call, and a manually constructed `/transaction/pay` redirect URL.

ZainCash v2 (Payment Gateway v2, doc v1.0, 22 Jan 2026) is a different protocol:

- OAuth2 `client_credentials` for API authentication (Bearer token).
- JSON request/response bodies.
- JWT (HS256, signed with the merchant API key) used only to verify callbacks
  ZainCash sends back — never to authenticate outbound requests.
- The init response returns `redirectUrl`; merchants must not construct it.

The package is pre-release, so v1 is removed entirely — no backward compatibility.

## Approach

Mirror the existing FIB gateway structure. FIB already solves the same problems
(OAuth `client_credentials`, cached Bearer token, JSON API, refund). A single
coherent pattern across both gateways is preferred over a bespoke layout.

## File structure

All v1 files are replaced:

```
src/Gateways/ZainCash/
  ZainCashGateway.php     — orchestration; implements SupportsStatusCheck, SupportsRefund
  ZainCashClient.php      — HTTP: init / inquiry / reverse (Bearer auth)
  ZainCashTokenCache.php  — OAuth2 client_credentials, cached with TTL safety margin
  ZainCashJwt.php         — HS256 decode-only (verify redirect + webhook callbacks)
  ZainCashStatusMap.php   — v2 status string -> PaymentStatus (logs drift)
  ZainCashErrorMap.php    — error string -> PaymentErrorCode
```

## Configuration

Replaces the `zaincash` block in `config/parakit.php`:

```php
'zaincash' => [
    'driver'        => 'zaincash',
    'base_url'      => env('ZAINCASH_BASE_URL', 'https://pg-api-uat.zaincash.iq'),
    'client_id'     => env('ZAINCASH_CLIENT_ID'),
    'client_secret' => env('ZAINCASH_CLIENT_SECRET'),
    'api_key'       => env('ZAINCASH_API_KEY'),      // HS256 key for callback JWTs
    'scope'         => env('ZAINCASH_SCOPE', 'payment:read payment:write reverse:write'),
    'service_type'  => env('ZAINCASH_SERVICE_TYPE', 'Delivery'),
    'lang'          => env('ZAINCASH_LANG', 'en'),
    'success_url'   => env('ZAINCASH_SUCCESS_URL'),
    'failure_url'   => env('ZAINCASH_FAILURE_URL'),
],
```

Two distinct secrets:

- `client_secret` — OAuth2 credential for the token endpoint.
- `api_key` — HS256 key used to verify redirect and webhook callback JWTs.

`lang` is normalized to `En`, `Ar`, or `Ku` before being sent.

## Components

### ZainCashTokenCache

OAuth2 `client_credentials` token cache, modeled on `FibTokenCache`.

- `POST {base_url}/oauth2/token`, form-encoded: `grant_type=client_credentials`,
  `client_id`, `client_secret`, `scope`.
- Caches `access_token` keyed by `xxh3(base_url|client_id|scope)`.
- TTL = `max(30, expires_in - 60)` safety margin.
- Non-2xx response throws `GatewayUnavailableException`.

### ZainCashClient

Bearer-authenticated HTTP client. All requests JSON; token from `ZainCashTokenCache`.

- `init(array $payload): array` — `POST /api/v2/payment-gateway/transaction/init`.
- `inquiry(string $transactionId): array` — `GET /api/v2/payment-gateway/transaction/inquiry/{transactionId}`.
- `reverse(string $transactionId, string $reason): array` — `POST /api/v2/payment-gateway/transaction/reverse`.
- Non-2xx responses throw `GatewayUnavailableException` carrying the HTTP status.

### ZainCashJwt

HS256 decode-only helper for callback verification.

- `decode(string $token): array` — verifies signature with `api_key`, algorithm
  pinned to HS256. Pinning rejects `alg: none` and asymmetric algorithm-confusion
  attacks.
- Invalid/expired/tampered tokens throw `InvalidWebhookSignatureException`.
- No `encode()` — v2 never requires the merchant to sign outbound requests.

### ZainCashStatusMap

Maps v2 status strings to `PaymentStatus`. Modeled on `FibStatusMap` (logs
unknown strings as `parakit.zaincash.unknown_status` before falling back).

| v2 status                          | PaymentStatus |
|-------------------------------------|---------------|
| `SUCCESS`                           | `Paid`        |
| `FAILED`                            | `Failed`      |
| `PENDING`                           | `Pending`     |
| `OTP_SENT`                          | `Pending`     |
| `CUSTOMER_AUTHENTICATION_REQUIRED`  | `Pending`     |
| `EXPIRED`                           | `Expired`     |
| `REFUNDED`                          | `Refunded`    |
| (unknown)                           | `Pending` + log |

### ZainCashErrorMap

Maps gateway error strings/codes to `PaymentErrorCode`. Includes v2 error codes
seen in the docs: `PAYMENT_GATEWAY_UNAUTHORIZED`, `PAYMENT_GATEWAY_TRANSACTION_NOT_FOUND`,
plus the existing substring heuristics (insufficient, cancel, expire, invalid, timeout).

### ZainCashGateway

Orchestration. Implements `SupportsStatusCheck` and `SupportsRefund`.

## Flows

### Charge — `performCharge()`

`POST /api/v2/payment-gateway/transaction/init`, JSON body:

| Field                     | Source |
|---------------------------|--------|
| `language`                | config `lang`, normalized to `En`/`Ar`/`Ku` (see note) |
| `externalReferenceId`     | deterministic UUIDv5 derived from the framework idempotency key |
| `orderId`                 | `PaymentRequest->reference` |
| `serviceType`             | `metadata['service_type']` if set, else config `service_type` |
| `amount.value`            | `PaymentRequest->amount` (string) |
| `amount.currency`         | `IQD` (only currency v2 supports) |
| `customer.phone`          | `PaymentRequest->customerPhone`; key omitted entirely if null |
| `redirectUrls.successUrl` | `PaymentRequest->returnUrl` ?? config `success_url` |
| `redirectUrls.failureUrl` | config `failure_url` |

Response handling:

- `redirectUrl` used exactly as returned — never reconstructed.
- `transactionDetails.transactionId` -> `gatewayTransactionId`.
- `expiryTime` -> `expiresAt`.
- Status is `Pending` (init always yields a pending session).
- Missing `redirectUrl` or `transactionId` -> `GatewayUnavailableException`.

**Note — `language` casing:** the v2 doc contradicts itself. The params table
states "Supported values: En, Ar, Ku", but every curl example sends lowercase
`"language": "en"`. The spec follows the params table (`En`/`Ar`/`Ku`) as the
documented contract; this must be confirmed against UAT during implementation
and switched to lowercase if the gateway rejects title-case.

**Why deterministic `externalReferenceId`:** `AbstractGateway` retries
`performCharge()` on `GatewayUnavailableException`. A random UUID per call would
create duplicate ZainCash transactions on retry. A UUIDv5 derived from the
framework idempotency key is stable across retries, so ZainCash's own
idempotency collapses the duplicate.

### Status — `status()`

`GET /api/v2/payment-gateway/transaction/inquiry/{transactionId}`.

- Status from `status` field via `ZainCashStatusMap`.
- `reference` from `transactionDetails.orderId`.
- `amount` from `transactionDetails.amount.value`.
- `success` = status is successful or `Pending` (consistent with FIB).

### Refund — `refund()`

`POST /api/v2/payment-gateway/transaction/reverse`, body `{transactionId, reason}`.

- v2 reverse is **full-refund only** — there is no amount parameter.
- If `RefundRequest->amount` differs from the original charge amount, throw
  `InvalidArgumentException` before calling the gateway. The original amount is
  read from the `PaymentTransaction` row matching `transactionId`.
- `reason` from `RefundRequest->reason`, fallback `"Merchant-initiated reversal"`.
- Response `status` `COMPLETED` -> success; `reversalReferenceId` -> `refundId`.
- Missing `reversalReferenceId` on a 200 -> `GatewayUnavailableException`.

### Callbacks — `handleWebhook()`

One handler covers both callback paths:

- **Redirect callback** — ZainCash redirects the customer to `successUrl`/
  `failureUrl` with `?token=JWT`.
- **Webhook** — ZainCash POSTs `{webhook_token: JWT}` to the registered
  notification URL (production only; not available in UAT).

Handler logic:

1. Read the token from `token` (redirect) or `webhook_token` (webhook), in
   either query, form, or JSON input. Missing -> `InvalidWebhookSignatureException`.
2. Decode HS256 JWT with `api_key`. Tampered/expired/`alg:none` ->
   `InvalidWebhookSignatureException`.
3. Parse the nested `data{}` envelope:
   - `data.transactionId` -> `gatewayTransactionId`
   - `data.orderId` -> `reference`
   - `data.amount.value` -> `amount`
   - `data.customerMsisdn` -> surfaced in `raw` (pass-through wallet capture;
     the merchant app decides whether to persist and reuse it)
4. Resolve status from the top-level `eventType` claim:
   - `STATUS_CHANGED` -> map `data.currentStatus` via `ZainCashStatusMap`.
   - `REFUND_COMPLETED` -> `PaymentStatus::Refunded`.
   - `REFUND_FAILED` -> map `data.currentStatus` (the payment itself is still
     paid); the failed-refund signal is surfaced via `eventType` in `raw` and
     logged as `parakit.zaincash.refund_failed`.
   - unknown `eventType` -> fall back to mapping `data.currentStatus`, logged
     as `parakit.zaincash.unknown_event`.
5. `eventId` taken from the JWT's top-level `eventId` claim — ZainCash's own
   idempotency key, used directly (not synthesized).
6. `occurredAt` from the JWT `timestamp` claim, fallback to now.
7. `eventType` is always carried through in `WebhookPayload->raw`.

The webhook is the authoritative status source; the redirect token is a UX
fallback. Both verify identically, so a single handler is correct.

**Caveat:** the v2 doc shows `STATUS_CHANGED` callback payloads only — no
`REFUND_COMPLETED`/`REFUND_FAILED` example. The `data{}` field paths for refund
events are assumed identical and must be confirmed against a real production
refund webhook; until then the `eventType` branch above is the best-effort
contract.

## Wallet management

Pass-through only. `customer.phone` is sent from `PaymentRequest->customerPhone`
when present. The payer wallet number captured in the success callback
(`data.customerMsisdn`) is exposed in `WebhookPayload->raw`. The package does not
persist or auto-populate it — the merchant application owns customer identity
and decides whether to store and reuse the wallet number on later charges.

## Testing

Rewrite the five ZainCash test files using `Http::fake` for OAuth + init/
inquiry/reverse, and signed JWT fixtures for callbacks:

- **ZainCashChargeTest** — init request shape, redirect URL passthrough,
  transactionId mapping, missing-field failure, deterministic
  `externalReferenceId` across retries, `metadata['service_type']` override.
- **ZainCashStatusTest** — inquiry parsing, status mapping for each v2 value.
- **ZainCashWebhookTest** — redirect `token` path, webhook `webhook_token`
  path, `data{}` envelope parsing, `eventId` from claim, `eventType` branching
  (`STATUS_CHANGED`, `REFUND_COMPLETED`, `REFUND_FAILED`, unknown),
  tampered/`alg:none` rejection, missing token.
- **ZainCashJwtTest** — HS256 decode, algorithm pinning, expired token.
- **ZainCashErrorMapTest** — v2 error codes + substring heuristics.
- **ZainCashStatusMapTest** (new) — every v2 status string + unknown-drift log.
- Token caching behavior (cache hit avoids a second token call) covered in
  `ZainCashChargeTest` or a dedicated `ZainCashTokenCacheTest`.

## Out of scope

- Partial refunds (v2 requires a commercial agreement; "consult business rep").
- Customer/wallet persistence (merchant-app responsibility).
- Webhook URL registration (manual, done by the ZainCash business team).
