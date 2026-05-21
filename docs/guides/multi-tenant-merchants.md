---
title: Multi-tenant merchants
---

# Multi-tenant merchants

If you process payments for more than one merchant — separate branches, white-label
tenants, or just a "live" and a "test" account — you give each one its own named
config and pick it per charge.

```php
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Facades\Payment;

$response = Payment::for($order)
    ->driver('fib_branch_a')        // the config key, not the driver type
    ->amount(5000, Currency::IQD)
    ->description("Order #{$order->id}")
    ->idempotencyKey("order:{$order->id}")
    ->charge();
```

`'fib_branch_a'` here is a key under `parakit.gateways` — not the driver name
`'fib'`. That distinction is the whole point of this page, so it is worth being
precise about.

## The config key is the merchant identity

`config/parakit.php` has a `gateways` array. Each entry is a named config, and
its `driver` value says which gateway code runs it:

```php
// config/parakit.php
'gateways' => [
    'fib_branch_a' => [
        'driver'        => 'fib',
        'base_url'      => env('FIB_A_BASE_URL', 'https://fib.stage.fib.iq'),
        'client_id'     => env('FIB_A_CLIENT_ID'),
        'client_secret' => env('FIB_A_CLIENT_SECRET'),
        'callback_url'  => env('FIB_A_CALLBACK_URL'),
    ],
    'fib_branch_b' => [
        'driver'        => 'fib',
        'base_url'      => env('FIB_B_BASE_URL', 'https://fib.stage.fib.iq'),
        'client_id'     => env('FIB_B_CLIENT_ID'),
        'client_secret' => env('FIB_B_CLIENT_SECRET'),
        'callback_url'  => env('FIB_B_CALLBACK_URL'),
    ],
],
```

Both entries run the same `fib` driver code, but they are two independent
merchants. When you call `Payment::driver('fib_branch_a')`, `PaymentManager`
builds the gateway with the config key as the gateway's name — look at
`PaymentManager::makeDriver()`:

```php
$driver = $cfg['driver'] ?? $name;   // 'fib' — which class to instantiate
// ...
return $this->{$method}($cfg, $name); // $name — 'fib_branch_a', the identity
```

The class is chosen by `driver`, but the *name* passed into the gateway is the
config key. That name is what the gateway uses for:

- its **circuit-breaker** cache key — one branch tripping does not open the
  breaker for the other,
- its **idempotency** namespace (`parakit:charge:idem:...` hashed per gateway, plus the per-gateway `payment_refunds` row),
- the **`gateway` column** written to `payment_transactions`,
- its **webhook route** — `POST /payments/webhooks/fib_branch_a`.

::: tip
Configure each merchant's `callback_url` to point at its own route — the one
that ends in the config key. A callback that lands on the wrong route is
verified against the wrong credentials and rejected. See
[Handling webhooks](/guides/handling-webhooks).
:::

If you omit `driver`, the config key is used as the driver type too. That works
only when the key happens to match a real driver name (`fib`, `zaincash`,
`nass`, `nasswallet`, `fastpay`). Any named config should set `driver`
explicitly.

## Selecting a merchant per request

Store the config key on whatever owns the payment — an order, a tenant, a
merchant row — and pass it to `driver()`:

```php
$response = Payment::for($order)
    ->driver($order->merchant->gateway_key)  // e.g. 'fib_branch_b'
    ->amount($order->total_minor, $order->currency)
    ->description("Order #{$order->id}")
    ->charge();
```

With no argument, `driver()` falls back to `parakit.default`. That is fine for a
single-merchant app; a multi-tenant app should always pass the key so a charge
can never silently hit the wrong account.

## Database-backed credentials — `resolveMerchantUsing()`

Static config works when merchants are known at deploy time. When merchants
self-onboard and their credentials live in your database, register a resolver
instead. Parakit calls it with the requested name and expects a config array
back.

```php
// In a service provider's boot()
use Froshly\Parakit\Facades\Payment;

Payment::resolveMerchantUsing(function (string $name): array {
    $merchant = Merchant::where('gateway_key', $name)->firstOrFail();

    return [
        'driver'        => 'fib',
        'base_url'      => $merchant->fib_base_url,
        'client_id'     => decrypt($merchant->fib_client_id),
        'client_secret' => decrypt($merchant->fib_client_secret),
        'callback_url'  => route('parakit.webhook', ['gateway' => $name]),
    ];
});
```

Once registered, the resolver takes over completely: `Payment::driver($name)`
calls it instead of reading `parakit.gateways`. Return the same array shape a
static entry would have — `driver` plus that driver's credentials.

The closure return is untrusted, so `PaymentManager::driver()` guards it at
runtime:

```php
$cfg = ($this->merchantResolver)($name);
if (!is_array($cfg) || $cfg === []) {
    throw new UnsupportedGatewayException("Merchant resolver returned no config for: {$name}");
}
```

A resolver that returns `null`, a non-array, or an empty array throws
`UnsupportedGatewayException` rather than building a half-configured gateway.

::: warning
Store gateway secrets encrypted (Laravel's `encrypt()`/`decrypt()`, or
references to a secret manager) and decrypt inside the resolver. Plain-text
`client_secret` columns are a credential leak waiting to happen.
:::

## Octane and resolved-driver caching

`PaymentManager` memoises each gateway it builds, keyed by name, so repeated
`driver('fib_branch_a')` calls in one request reuse one instance.

Under a long-lived runtime like Octane that cache would otherwise survive
between requests and leak one tenant's gateway into the next. Parakit prevents
that: `flushResolved()` clears the cache, and the service provider wires it to
both `RequestHandled` and Octane's `RequestTerminated`. Every request starts
with an empty resolver cache.

If you change a merchant's credentials mid-request and need the next `driver()`
call to rebuild, flush manually:

```php
app('parakit.manager')->flushResolved();
```

::: tip
`flushResolved()` only drops the in-memory instance cache. It does not touch
circuit-breaker state or the idempotency cache — those are keyed by merchant
name and persist deliberately.
:::
