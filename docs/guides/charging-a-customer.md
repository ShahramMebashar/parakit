---
title: Charging a customer
---

# Charging a customer

To take a payment, build a charge with the `Payment` facade and call `charge()`.
It returns a `PaymentResponse` describing how the customer completes the payment
— a QR code, a deep link, or a hosted redirect, depending on the gateway.

```php
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\Enums\Currency;

$response = Payment::for($order)
    ->driver('fib')
    ->amount(25_000, Currency::IQD)
    ->description("Order #{$order->id}")
    ->idempotencyKey($order->id)
    ->charge();
```

This persists a `payment_transactions` row, calls the gateway, and hands back a
`PaymentResponse`. A successful call means the payment was **initiated** — the
customer still has to complete it. You learn the final outcome from a
[webhook](/guides/handling-webhooks).

## The `Payment` facade

`Payment` resolves `Froshly\Parakit\PaymentManager`. It has four methods:

| Method | Returns | Use it for |
|---|---|---|
| `for($reference, $keyAttribute = 'id')` | `PaymentBuilder` | Start a fluent charge. |
| `driver(?string $name = null)` | `PaymentGateway` | Get a gateway directly. `null` uses `parakit.default`. |
| `extend($driver, Closure $creator)` | `void` | Register a [custom gateway](/guides/custom-gateway). |
| `resolveMerchantUsing(Closure $resolver)` | `void` | Supply per-tenant config — see [Multi-tenant merchants](/guides/multi-tenant-merchants). |

`for()` accepts a model or a plain string. Given a model, it reads the key named
by `$keyAttribute` (`id` by default) and uses it as the transaction
`reference`:

```php
Payment::for($order);          // reference = $order->id
Payment::for($order, 'uuid');  // reference = $order->uuid
Payment::for('ORD-2048');      // reference = 'ORD-2048'
```

## The fluent `PaymentBuilder`

`Payment::for(...)` returns a `PaymentBuilder`. Every setter returns the builder,
so calls chain in any order. `charge()` is terminal.

