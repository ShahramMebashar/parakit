---
title: Receipts
---

# Receipts

Parakit turns a `PaymentTransaction` into a PDF receipt. To generate one, point
the `Receipt` facade at a transaction and choose how to deliver it — as bytes,
an HTTP response, or a file on disk.

```php
use Froshly\Parakit\Facades\Receipt;

// Stream the receipt inline in the browser.
return Receipt::for($transaction)->stream();
```

That renders the default template, picks the locale, fills in customer details,
and returns a `StreamedResponse` showing the PDF in the browser.

::: tip
Receipt configuration — template, disk, filename, merchant block, PDF options —
lives under the `receipts` key. See [Configuration](/configuration#receipts).
:::

## The `Receipt` facade

`Receipt` resolves `Froshly\Parakit\Receipts\ReceiptManager`:

| Method | Returns | Use it for |
|---|---|---|
| `for(PaymentTransaction $tx)` | `ReceiptBuilder` | Start a fluent receipt. |
| `generate($tx, $type, $customer, $template, $locale)` | `ReceiptDocument` | Build a document in one call. |
| `resolveTemplate(string $template)` | `string` | Validate a template name. |

`generate()` takes the transaction, then four optional arguments — a
`ReceiptType` (defaults to `Payment`), customer details, a template name, and a
locale. The builder is the friendlier way to set those.

## The fluent `ReceiptBuilder`

`Receipt::for($tx)` returns a `ReceiptBuilder`. Setters chain; `generate()` is
terminal, and `stream()`, `download()`, `save()` are terminal shortcuts.

| Method | Notes |
|---|---|
| `type(ReceiptType $type)` | `Payment` or `Refund`. Defaults to `Payment`. |
| `asRefund()` | Shorthand for `type(ReceiptType::Refund)`. |
| `template(string $template)` | `modern`, `classic`, or `minimal`. Validated immediately — an unknown name throws `InvalidArgumentException`. |
| `locale(string $locale)` | `en`, `ar`, or `ckb`. Overrides locale resolution. |
| `customer(CustomerDetails\|array $customer)` | The customer identity printed on the receipt. |
| `generate()` | Builds and returns a `ReceiptDocument`. |
| `stream()` | `generate()->stream()`. |
| `download()` | `generate()->download()`. |
| `save(?string $disk = null, ?string $path = null)` | `generate()->save(...)`. |

```php
use Froshly\Parakit\Facades\Receipt;

$path = Receipt::for($transaction)
    ->asRefund()
    ->template('classic')
    ->locale('ar')
    ->customer(['name' => 'Ada Lovelace', 'email' => 'ada@example.com'])
    ->save();
```

## The `ReceiptDocument`

`generate()` returns a `ReceiptDocument`. Constructing it is cheap — the PDF is
rendered lazily on first access and then memoised.

| Method | Returns | Notes |
|---|---|---|
| `html()` | `string` | The rendered receipt HTML. |
| `raw()` | `string` | Raw PDF bytes. |
| `filename()` | `string` | The receipt filename, from the `filename` config. |
| `stream()` | `StreamedResponse` | Inline PDF response (renders in the browser). |
| `download()` | `Response` | Attachment PDF response (browser download dialog). |
| `save(?string $disk = null, ?string $path = null)` | `string` | Writes the PDF to a disk; returns the stored path. |

```php
use Froshly\Parakit\Facades\Receipt;

$document = Receipt::for($transaction)->generate();

$bytes = $document->raw();        // PDF bytes — attach to your own mail, etc.
$name  = $document->filename();   // e.g. "payment-ORD-2048.pdf"
$path  = $document->save('s3');   // store on the s3 disk
```

`filename()` expands the `parakit.receipts.filename` template. The tokens are
`{type}` (`payment` or `refund`), `{reference}`, and `{id}`.

`save()` defaults to the disk and path from `parakit.receipts`. Pass arguments
to override either.

## Receipt types

`ReceiptType` has two cases — `Payment` and `Refund`. The type picks the Blade
view (`parakit::receipts.{template}.{payment|refund}`) and changes the figures
shown:

- A **payment** receipt documents the payment as it stood and never shows refund
  figures, even on a transaction that was later refunded.
- A **refund** receipt shows the refunded amount and flags a partial refund when
  the refund is smaller than the original charge.

## Templates

Three templates ship with the package: `modern`, `classic`, and `minimal`. The
default is `parakit.receipts.template` (`modern`). Override per receipt with
`->template(...)`. An unknown name throws `InvalidArgumentException`.

## Customer details

Gateways rarely return a usable customer name or email, so your application
supplies them. Customer details resolve in this order:

1. **Explicit `customer()`** — a `CustomerDetails` DTO or a plain array with
   `name`, `email`, `phone` keys. This always wins.
2. **Transaction metadata** — for any field not given explicitly, parakit reads
   the transaction's `metadata` using the keys in `parakit.receipts.metadata`
   (`customer_name`, `customer_email`, `customer_phone` by default).
3. **Nothing** — a receipt renders fine with no customer block.

```php
use Froshly\Parakit\Receipts\CustomerDetails;

Receipt::for($transaction)
    ->customer(new CustomerDetails(
        name: 'Ada Lovelace',
        email: 'ada@example.com',
        phone: '0770 000 0000',
    ))
    ->stream();
```

To skip `customer()` entirely, store those values in `metadata` at charge time:

```php
Payment::for($order)
    ->driver('fib')
    ->amount(25_000, Currency::IQD)
    ->description("Order #{$order->id}")
    ->metadata([
        'customer_name'  => $order->customer_name,
        'customer_email' => $order->customer_email,
    ])
    ->charge();
```

## Locale and right-to-left receipts

The receipt locale resolves in this order: an explicit `->locale(...)`, then the
transaction's `metadata` locale key (`locale` by default), then
`parakit.receipts.locale` — which is `app`, meaning the application locale.

Translations ship for `en`, `ar`, and `ckb`. The `ar` and `ckb` templates
render right-to-left automatically.

::: warning
Arabic and Kurdish text needs the bundled **DejaVu Sans** dompdf font, set as
`defaultFont` under `parakit.receipts.pdf.options`. The default config already
uses it. If you change `defaultFont` to a font without Arabic glyphs, those
receipts render as empty boxes.
:::

## Previewing templates

To eyeball a template design without a real payment, use the preview command:

```bash
php artisan parakit:receipt:preview --template=modern --type=payment --locale=en
```

It renders a sample receipt to `storage/parakit-receipts` and prints the path.
Options:

| Option | Default | Notes |
|---|---|---|
| `--template` | `modern` | `modern`, `classic`, or `minimal`. |
| `--type` | `payment` | `payment` or `refund`. |
| `--locale` | `en` | `en`, `ar`, or `ckb`. |
| `--format` | `html` | `html` (fastest for design iteration) or `pdf`. |
| `--output` | `storage/parakit-receipts` | Target directory. |
| `--all` | — | Render every template × type × locale combination. |

## Receipts are not emailed

Parakit generates and delivers receipts as bytes, HTTP responses, or files on
disk. It does **not** email them. Emailing a receipt is your application's job —
get the bytes with `raw()` (or the path from `save()`) and attach them to your
own mailable.
