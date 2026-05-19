@php
    /** @var \Froshly\Parakit\Receipts\ReceiptData $receipt */
    $rtl    = $receipt->isRtl();
    $refund = $receipt->isRefund();
    $title  = __($refund ? 'parakit::receipts.title_refund' : 'parakit::receipts.title_payment');
    $status = __('parakit::payments.statuses.' . $receipt->status->value);
    $end    = $rtl ? 'left' : 'right';
    $customerParts = array_filter([
        $receipt->customerName, $receipt->customerEmail, $receipt->customerPhone,
    ]);
@endphp
<!DOCTYPE html>
<html dir="{{ $rtl ? 'rtl' : 'ltr' }}" lang="{{ $receipt->locale }}">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: 'DejaVu Sans', sans-serif; }
        @page { margin: 36px; }
        body { margin: 0; color: #1a1a1a; font-size: 12px; line-height: 1.55; }
        .frame { border: 2px solid #1a1a1a; padding: 0; }
        .inner { border: 1px solid #1a1a1a; margin: 5px; padding: 28px 32px; }
        .head { text-align: center; border-bottom: 1px solid #1a1a1a; padding-bottom: 18px; margin-bottom: 20px; }
        .merchant { font-size: 20px; font-weight: bold; letter-spacing: .5px; }
        .addr { color: #555; font-size: 11px; }
        .title { margin-top: 10px; font-size: 13px; font-weight: bold; letter-spacing: 3px; text-transform: uppercase; }
        .meta { width: 100%; margin-bottom: 18px; }
        .meta td { padding: 3px 0; font-size: 11px; }
        .meta .k { color: #555; }
        .meta .v { text-align: {{ $end }}; font-weight: bold; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.items th {
            border: 1px solid #1a1a1a; padding: 8px 10px; font-size: 10px;
            text-transform: uppercase; letter-spacing: 1px; background: #1a1a1a; color: #fff;
        }
        table.items td { border: 1px solid #1a1a1a; padding: 9px 10px; }
        .num { text-align: {{ $end }}; }
        .total-row td { font-weight: bold; font-size: 13px; background: #f0f0f0; }
        .note { margin-top: 14px; font-size: 11px; font-style: italic; color: #555; }
        .foot { text-align: center; border-top: 1px solid #1a1a1a; margin-top: 22px; padding-top: 14px; font-size: 10px; color: #555; }
    </style>
</head>
<body>
<div class="frame"><div class="inner">

    <div class="head">
        <div class="merchant">{{ $receipt->merchant['name'] ?? '' }}</div>
        @if(!empty($receipt->merchant['address']))
            <div class="addr">{{ $receipt->merchant['address'] }}</div>
        @endif
        <div class="title">{{ $title }}</div>
    </div>

    <table class="meta">
        <tr>
            <td class="k">{{ __('parakit::receipts.reference') }}</td>
            <td class="v">{{ $receipt->reference }}</td>
        </tr>
        <tr>
            <td class="k">{{ __('parakit::receipts.transaction_id') }}</td>
            <td class="v">{{ $receipt->gatewayTransactionId ?? $receipt->transactionId }}</td>
        </tr>
        <tr>
            <td class="k">{{ __('parakit::receipts.gateway') }}</td>
            <td class="v">{{ ucfirst($receipt->gateway) }}</td>
        </tr>
        <tr>
            <td class="k">{{ __('parakit::receipts.status') }}</td>
            <td class="v">{{ $status }}</td>
        </tr>
        <tr>
            <td class="k">{{ $receipt->paidAt ? __('parakit::receipts.paid_at') : __('parakit::receipts.issued') }}</td>
            <td class="v">{{ ($receipt->paidAt ?? $receipt->issuedAt)->format('Y-m-d H:i') }}</td>
        </tr>
        @if($receipt->hasCustomer())
        <tr>
            <td class="k">{{ __('parakit::receipts.billed_to') }}</td>
            <td class="v">{{ implode(' · ', $customerParts) }}</td>
        </tr>
        @endif
    </table>

    <table class="items">
        <tr>
            <th>{{ __('parakit::receipts.description') }}</th>
            <th class="num">{{ __('parakit::receipts.amount') }}</th>
        </tr>
        <tr>
            <td>{{ __('parakit::receipts.amount') }}</td>
            <td class="num">{{ $receipt->amountFormatted }} {{ $receipt->currencySymbol() }}</td>
        </tr>
        @if($refund)
        <tr>
            <td>{{ __('parakit::receipts.refunded') }}</td>
            <td class="num">{{ $receipt->refundedFormatted }} {{ $receipt->currencySymbol() }}</td>
        </tr>
        <tr class="total-row">
            <td>{{ __('parakit::receipts.total_refunded') }}</td>
            <td class="num">{{ $receipt->refundedFormatted }} {{ $receipt->currencySymbol() }}</td>
        </tr>
        @else
        <tr class="total-row">
            <td>{{ __('parakit::receipts.total_paid') }}</td>
            <td class="num">{{ $receipt->amountFormatted }} {{ $receipt->currencySymbol() }}</td>
        </tr>
        @endif
    </table>

    @if($receipt->isPartialRefund)
        <div class="note">{{ __('parakit::receipts.partial_refund_note') }}</div>
    @endif

    <div class="foot">
        <div>{{ $refund ? __('parakit::receipts.refund_processed') : __('parakit::receipts.thank_you') }}</div>
        @if(!empty($receipt->merchant['support_email']))
            <div>{{ __('parakit::receipts.support', ['email' => $receipt->merchant['support_email']]) }}</div>
        @endif
    </div>

</div></div>
</body>
</html>
