---
title: Writing a custom gateway
---

# Writing a custom gateway

Parakit ships five drivers (FIB, ZainCash, Nass Pay, Nass Wallet, FastPay). To
add a provider it does not cover, implement the `PaymentGateway` contract and
register it with `Payment::extend()`.

```php
// In a service provider's boot()
use Froshly\Parakit\Facades\Payment;

Payment::extend('acmepay', fn ($cfg, $container, $name) => new AcmePayGateway($name, $cfg));
```

After that, a config entry with `'driver' => 'acmepay'` charges through your
class just like a built-in driver.

## The contract

A gateway must implement three methods:

```php
namespace Froshly\Parakit\Contracts;

use Illuminate\Http\Request;
use Froshly\Parakit\DTOs\PaymentRequest;
use Froshly\Parakit\DTOs\PaymentResponse;
use Froshly\Parakit\DTOs\WebhookPayload;

interface PaymentGateway
{
    public function charge(PaymentRequest $request): PaymentResponse;
    public function handleWebhook(Request $request): WebhookPayload;
    public function name(): string;
}
```

- `charge()` takes the canonical `PaymentRequest` (amount in minor units) and
  returns a `PaymentResponse`.
- `handleWebhook()` verifies an incoming gateway callback and returns a
  `WebhookPayload`. Throw `InvalidWebhookSignatureException` when verification
  fails — the webhook controller turns that into a 401.
- `name()` returns the gateway's identity string.

You can implement the contract directly, but most gateways should extend
`AbstractGateway` instead.

## Extending `AbstractGateway`

`Froshly\Parakit\Gateways\AbstractGateway` implements `PaymentGateway` and gives
you the reliability layer for free. Its constructor signature is:

```php
public function __construct(
    protected readonly string $gatewayName,
    protected readonly array $config,
) { /* ... */ }
```

Extend it and you get:

- **Idempotency** — `charge()` derives a key, checks a per-gateway cache, and
  returns the cached `PaymentResponse` on a repeat call.
- **A write-ahead transaction** — a `Pending` `payment_transactions` row is
  persisted *before* the gateway call, so a crash or a racing webhook still has
  a row to land on.
- **Retries with backoff** — transient failures (`GatewayUnavailableException`)
  are retried per `parakit.reliability.retry` unless the driver disables them.
- **A circuit breaker** — keyed by your gateway name; `charge()` throws
  `GatewayUnavailableException` while the breaker is open.
- **The `PaymentInitiated` event**, fired exactly once per `charge()` call.

In exchange, you implement two abstract methods instead of `charge()` itself:

```php
abstract protected function performCharge(PaymentRequest $request): PaymentResponse;
abstract public function handleWebhook(Request $request): WebhookPayload;
```

`AbstractGateway::charge()` wraps `performCharge()` with everything above.
`name()` is already implemented — it returns the name passed to the constructor.

::: warning
Throw `GatewayUnavailableException` from `performCharge()` only for genuinely
transient failures (timeouts, 5xx, network errors). It is the one exception the
retry loop catches. Exhausted transient failures leave the transaction
`Pending`, because the provider outcome may be unknown. Any other `Throwable`
marks the transaction failed and propagates immediately.
:::

If the provider cannot accept a stable merchant request ID and cannot reconcile
an ambiguous create, disable retries:

```php
protected function retryChargeOnTransientFailure(): bool
{
    return false;
}
```

## Skeleton

```php
namespace App\Payments;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Froshly\Parakit\DTOs\PaymentRequest;
use Froshly\Parakit\DTOs\PaymentResponse;
use Froshly\Parakit\DTOs\WebhookPayload;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Exceptions\InvalidWebhookSignatureException;
use Froshly\Parakit\Gateways\AbstractGateway;

final class AcmePayGateway extends AbstractGateway
{
    protected function performCharge(PaymentRequest $request): PaymentResponse
    {
        // 1. Map PaymentRequest onto AcmePay's API shape.
        // 2. Call the provider. Throw GatewayUnavailableException on a
        //    transient failure so the base class retries it.
        // 3. Map the provider response onto a PaymentResponse.
        return new PaymentResponse(
            success: true,
            gateway: $this->name(),               // the config key
            gatewayTransactionId: $raw['id'],
            reference: $request->reference,
            status: PaymentStatus::Pending,
            amount: $request->amount,
            currency: $request->currency,
            correlationId: $this->correlationId(),
            redirectUrl: $raw['checkout_url'] ?? null,
            raw: $raw,
        );
    }

    public function handleWebhook(Request $request): WebhookPayload
    {
        // Verify the signature first; throw on failure.
        if (! $this->signatureValid($request)) {
            throw new InvalidWebhookSignatureException('AcmePay signature mismatch');
        }

        return new WebhookPayload(
            gateway: $this->name(),
            gatewayTransactionId: (string) $request->input('id'),
            reference: (string) $request->input('order_id'),
            status: PaymentStatus::Paid,
            amount: (int) $request->input('amount'),
            currency: $request->currency,
            eventId: (string) $request->input('event_id'),
            occurredAt: new DateTimeImmutable(),
            raw: $request->all(),
        );
    }
}
```

