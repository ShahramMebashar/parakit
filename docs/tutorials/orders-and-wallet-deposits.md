---
title: Orders and wallet deposits
---

# Accept order payments and wallet deposits

This tutorial uses one Parakit installation for two payment purposes:

1. A customer pays for an order.
2. A customer deposits money into their application wallet.

Both use the same FIB gateway and webhook. Your application distinguishes them
with a namespaced payment reference and metadata, then performs the correct
business action when Parakit fires `PaymentSucceeded`.

Parakit handles the gateway call, webhook verification, transaction status,
and duplicate webhook delivery. Your application still owns order fulfilment
and wallet accounting.

## Before you start

Install Parakit and configure FIB as the default gateway:

```bash
composer require froshly/parakit
php artisan parakit:install
```

```env
PARAKIT_DEFAULT=fib

FIB_BASE_URL=https://fib.stage.fib.iq
FIB_CLIENT_ID=your-fib-client-id
FIB_CLIENT_SECRET=your-fib-client-secret
FIB_CALLBACK_URL=https://yourapp.com/payments/webhooks/fib
```

Verify the connection before building the application flow:

```bash
php artisan parakit:doctor --gateway=fib
```

See [Installation](/installation) and the [FIB gateway guide](/gateways/fib)
for the complete setup.

## Use namespaced references

`Payment::for($model)` uses only the model's key as the payment reference. An
order and a wallet deposit can both have an ID of `123`, so a bare ID is
ambiguous when handling a successful payment.

Use namespaced references instead:

```text
order:123
wallet_deposit:123
```

Also store the payment purpose and application model ID in metadata. Parakit
persists this metadata on its `payment_transactions` row and returns it on the
transaction carried by lifecycle events.

## Pay for an order

The example assumes the order stores `amount_due`, `currency`, `status`,
`payment_transaction_id`, and `paid_at`.

Create the FIB payment from your checkout controller:

```php
use App\Models\Order;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Facades\Payment;

public function store(Order $order)
{
    abort_unless($order->user_id === auth()->id(), 403);
    abort_if($order->status === 'paid', 409, 'Order is already paid.');

    $response = Payment::for("order:{$order->id}")
        ->amount($order->amount_due, Currency::IQD)
        ->description("Order #{$order->id}")
        ->idempotencyKey("order:{$order->id}")
        ->metadata([
            'purpose' => 'order',
            'order_id' => (string) $order->id,
        ])
        ->charge();

    return view('payments.fib', [
        'qrCode' => $response->qrCode,
        'readableCode' => $response->readableCode,
        'deepLink' => $response->deepLink,
        'expiresAt' => $response->expiresAt,
    ]);
}
```

Because `PARAKIT_DEFAULT=fib`, the example does not need
`->driver('fib')`. Add it when the customer can choose between gateways.

The response means FIB accepted and created the payment. It does **not** mean
the order has been paid. Show the QR code or deep link, then wait for
`PaymentSucceeded`.

The order ID is a suitable idempotency key when an order can have only one
payment attempt. If your application allows a new attempt after a terminal
failure, create a payment-attempt record and include its ID in both the
reference and idempotency key.

## Deposit into a wallet

Create one application-owned `WalletDeposit` record for each deposit attempt
before calling the gateway. A minimal record needs:

- `id`
- `wallet_id`
- `amount`
- `currency`
- `status` (`pending`, `completed`, or `failed`)
- nullable `payment_transaction_id`
- nullable `credited_at`

Then initiate the payment:

```php
use App\Models\WalletDeposit;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Facades\Payment;
use Illuminate\Http\Request;

public function store(Request $request)
{
    $validated = $request->validate([
        'amount' => ['required', 'integer', 'min:1000'],
    ]);

    $wallet = $request->user()->wallet;

    $deposit = WalletDeposit::create([
        'wallet_id' => $wallet->id,
        'amount' => $validated['amount'],
        'currency' => Currency::IQD->value,
        'status' => 'pending',
    ]);

    $response = Payment::for("wallet_deposit:{$deposit->id}")
        ->amount($deposit->amount, Currency::IQD)
        ->description("Wallet deposit #{$deposit->id}")
        ->idempotencyKey("wallet-deposit:{$deposit->id}")
        ->metadata([
            'purpose' => 'wallet_deposit',
            'deposit_id' => (string) $deposit->id,
        ])
        ->charge();

    return view('payments.fib', [
        'qrCode' => $response->qrCode,
        'readableCode' => $response->readableCode,
        'deepLink' => $response->deepLink,
        'expiresAt' => $response->expiresAt,
    ]);
}
```

The minimum deposit in this example is an application rule, not a Parakit
requirement. IQD amounts are whole dinars, so `1000` means IQD 1,000.

