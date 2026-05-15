# Parakit — Full Specification

> پارەکیت — The payment kit for Kurdistan and Iraq, Laravel-native.

**Version:** 1.0 (target)
**Status spec covers:** v0.1 → v1.0
**Maintainer:** Shah

---

## Table of contents

1. [Vision & positioning](#vision--positioning)
2. [Package identity](#package-identity)
3. [Supported gateways](#supported-gateways)
4. [Directory structure](#directory-structure)
5. [Configuration](#configuration)
6. [Public API](#public-api)
7. [Contracts](#contracts)
8. [DTOs](#dtos)
9. [Enums](#enums)
10. [Database schema](#database-schema)
11. [Webhook flow](#webhook-flow)
12. [Reliability layer](#reliability-layer)
13. [Observability](#observability)
14. [Security](#security)
15. [Translations](#translations)
16. [Error handling](#error-handling)
17. [Console commands](#console-commands)
18. [Events](#events)
19. [Pending sweeper (lost-webhook recovery)](#pending-sweeper)
20. [Reconciliation](#reconciliation)
21. [Testing utilities](#testing-utilities)
22. [Multi-merchant support](#multi-merchant-support)
23. [Tokenization & saved methods](#tokenization)
24. [Filament integration](#filament-integration)
25. [Blade & Livewire components](#blade--livewire-components)
26. [Documentation](#documentation)
27. [Versioning & support policy](#versioning--support-policy)
28. [Release roadmap](#release-roadmap)
29. [Success criteria](#success-criteria)

---

## Vision & positioning

Parakit is the default way to take payments from Iraqi and Kurdish customers in a Laravel application. It is not just a wrapper around HTTP clients — it ships the integration-layer features that every merchant eventually builds themselves (idempotency, webhook retry handling, status reconciliation, observability, state machine), so the developer can ship in minutes and trust it in production.

**Goal:** `composer require` to first successful sandbox charge in under 15 minutes.

**Non-goals:**
- Not a generic global payment library — local optimization is the moat.
- Not a hosted service — it's a library.
- Not opinionated about your domain model — orders, subscriptions, and invoices are yours.

---

## Package identity

| Field | Value |
|---|---|
| Composer name | `froshly/parakit` |
| Namespace | `Froshly\Parakit` |
| Facade | `Payment` (aliased) |
| PHP | `^8.2` |
| Laravel | `^11.0 \|\| ^12.0` |
| License | MIT |
| Docs site | `parakit.dev` |
| Tagline | *The payment kit for Kurdistan and Iraq — Laravel-native.* |

---

## Supported gateways

| Gateway | Flow | Currency | Webhook auth | Refund | Cancel | Tokenization |
|---|---|---|---|---|---|---|
| FIB | QR / deep-link / readable code → callback + status poll | IQD | Callback by ID, verified via status endpoint | ✅ | ✅ | Roadmap |
| ZainCash | OAuth2 API → hosted payment page redirect → JWT callback | IQD | JWT (HS256) merchant API key | ✅ Full only | ❌ | Roadmap |
| FastPay | Form-redirect → signed callback | IQD | HMAC signature | ⚠️ Partial | ❌ | ❌ |
| NassPay | Redirect → callback | IQD | HMAC / token | ⚠️ Partial | ❌ | ❌ |
| NassWallet | Redirect → callback | IQD | HMAC / token | ⚠️ Partial | ❌ | ❌ |

Each driver lives in its own subfolder and can be swapped at runtime via config.

---

## Directory structure

```
froshly/parakit/
├── composer.json
├── README.md
├── CHANGELOG.md
├── SECURITY.md
├── LICENSE
├── config/
│   └── parakit.php
├── database/
│   └── migrations/
│       ├── create_payment_transactions_table.php
│       ├── create_payment_webhook_events_table.php
│       └── create_payment_logs_table.php
├── resources/
│   ├── lang/
│   │   ├── en/payments.php
│   │   ├── ar/payments.php
│   │   └── ckb/payments.php
│   └── views/
│       └── components/
├── routes/
│   └── webhooks.php
├── src/
│   ├── ParakitServiceProvider.php
│   ├── PaymentManager.php
│   ├── Facades/
│   │   └── Payment.php
│   ├── Contracts/
│   │   ├── PaymentGateway.php
│   │   ├── SupportsRefund.php
│   │   ├── SupportsTokenization.php
│   │   └── SupportsStatusCheck.php
│   ├── Enums/
│   │   ├── PaymentStatus.php
│   │   ├── PaymentErrorCode.php
│   │   ├── Currency.php
│   │   └── Gateway.php
│   ├── DTOs/
│   │   ├── PaymentRequest.php
│   │   ├── PaymentResponse.php
│   │   ├── WebhookPayload.php
│   │   ├── RefundRequest.php
│   │   ├── RefundResponse.php
│   │   └── PaymentError.php
│   ├── Gateways/
│   │   ├── AbstractGateway.php
│   │   ├── Fib/
│   │   │   ├── FibGateway.php
│   │   │   ├── FibClient.php
│   │   │   ├── FibErrorMap.php
│   │   │   ├── FibStatusMap.php
│   │   │   └── FibTokenCache.php
│   │   ├── ZainCash/
│   │   │   ├── ZainCashGateway.php
│   │   │   └── ZainCashErrorMap.php
│   │   ├── FastPay/
│   │   ├── NassPay/
│   │   └── NassWallet/
│   ├── Http/
│   │   ├── Controllers/WebhookController.php
│   │   └── Middleware/VerifyWebhookSignature.php
│   ├── Models/
│   │   ├── PaymentTransaction.php
│   │   ├── PaymentWebhookEvent.php
│   │   └── PaymentLog.php
│   ├── Events/
│   │   ├── PaymentInitiated.php
│   │   ├── PaymentSucceeded.php
│   │   ├── PaymentFailed.php
│   │   ├── PaymentCancelled.php
│   │   ├── PaymentRefunded.php
│   │   ├── WebhookReceived.php
│   │   ├── WebhookVerificationFailed.php
│   │   ├── GatewayTimeout.php
│   │   └── CircuitOpened.php
│   ├── Exceptions/
│   │   ├── PaymentException.php
│   │   ├── GatewayUnavailableException.php
│   │   ├── InvalidWebhookSignatureException.php
│   │   ├── DuplicateWebhookException.php
│   │   └── UnsupportedGatewayException.php
│   ├── Support/
│   │   ├── IdempotencyKey.php
│   │   ├── CircuitBreaker.php
│   │   ├── PayloadRedactor.php
│   │   └── CorrelationId.php
│   ├── Testing/
│   │   ├── PaymentFake.php
│   │   ├── WebhookSimulator.php
│   │   └── RecordedResponses/
│   └── Console/
│       ├── InstallCommand.php
│       ├── DoctorCommand.php
│       ├── SweepPendingCommand.php
│       ├── SimulateWebhookCommand.php
│       ├── TestChargeCommand.php
│       ├── ReconcileImportCommand.php
│       └── PruneLogsCommand.php
└── tests/
    ├── Feature/
    ├── Unit/
    └── Fixtures/
```

---

## Configuration

`config/parakit.php`:

```php
return [
    'default' => env('PARAKIT_DEFAULT', 'fib'),

    'webhooks' => [
        'route_prefix' => 'payments/webhooks',
        'middleware' => ['api'],
        'tolerance_seconds' => 300,
    ],

    'reliability' => [
        'idempotency_ttl' => 86400,
        'retry' => ['max_attempts' => 3, 'base_delay_ms' => 200],
        'circuit_breaker' => [
            'failure_threshold' => 5,
            'cooldown_seconds' => 30,
        ],
        'timeout_seconds' => 15,
    ],

    'logging' => [
        'enabled' => true,
        'channel' => env('PARAKIT_LOG_CHANNEL', 'stack'),
        'redact_keys' => ['password', 'token', 'secret', 'card', 'msisdn'],
        'retention_days' => 90,
    ],

    'sweeper' => [
        'enabled' => true,
        'older_than_minutes' => 5,
        'max_age_hours' => 24,
    ],

    'gateways' => [
        'fib' => [
            'driver' => 'fib',
            'base_url' => env('FIB_BASE_URL', 'https://fib.stage.fib.iq'),
            'client_id' => env('FIB_CLIENT_ID'),
            'client_secret' => env('FIB_CLIENT_SECRET'),
            'currency' => env('FIB_CURRENCY', 'IQD'),
            'refundable_for' => env('FIB_REFUNDABLE_FOR', 'P7D'),
            'callback_url' => env('FIB_CALLBACK_URL'),
        ],
        'zaincash' => [
            'driver' => 'zaincash',
            'base_url' => env('ZAINCASH_BASE_URL'),
            'merchant_id' => env('ZAINCASH_MERCHANT_ID'),
            'msisdn' => env('ZAINCASH_MSISDN'),
            'secret' => env('ZAINCASH_SECRET'),
            'lang' => env('ZAINCASH_LANG', 'en'),
        ],
        'fastpay'    => [ /* ... */ ],
        'nasspay'    => [ /* ... */ ],
        'nasswallet' => [ /* ... */ ],
    ],
];
```

Anyone can register a custom driver class via this config slot — the package does not require forking for new gateways.

---

## Public API

```php
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\DTOs\PaymentRequest;
use Froshly\Parakit\Enums\Currency;

// Default driver
Payment::charge($request);

// Specific driver
Payment::driver('fib')->charge($request);

// Status check
Payment::driver('fib')->status($transactionId);

// Refund (drivers that implement SupportsRefund)
Payment::driver('fib')->refund(new RefundRequest($id, amount: 5000));

// Fluent builder for the common case
$response = Payment::for($order)
    ->driver('fib')
    ->amount(5000, Currency::IQD)
    ->description('Order #123')
    ->idempotencyKey($order->id)
    ->charge();

// Multi-merchant
Payment::driver('fib')->forMerchant($merchant)->charge($request);

// Testing
Payment::fake();
Payment::driver('fib')->assertCharged($reference);
Payment::driver('fib')->simulateWebhook(PaymentStatus::Paid, $reference);
```

---

## Contracts

```php
interface PaymentGateway
{
    public function charge(PaymentRequest $request): PaymentResponse;
    public function status(string $gatewayTransactionId): PaymentResponse;
    public function handleWebhook(Request $request): WebhookPayload;
    public function name(): string;
}

interface SupportsRefund {
    public function refund(RefundRequest $request): RefundResponse;
}

interface SupportsTokenization {
    public function tokenize(PaymentRequest $request): string;
    public function chargeToken(string $token, int $amount, Currency $currency): PaymentResponse;
}

interface SupportsStatusCheck {
    public function status(string $id): PaymentResponse;   // required for sweeper
}
```

Drivers declare capabilities by implementing optional contracts. Application code checks `instanceof SupportsRefund` before calling refund-only methods.

---

## DTOs

All DTOs are `final readonly` value objects.

```php
final class PaymentRequest {
    public function __construct(
        public readonly string $reference,           // your order id
        public readonly int $amount,                 // minor units
        public readonly Currency $currency,
        public readonly string $description,
        public readonly ?string $customerPhone = null,
        public readonly ?string $customerEmail = null,
        public readonly ?string $customerName = null,
        public readonly ?string $callbackUrl = null,
        public readonly ?string $returnUrl = null,
        public readonly ?string $idempotencyKey = null,
        public readonly array $metadata = [],
    ) {}
}

final class PaymentResponse {
    public function __construct(
        public readonly bool $success,
        public readonly string $gateway,
        public readonly ?string $gatewayTransactionId,
        public readonly string $reference,
        public readonly PaymentStatus $status,
        public readonly int $amount,
        public readonly Currency $currency,
        public readonly string $correlationId,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $qrCode = null,           // base64 (FIB)
        public readonly ?string $deepLink = null,         // app link (FIB)
        public readonly ?string $readableCode = null,     // FIB
        public readonly ?\DateTimeImmutable $expiresAt = null,
        public readonly ?PaymentError $error = null,
        public readonly array $raw = [],
    ) {}

    public function failed(): bool { return !$this->success; }
}

final class WebhookPayload {
    public function __construct(
        public readonly string $gateway,
        public readonly string $gatewayTransactionId,
        public readonly string $reference,
        public readonly PaymentStatus $status,
        public readonly int $amount,
        public readonly Currency $currency,
        public readonly string $eventId,                  // for idempotency
        public readonly \DateTimeImmutable $occurredAt,
        public readonly ?PaymentError $error = null,
        public readonly array $raw = [],
    ) {}
}

final class PaymentError {
    public function __construct(
        public readonly PaymentErrorCode $code,
        public readonly string $rawCode,
        public readonly string $rawMessage,
    ) {}

    public function message(?string $locale = null): string;
}
```

---

## Enums

```php
enum PaymentStatus: string {
    case Pending = 'pending';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Expired = 'expired';
    case Disputed = 'disputed';

    public function isTerminal(): bool;
    public function isSuccessful(): bool;
    public function canTransitionTo(self $next): bool;   // state machine
}

enum Currency: string {
    case IQD = 'IQD';
    case USD = 'USD';

    public function minorUnitFactor(): int;     // IQD=1, USD=100
    public function symbol(): string;
}

enum PaymentErrorCode: string {
    case InsufficientFunds = 'insufficient_funds';
    case InvalidPhone = 'invalid_phone';
    case UserCancelled = 'user_cancelled';
    case Expired = 'expired';
    case InvalidAmount = 'invalid_amount';
    case InvalidCredentials = 'invalid_credentials';
    case GatewayUnavailable = 'gateway_unavailable';
    case DuplicateTransaction = 'duplicate_transaction';
    case NetworkError = 'network_error';
    case Timeout = 'timeout';
    case SignatureInvalid = 'signature_invalid';
    case Unknown = 'unknown';
}

enum Gateway: string {
    case Fib = 'fib';
    case ZainCash = 'zaincash';
    case FastPay = 'fastpay';
    case NassPay = 'nasspay';
    case NassWallet = 'nasswallet';
}
```

`PaymentStatus::canTransitionTo()` enforces a state machine: `Paid → Pending` throws and is logged. This prevents an entire class of double-fulfillment bugs.

---

## Database schema

### `payment_transactions`

| Column | Type | Notes |
|---|---|---|
| `id` | ulid PK | |
| `gateway` | string(32) | indexed |
| `reference` | string | merchant order id, indexed |
| `gateway_transaction_id` | string nullable | indexed |
| `status` | string(32) | indexed |
| `amount` | unsignedBigInteger | minor units |
| `currency` | string(3) | |
| `refunded_amount` | unsignedBigInteger default 0 | |
| `idempotency_key` | string nullable unique | |
| `correlation_id` | string | indexed |
| `metadata` | json nullable | |
| `last_raw_response` | json nullable | |
| `expires_at` | timestamp nullable | |
| `paid_at` | timestamp nullable | |
| `created_at`, `updated_at` | timestamps | |
| **unique** | `(gateway, gateway_transaction_id)` | |

### `payment_webhook_events` (idempotency at DB level)

| Column | Type |
|---|---|
| `id` | bigint PK |
| `gateway` | string(32) |
| `event_id` | string |
| `status` | string(32) |
| `payload` | json |
| `processed_at` | timestamp nullable |
| `created_at`, `updated_at` | timestamps |
| **unique** | `(gateway, event_id)` |

### `payment_logs` (request/response audit trail)

| Column | Type |
|---|---|
| `id` | bigint PK |
| `correlation_id` | string indexed |
| `gateway` | string(32) |
| `action` | string(32) — `charge`, `status`, `refund`, `webhook` |
| `endpoint` | string nullable |
| `status_code` | unsignedSmallInteger nullable |
| `duration_ms` | unsignedInteger nullable |
| `request` | json nullable, redacted |
| `response` | json nullable, redacted |
| `error_message` | text nullable |
| `created_at` | timestamp |

---

## Webhook flow

```
POST /payments/webhooks/{gateway}
  ↓
WebhookController
  ↓
$driver->handleWebhook($request)        // verifies signature, parses
  ↓ returns WebhookPayload (or throws InvalidWebhookSignatureException)
  ↓
fire WebhookReceived
  ↓
INSERT payment_webhook_events (unique constraint)
  ↓ duplicate? → 200 OK, no further processing
  ↓
DB::transaction:
  SELECT FOR UPDATE payment_transactions
  if can_transition_to(new_status):
      UPDATE status, gateway_transaction_id, last_raw_response, paid_at
  UPDATE payment_webhook_events SET processed_at = NOW()
  ↓
fire PaymentSucceeded / PaymentFailed / PaymentCancelled
  ↓
return 200 OK
```

**Critical invariants:**
- Signature verification is non-skippable. No config flag disables it.
- DB-level idempotency: race-safe even under concurrent webhook delivery.
- State machine enforces legal transitions; illegal ones log and skip the update.
- Failed verification fires `WebhookVerificationFailed` and returns 401 without touching the DB.

---

## Reliability layer

| Concern | Mechanism |
|---|---|
| **Idempotency** | `charge()` with same `idempotency_key` returns cached `PaymentResponse` (24h TTL). |
| **Retries** | Exponential backoff on network errors and 5xx. Never on 4xx. Never on `charge` without an idempotency key. |
| **Circuit breaker** | Per gateway. After N failures in window, fail fast with `GatewayUnavailableException`. Auto-recover after cooldown. |
| **Timeout** | Hard HTTP timeout, default 15s. |
| **Webhook idempotency** | Unique DB index on `(gateway, event_id)` rejects duplicates atomically before processing. |
| **Replay protection** | Webhooks with timestamps older than `tolerance_seconds` rejected. |
| **Signature verification** | Mandatory, constant-time comparison. Cannot be disabled. |

These primitives are wired into `AbstractGateway` so every driver inherits them.

---

## Observability

Every HTTP call logged to `payment_logs` with:

- `correlation_id` — propagated across `charge → webhook → status` so one ID traces the entire payment lifecycle
- `gateway`, `endpoint`, `duration_ms`, `status_code`
- Redacted request and response payloads (auto-redaction by key name + regex)

Optional OpenTelemetry spans if `open-telemetry/api` is installed (loose dependency).

Metric helpers:

```php
Payment::stats()->successRate('fib', since: now()->subDay());
Payment::stats()->volumeByGateway(since: now()->startOfMonth());
```

---

## Security

- Webhook signature verification mandatory, no override flag
- Secrets never logged — auto-redaction by configured key names + regex catch-all
- `payment_logs` redacted at write time, not at read time
- Replay protection: reject webhooks older than `tolerance_seconds`
- Constant-time comparison (`hash_equals`) for HMAC/signature checks
- `SECURITY.md` with `security@parakit.dev` private disclosure address
- 90-day disclosure window; CVEs published in CHANGELOG
- All drivers verify TLS certificates by default; no `verify: false` ever shipped

---

## Translations

Publishable lang files at `resources/lang/{en,ar,ckb}/payments.php` covering:

- All `PaymentErrorCode` cases
- All `PaymentStatus` labels
- UX strings (`pay_with_fib`, `pay_with_zaincash`, `redirecting`, `payment_pending`, …)
- Validation messages

Publish:

```bash
php artisan vendor:publish --tag=parakit-lang
```

Adding a fourth language requires no code change — drop a folder in `resources/lang/`.

---

## Error handling

Each driver maps its native error codes to the unified `PaymentErrorCode` enum via a per-driver `ErrorMap` class. Application code is gateway-agnostic:

```php
if ($response->failed()) {
    return back()->with('error', $response->error->message(app()->getLocale()));
}
```

Drivers preserve `rawCode` and `rawMessage` on every `PaymentError` for debugging and merchant support.

---

## Console commands

| Command | Purpose |
|---|---|
| `parakit:install` | One-shot setup: publishes config + migrations + lang, runs migrate |
| `parakit:doctor` | Checks credentials, webhook URL reachability, clock skew, FIB token validity |
| `parakit:doctor --gateway=fib` | Scoped check for a single gateway |
| `parakit:test-charge fib --amount=1000` | Sandbox roundtrip — proves end-to-end works |
| `parakit:sweep-pending` | Status-polls pending transactions to recover lost webhooks |
| `parakit:sweep-pending --gateway=fib --older-than=10m` | Scoped sweep |
| `parakit:webhook:simulate fib --status=paid --reference=ord_123` | Posts a correctly-signed test webhook to your local app |
| `parakit:reconcile:import fib ./export.csv` | Diff a merchant-dashboard CSV against local DB |
| `parakit:logs:prune --days=90` | Trim `payment_logs` per retention policy |

The scheduler auto-registers `sweep-pending` (every 5 min) and `logs:prune` (daily) when their config flags are enabled.

**`parakit:doctor` is the support-prevention command** — it catches the #1 issue ("my webhook isn't working") before merchants file a ticket. Output is actionable, not a wall of text.

---

## Events

```php
PaymentInitiated            // before HTTP call to gateway
PaymentSucceeded            // on webhook or status confirming paid
PaymentFailed
PaymentCancelled
PaymentRefunded
WebhookReceived             // before processing, after signature check
WebhookVerificationFailed   // signature/JWT invalid
GatewayTimeout              // HTTP timeout
CircuitOpened               // circuit breaker tripped
```

All events carry the relevant DTO. Hook Sentry, Telescope, your own audit log, or your fulfillment pipeline.

---

## Pending sweeper

Because Iraqi gateways generally do **not** expose bulk transaction-list endpoints, full automated reconciliation isn't possible. What *is* possible — and solves 95% of real-world issues — is recovering lost webhooks by polling single-transaction status.

```bash
php artisan parakit:sweep-pending
```

Logic:

1. Find local transactions in `Pending` or `Processing` older than `older_than_minutes`, younger than `max_age_hours`
2. For each, call the driver's `status($gatewayTransactionId)`
3. If gateway status differs from local, transition via the state machine and fire the matching event

Wired into the scheduler by default. This is the workhorse that keeps your DB in sync when webhooks drop.

---

## Reconciliation

Two paths, scoped to what the gateway APIs actually support:

### Automated (where supported)

If a gateway later adds a list endpoint, the driver implements `SupportsReconciliation`:

```php
Payment::driver('fib')->reconcile(from: now()->subDay());
```

### CSV import (universal fallback)

Every gateway provides a merchant-dashboard CSV export. Parakit ships a parser:

```bash
php artisan parakit:reconcile:import fib ./fib-export-2026-05.csv
```

Output:

```
✓ 142 transactions match
⚠ 3 missing locally          (gateway has them, you don't)
⚠ 1 status mismatch          (gateway: paid, local: pending) ← lost webhook
⚠ 1 refunded on gateway only (manual refund, sync your DB)
```

Built-in parsers for each gateway's CSV format; auto-detected by column signature.

---

## Testing utilities

```php
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\Enums\PaymentStatus;

Payment::fake();

// Trigger your application code...
$service->checkout($order);

// Assert
Payment::driver('fib')->assertCharged($order->id);
Payment::driver('zaincash')->assertNothingCharged();

// Simulate a webhook arriving from the gateway
Payment::driver('zaincash')->simulateWebhook(
    status: PaymentStatus::Paid,
    reference: $order->id,
);
```

Under the hood:

- `PaymentFake` records all `charge()` / `status()` / `refund()` calls and returns canned `PaymentResponse` instances
- Recorded HTTP fixtures from real sandbox responses live in `tests/Fixtures/`
- `WebhookSimulator` posts realistic, correctly-signed payloads to the configured webhook URL — different per gateway (JWT for ZainCash, HMAC for FastPay, etc.)
- Pest framework, 90% coverage gate in CI

---

## Multi-merchant support

For SaaS builders running one Laravel app for many merchants:

```php
Payment::driver('fib')->forMerchant($merchant)->charge($request);
```

`forMerchant()` resolves credentials per merchant from a user-supplied resolver:

```php
// In a service provider
Payment::resolveMerchantCredentialsUsing(function (Merchant $m, string $gateway) {
    return [
        'client_id' => $m->fib_client_id,
        'client_secret' => decrypt($m->fib_client_secret),
        'callback_url' => route('webhook', ['merchant' => $m->slug]),
    ];
});
```

The webhook route accepts a merchant identifier so callbacks land in the right tenant's context.

---

## Tokenization

Drivers that support saving a payment method implement `SupportsTokenization`:

```php
// Save a method for later
$token = Payment::driver('fib')->tokenize($paymentRequest);
$user->update(['fib_token' => encrypt($token)]);

// Charge it later (subscription, retry, etc.)
Payment::driver('fib')->chargeToken(
    token: decrypt($user->fib_token),
    amount: 5000,
    currency: Currency::IQD,
);
```

Roadmap item — FIB and ZainCash both have recurring/tokenized flows on their roadmaps. Parakit ships the abstraction now so subscription products work the day the gateway turns it on.

---

## Filament integration

Shipped as a separate package: `froshly/parakit-filament`. Kept separate so non-Filament users don't pull the dependency.

Provides:

- **`PaymentTransactionResource`** — list, filter by gateway/status/date range, view raw payload, trigger refund (permission-aware)
- **`PaymentDashboardWidget`** — today's volume, success rate by gateway, failed transactions, sweeper-recovered count
- **Permission policies** scaffolded for `view`, `refund`, `simulate`

Install:

```bash
composer require froshly/parakit-filament
php artisan parakit-filament:install
```

---

## Blade & Livewire components

```blade
<x-parakit::pay-button :order="$order" gateway="fib" />
<x-parakit::gateway-picker :order="$order" :gateways="['fib','zaincash']" />
```

```php
<livewire:parakit-checkout :order="$order" />
```

All components are:

- RTL-aware (`dir="rtl"` auto-detected from locale)
- Fully translated (en/ar/ckb)
- Dark-mode friendly
- Built on Tailwind v4 (CSS-based `@theme` config)
- Headless variants available for full styling control

---

## Documentation

`parakit.dev` (VitePress):

- **Quickstart** — 15-min path to first charge
- **Per-gateway setup** with screenshots of merchant onboarding
- **Cookbook** — 15 common scenarios with copy-paste code
- **API reference** — auto-generated from PHPDoc
- **Migration guide** — from rolling-your-own
- **Troubleshooting** — mapped from `parakit:doctor` output codes
- Every page in English, Arabic, and Kurdish Sorani

---

## Versioning & support policy

- Semantic versioning
- v1.x supports Laravel 11 and 12; new Laravel majors added within 60 days of release
- Gateway API changes tracked in CHANGELOG with `@since X.Y — gateway vN` markers
- Security patches for the last two minor versions
- Public API locked at v1.0; breaking changes only in major releases

---

## Release roadmap

### v0.1 (alpha — 2 weeks)

Core: Manager, contracts, DTOs, enums, state machine, FIB + ZainCash drivers, webhook controller with idempotency, sweeper, translations, `install` + `doctor` commands, README. Sandbox-tested end to end.

### v0.2 (beta — +2 weeks)

FastPay, NassPay, NassWallet drivers. Blade components. `Payment::fake()` and `WebhookSimulator`. Test coverage to 90%. CSV reconciliation import.

### v0.3 (+2 weeks)

Filament package. Multi-merchant credential resolver. Docs site live in three languages. `parakit:test-charge` and `parakit:webhook:simulate` polished.

### v1.0 (stable)

Lock public API. Circuit breaker + retry primitives hardened. OpenTelemetry exporter optional.

### v1.1+

Qi Card, AsiaHawala, and other emerging Iraqi gateways. Subscription helpers. Advanced reconciliation (if gateway APIs expand).

---

## Success criteria

1. `composer require` to first successful sandbox charge in **under 15 minutes**
2. One env-file change to swap gateways — no code changes
3. Zero CVEs in the first year
4. **5+ unrelated MENA SaaS products** running it in production by v1.0
5. The default answer when anyone asks "how do I take payments in Iraq with Laravel"
6. Community contributes at least **one driver** without forking — proves the extension model works

---

## Appendix A — Gateway-specific notes

### FIB

- OAuth2 client_credentials flow; token cached with TTL (FIB tokens live ~60s)
- `monetaryValue.amount` is a decimal string; FIB currently supports `IQD` only
- Charge sends `statusCallbackUrl` (webhook URL) and `redirectUri` (from the
  request `returnUrl`); `description` is truncated to FIB's 50-char limit
- Optional charge fields via config or per-request `metadata`: `expiresIn` /
  `refundableFor` (ISO-8601 durations), `category` (ERP, POS, ECOMMERCE, ...)
- Callback delivers `{id, status}`; driver re-fetches status endpoint for full state
- Refund window: FIB defaults to 24h, `refundableFor` accepts 12h–7d
- Supports payment cancellation (`SupportsCancel`) for active, unpaid payments
- Returns `qrCode` (base64 PNG), `readableCode`, `personalAppLink`
  (`businessAppLink` / `corporateAppLink` are preserved in `raw`)

### ZainCash

- ZainCash Payment Gateway v2: OAuth2 `client_credentials` Bearer auth
- `POST /api/v2/payment-gateway/transaction/init` returns a hosted `redirectUrl`
- Status via inquiry endpoint; full reversal via reverse endpoint
- Redirect (`token`) and webhook (`webhook_token`) callbacks are HS256 JWTs,
  verified with the merchant API key; `eventId` is the idempotency key
- IQD only; whole-number amounts

### FastPay

- Form-redirect flow
- HMAC-signed callback
- Verify each gateway's current API docs before driver release — schemas have changed historically

### NassPay / NassWallet

- Similar redirect + callback model
- HMAC or token-based webhook auth depending on contract
- Driver-level verification per current docs

---

## Appendix B — Glossary

| Term | Meaning |
|---|---|
| **Minor units** | Integer representation of money. IQD stays as IQD (no subunit in practice); USD is cents. |
| **Idempotency key** | Caller-supplied unique string ensuring the same `charge()` returns the same result on retry. |
| **Correlation ID** | Internally generated ULID tying together every step (charge → webhook → status → refund) of one payment lifecycle. |
| **State machine** | Codified set of legal status transitions, enforced before any DB write. |
| **Sweeper** | Background task that polls status for pending transactions to recover lost webhooks. |
| **Circuit breaker** | Per-gateway failure counter that fails fast when a gateway is unhealthy and recovers automatically. |

---

*End of specification.*