Use `$this->config` for credentials, `$this->name()` for the identity string,
and `$this->correlationId()` for the current request's correlation id.
`FibGateway` (`src/Gateways/Fib/FibGateway.php`) is a complete worked reference.

## Optional capabilities

Implement these only when the provider supports the feature. Parakit checks for
the interface with `instanceof` before calling, so a gateway that does not
implement one simply does not offer that operation.

| Interface | Adds |
|---|---|
| `SupportsRefund` | `refund(RefundRequest): RefundResponse` |
| `SupportsCancel` | `cancel(string $gatewayTransactionId): PaymentResponse` |
| `SupportsStatusCheck` | `status(string $gatewayTransactionId): PaymentResponse` — used by `parakit:transactions:sweep-pending` to recover stuck payments |
| `SupportsTokenization` | `tokenize(PaymentRequest): string` and `chargeToken(string, int, Currency): PaymentResponse` |

```php
use Froshly\Parakit\Contracts\SupportsRefund;
use Froshly\Parakit\Contracts\SupportsStatusCheck;

final class AcmePayGateway extends AbstractGateway implements SupportsRefund, SupportsStatusCheck
{
    // ... performCharge(), handleWebhook(), plus refund() and status()
}
```

## Registering the driver

`Payment::extend()` maps a driver type to a factory closure:

```php
public function extend(string $driver, Closure $creator): void
```

The closure receives three arguments, in this order:

1. `array $cfg` — the resolved config array for the named config being built.
2. `Illuminate\Contracts\Container\Container $container` — the app container,
   for resolving dependencies.
3. `string $name` — the config key (the merchant identity), which is what you
   pass as the gateway name.

```php
use Froshly\Parakit\Facades\Payment;

Payment::extend('acmepay', function (array $cfg, $container, string $name) {
    return new AcmePayGateway($name, $cfg);
});
```

::: warning
Pass `$name` — not `'acmepay'` — as the gateway name. The driver type is shared
by every config that uses it; the name is the per-merchant identity that keys
the circuit breaker, idempotency cache, and webhook route. Hard-coding the
driver type collapses every merchant onto one identity. See
[Multi-tenant merchants](/guides/multi-tenant-merchants).
:::

Then add a config entry that points `driver` at your registered type:

```php
// config/parakit.php
'gateways' => [
    'acmepay' => [
        'driver'   => 'acmepay',
        'base_url' => env('ACMEPAY_BASE_URL'),
        'api_key'  => env('ACMEPAY_API_KEY'),
    ],
],
```

Charge with `Payment::driver('acmepay')`. A custom driver type has no built-in
config check, so [`parakit:doctor`](/reference/commands) reports it as
unverified and asks you to confirm the config by hand.

## Error mapping

The provider returns its own error codes; Parakit's retry logic and your
localised messages both depend on those codes being mapped to a
`Froshly\Parakit\Enums\PaymentErrorCode`. The mapping decides two things:

- **Whether a failure retries.** A code you treat as transient (mapped to a
  `GatewayUnavailableException`) is retried with backoff; anything else is not.
- **What the customer sees.** `PaymentErrorCode` drives the localised
  (en/ar/ckb) `PaymentError` message.

`src/Gateways/Fib/FibErrorMap.php` and `src/Gateways/ZainCash/ZainCashErrorMap.php`
show the shipped pattern — a dedicated map class keyed by the provider's raw
codes.

::: danger
When a code is ambiguous, map it to a permanent error. A missed retry is safer
than retrying a request that already charged the customer.
:::