## Apply successful payments

Use one queued listener to route successful payments by their purpose:

```php
namespace App\Listeners;

use App\Models\Order;
use App\Models\WalletDeposit;
use App\Models\WalletLedgerEntry;
use Froshly\Parakit\Events\PaymentSucceeded;
use Froshly\Parakit\Models\PaymentTransaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use LogicException;

class CompleteSuccessfulPayment implements ShouldQueue
{
    public int $tries = 5;

    public function handle(PaymentSucceeded $event): void
    {
        $transaction = $event->transaction;

        match ($transaction->metadata['purpose'] ?? null) {
            'order' => $this->completeOrder($transaction),
            'wallet_deposit' => $this->completeDeposit($transaction),
            default => throw new LogicException('Unknown payment purpose.'),
        };
    }

    private function completeOrder(PaymentTransaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $order = Order::query()
                ->lockForUpdate()
                ->findOrFail($transaction->metadata['order_id']);

            if ($order->status === 'paid') {
                return;
            }

            if (
                (int) $order->amount_due !== $transaction->amount
                || $order->currency !== $transaction->currency->value
            ) {
                throw new LogicException('Order payment amount mismatch.');
            }

            $order->update([
                'status' => 'paid',
                'payment_transaction_id' => $transaction->id,
                'paid_at' => now(),
            ]);
        });
    }

    private function completeDeposit(PaymentTransaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $deposit = WalletDeposit::query()
                ->lockForUpdate()
                ->findOrFail($transaction->metadata['deposit_id']);

            if ($deposit->status === 'completed') {
                return;
            }

            if (
                (int) $deposit->amount !== $transaction->amount
                || $deposit->currency !== $transaction->currency->value
            ) {
                throw new LogicException('Wallet deposit amount mismatch.');
            }

            WalletLedgerEntry::create([
                'wallet_id' => $deposit->wallet_id,
                'wallet_deposit_id' => $deposit->id,
                'payment_transaction_id' => $transaction->id,
                'type' => 'credit',
                'amount' => $transaction->amount,
                'currency' => $transaction->currency->value,
            ]);

            $deposit->update([
                'status' => 'completed',
                'payment_transaction_id' => $transaction->id,
                'credited_at' => now(),
            ]);
        });
    }
}
```

Register the listener in your application's event configuration or in a service
provider:

```php
use App\Listeners\CompleteSuccessfulPayment;
use Froshly\Parakit\Events\PaymentSucceeded;
use Illuminate\Support\Facades\Event;

Event::listen(
    PaymentSucceeded::class,
    CompleteSuccessfulPayment::class,
);
```

When you use an asynchronous queue connection, keep a queue worker running so
the listener can process:

```bash
php artisan queue:work
```

If the wallet has a cached `balance` column, update it inside the same database
transaction as the ledger entry. Do not update the balance separately.

## Make wallet credits idempotent

Parakit deduplicates gateway webhooks, but your listener must also be safe to
retry. A queue worker can retry a failed job, and Parakit's pending-payment
sweeper can also discover a completed payment.

Add unique indexes to the wallet ledger:

```php
$table->unique('wallet_deposit_id');
$table->unique('payment_transaction_id');
```

The locked deposit row, `completed` check, database transaction, and unique
indexes ensure one successful deposit creates one wallet credit.

Use the wallet ledger as the financial record. Avoid incrementing a wallet
balance without also inserting a uniquely identified ledger entry.

## Handle failed payments

`PaymentFailed` and `PaymentCancelled` report asynchronous terminal outcomes.
Listen for them when your UI needs to mark the related order payment attempt or
wallet deposit as failed. Never fulfil the order or create a wallet ledger
entry from those events.

`charge()` can also throw before returning a payment response. Handle that
exception in the controller and show a safe error to the customer. A timeout or
`GatewayUnavailableException` has an unknown remote outcome, so leave the
application record pending and let Parakit's sweeper reconcile it instead of
immediately declaring it failed.

A later retry should use a new payment-attempt or wallet-deposit record. Do not
reuse an idempotency key for a genuinely new gateway charge.

## Rules to keep

- Use one gateway webhook for every payment purpose.
- Use namespaced references such as `order:123` and `wallet_deposit:123`.
- Store the application purpose and model ID in payment metadata.
- Treat `charge()` as payment initiation, not payment confirmation.
- Fulfil orders and credit wallets only from `PaymentSucceeded`.
- Compare the paid amount and currency with your application record.
- Make every success handler transactional and idempotent.
- Treat browser redirects, QR pages, and deep links as UI only.

For the underlying mechanics, see [Charging a customer](/guides/charging-a-customer)
and [Handling webhooks](/guides/handling-webhooks).