| Method | Required | Notes |
|---|---|---|
| `amount(int $minor, Currency $c)` | **Yes** | Amount in minor units. See [Amounts](#amounts-and-minor-units). |
| `description(string $d)` | **Yes** | Must be non-empty. FIB rejects an empty description; ZainCash uses it as the service type. |
| `driver(string $name)` | No | A named gateway config key. Omit to use `parakit.default`. |
| `idempotencyKey(string $k)` | No | Strongly recommended — see [Idempotency keys](#idempotency-keys). |
| `metadata(array $m)` | No | Arbitrary data stored on the transaction row. Some gateways also read keys from it (FIB reads `refundable_for`, `expires_in`, `category`). |
| `callbackUrl(string $u)` | No | Server-to-server webhook URL for this charge. Overrides the gateway's configured callback URL. |
| `returnUrl(string $u)` | No | Where the customer's browser or app returns after paying or cancelling. |
| `customerPhone(string $p)` | No | Customer phone number, where the gateway uses one. |
| `charge()` | — | Runs the charge; returns `PaymentResponse`. |

`charge()` throws `InvalidArgumentException` before any network call when
`amount()` was not set or `description()` is empty. The failure names the cause,
so you do not wait for a gateway `400` to find out.

```php
$response = Payment::for($order)
    ->driver('fib')
    ->amount(25_000, Currency::IQD)
    ->description("Order #{$order->id}")
    ->idempotencyKey($order->id)
    ->callbackUrl(route('parakit.webhook', ['gateway' => 'fib']))
    ->returnUrl(route('checkout.return', $order))
    ->customerPhone($order->customer_phone)
    ->metadata(['cart_id' => $order->cart_id])
    ->charge();
```

## Charging with the explicit DTO

Skip the builder when you already have the request assembled — pass a
`PaymentRequest` straight to the gateway:

```php
use Froshly\Parakit\DTOs\PaymentRequest;
use Froshly\Parakit\Enums\Currency;

$response = Payment::driver('zaincash')->charge(new PaymentRequest(
    reference: $order->id,
    amount: 25_000,
    currency: Currency::IQD,
    description: 'Order #' . $order->id,
));
```

`PaymentRequest` is a readonly DTO. Its constructor takes:

| Property | Type | Notes |
|---|---|---|
| `reference` | `string` | Required. Throws if empty. |
| `amount` | `int` | Required, minor units. Throws if `<= 0`. |
| `currency` | `Currency` | Required. |
| `description` | `string` | Required. |
| `customerPhone` | `?string` | Optional. |
| `customerEmail` | `?string` | Optional. |
| `customerName` | `?string` | Optional. |
| `callbackUrl` | `?string` | Optional. |
| `returnUrl` | `?string` | Optional. |
| `idempotencyKey` | `?string` | Optional. |
| `metadata` | `array` | Optional, defaults to `[]`. |

::: tip
The builder constructs this DTO for you. It does not expose `customerEmail`
or `customerName` — use the DTO directly when you need those fields.
:::

## Amounts and minor units

Amounts are always integers in **minor units**. `Currency::minorUnitFactor()`
defines how many minor units make one major unit:

| Currency | Factor | Pass | Means |
|---|---|---|---|
| `Currency::IQD` | `1` | `25_000` | 25,000 dinars |
| `Currency::USD` | `100` | `150` | 1.50 USD (150 cents) |

IQD has no subdivision in practice, so its factor is `1` — pass whole dinars.
USD has a factor of `100` — pass cents. `PaymentRequest` rejects any amount
that is not a positive integer.

## Reading the response

`PaymentResponse` is a readonly DTO. Its useful properties:

| Property | Type | Notes |
|---|---|---|
| `success` | `bool` | `true` if the gateway accepted the charge. |
| `status` | `PaymentStatus` | Usually `Pending` right after `charge()`. |
| `gateway` | `string` | The named config that handled it. |
| `gatewayTransactionId` | `?string` | The gateway's own id. |
| `reference` | `string` | Your reference. |
| `amount` / `currency` | `int` / `Currency` | Echoes the request. |
| `correlationId` | `string` | ULID tracing this payment across logs. |
| `redirectUrl` | `?string` | Hosted checkout URL (ZainCash, FastPay). |
| `qrCode` | `?string` | QR payload (FIB). |
| `deepLink` | `?string` | App deep link (FIB). |
| `readableCode` | `?string` | Short human-readable code (FIB). |
| `expiresAt` | `?DateTimeImmutable` | When the payment stops being payable. |
| `error` | `?PaymentError` | Set when the charge failed. |
| `raw` | `array` | The gateway response body. Fresh gateway calls return it untouched; idempotency replays may return a redacted or empty copy according to `parakit.raw_payloads`. |

`failed()` is the inverse of `success`.

Which fields are populated depends on the gateway flow — QR versus redirect:

```php
if ($response->failed()) {
    return back()->with('error', $response->error->message(app()->getLocale()));
}

// FIB — render a QR the customer scans in their banking app
if ($response->qrCode !== null) {
    return view('checkout.fib', [
        'qrCode'       => $response->qrCode,
        'readableCode' => $response->readableCode,
        'deepLink'     => $response->deepLink,
    ]);
}

// ZainCash / FastPay — hosted redirect
return redirect()->away($response->redirectUrl);
```

### Errors

When a charge fails, `error` holds a `PaymentError`:

- `code` — a `PaymentErrorCode` enum (e.g. `InsufficientFunds`, `InvalidPhone`,
  `GatewayUnavailable`). Use this for branching.
- `rawCode` / `rawMessage` — the gateway's own code and message.
- `message(?string $locale = null)` — a translated, customer-safe message,
  falling back to `rawMessage` when no translation exists.

```php
use Froshly\Parakit\Enums\PaymentErrorCode;

if ($response->failed() && $response->error->code === PaymentErrorCode::InvalidPhone) {
    return back()->withErrors(['phone' => 'That phone number was rejected.']);
}
```

::: warning
A hard gateway failure (timeout, an open circuit breaker) throws
`GatewayUnavailableException` rather than returning a failed response. Wrap
`charge()` in a `try/catch` if you need to handle that path — see
[Reliability](/guides/reliability).
:::

## Idempotency keys

Without an idempotency key, a double-submitted checkout form creates two
charges. With one, a second `charge()` within `parakit.reliability.idempotency_ttl`
(24h by default) returns the cached first response instead of calling the
gateway again.

Cached responses are package-owned persisted data. Their `raw` payload follows
the `parakit.raw_payloads` policy, so it may be redacted by default or empty if
raw payload storage is disabled.

The key is also a precondition for safe retries. See
[Reliability](/guides/reliability).

Use a value that is stable for the logical payment — the order id is ideal:

```php
->idempotencyKey($order->id)
```

If you omit the key, parakit derives one from the gateway, reference, amount,
and currency. That still deduplicates identical charges, but a deliberate
re-charge of the same order for a different amount counts as new.
