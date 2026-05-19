@php
    /** @var \Froshly\Parakit\Receipts\ReceiptData $receipt */
    $rtl    = $receipt->isRtl();
    $refund = $receipt->isRefund();
    $title  = __($refund ? 'parakit::receipts.title_refund' : 'parakit::receipts.title_payment');
    $status = __('parakit::payments.statuses.' . $receipt->status->value);
    $end    = $rtl ? 'left' : 'right';
@endphp
<!DOCTYPE html>
<html dir="{{ $rtl ? 'rtl' : 'ltr' }}" lang="{{ $receipt->locale }}">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: 'DejaVu Sans', sans-serif; }
        @page { margin: 64px 60px; }
        body { margin: 0; color: #111; font-size: 12px; line-height: 1.6; }
        .title { font-size: 11px; letter-spacing: 4px; text-transform: uppercase; color: #111; }
        .merchant { margin-top: 4px; font-size: 17px; font-weight: bold; }
        .rule { border-bottom: 1px solid #111; margin: 18px 0; }
        .hair { border-bottom: 1px solid #ddd; }
        .rows { width: 100%; }
        .rows td { padding: 9px 0; font-size: 12px; }
        .rows .k { color: #888; text-transform: uppercase; font-size: 9px; letter-spacing: 1px; }
        .rows .v { text-align: {{ $end }}; }
        .total { width: 100%; margin-top: 8px; }
        .total td { padding: 14px 0; }
        .total .k { font-size: 11px; letter-spacing: 2px; text-transform: uppercase; color: #888; }
        .total .v { text-align: {{ $end }}; font-size: 24px; font-weight: bold; letter-spacing: -.5px; }
        .note { margin-top: 18px; font-size: 10px; color: #888; }
        .foot { margin-top: 48px; font-size: 10px; color: #aaa; }
    </style>
</head>
<body>

    <div class="title">{{ $title }}</div>
    <div class="merchant">{{ $receipt->merchant['name'] ?? '' }}</div>
    @if(!empty($receipt->merchant['address']))
        <div style="color:#888; font-size:11px;">{{ $receipt->merchant['address'] }}</div>
    @endif

    <div class="rule"></div>

    <table class="rows">
        <tr class="hair">
            <td class="k" width="40%">{{ __('parakit::receipts.reference') }}</td>
            <td class="v">{{ $receipt->reference }}</td>
        </tr>
        <tr class="hair">
            <td class="k">{{ __('parakit::receipts.transaction_id') }}</td>
            <td class="v">{{ $receipt->gatewayTransactionId ?? $receipt->transactionId }}</td>
        </tr>
        <tr class="hair">
            <td class="k">{{ __('parakit::receipts.gateway') }}</td>
            <td class="v">{{ ucfirst($receipt->gateway) }}</td>
        </tr>
        <tr class="hair">
            <td class="k">{{ __('parakit::receipts.status') }}</td>
            <td class="v">{{ $status }}</td>
        </tr>
        <tr class="hair">
            <td class="k">{{ $receipt->paidAt ? __('parakit::receipts.paid_at') : __('parakit::receipts.issued') }}</td>
            <td class="v">{{ ($receipt->paidAt ?? $receipt->issuedAt)->format('Y-m-d H:i') }}</td>
        </tr>
        @if($receipt->customerName || $receipt->customerEmail)
        <tr class="hair">
            <td class="k">{{ __('parakit::receipts.customer') }}</td>
            <td class="v">{{ $receipt->customerName ?? $receipt->customerEmail }}</td>
        </tr>
        @endif
        @if($refund)
        <tr class="hair">
            <td class="k">{{ __('parakit::receipts.amount') }}</td>
            <td class="v">{{ $receipt->amountFormatted }} {{ $receipt->currencySymbol() }}</td>
        </tr>
        <tr class="hair">
            <td class="k">{{ __('parakit::receipts.refunded') }}</td>
            <td class="v">{{ $receipt->refundedFormatted }} {{ $receipt->currencySymbol() }}</td>
        </tr>
        @endif
    </table>

    <table class="total">
        <tr>
            <td class="k">{{ $refund ? __('parakit::receipts.total_refunded') : __('parakit::receipts.total_paid') }}</td>
            <td class="v">{{ ($refund ? $receipt->refundedFormatted : $receipt->amountFormatted) }} {{ $receipt->currencySymbol() }}</td>
        </tr>
    </table>

    @if($receipt->isPartialRefund)
        <div class="note">{{ __('parakit::receipts.partial_refund_note') }}</div>
    @endif

    <div class="foot">
        {{ $refund ? __('parakit::receipts.refund_processed') : __('parakit::receipts.thank_you') }}
        @if(!empty($receipt->merchant['support_email']))
            — {{ __('parakit::receipts.support', ['email' => $receipt->merchant['support_email']]) }}
        @endif
    </div>

</body>
</html>
