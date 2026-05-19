@php
    /** @var \Froshly\Parakit\Receipts\ReceiptData $receipt */
    $rtl    = $receipt->isRtl();
    $refund = $receipt->isRefund();
    $accent = '#4f46e5';
    $title  = __($refund ? 'parakit::receipts.title_refund' : 'parakit::receipts.title_payment');
    $status = __('parakit::payments.statuses.' . $receipt->status->value);
@endphp
<!DOCTYPE html>
<html dir="{{ $rtl ? 'rtl' : 'ltr' }}" lang="{{ $receipt->locale }}">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: 'DejaVu Sans', sans-serif; }
        @page { margin: 0; }
        body { margin: 0; color: #1f2937; font-size: 12px; line-height: 1.5; }
        .strip { height: 8px; background: {{ $accent }}; }
        .page { padding: 44px 48px; }
        .row { width: 100%; }
        .row td { vertical-align: top; }
        .merchant { font-size: 21px; font-weight: bold; color: #111827; }
        .muted { color: #6b7280; }
        .title { font-size: 15px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; }
        .pill {
            display: inline-block; margin-top: 6px; padding: 4px 12px;
            background: #eef2ff; color: {{ $accent }};
            border-radius: 11px; font-size: 10px; font-weight: bold; text-transform: uppercase;
        }
        .divider { border-bottom: 1px solid #e5e7eb; margin: 26px 0; }
        .meta td { padding: 5px 0; }
        .label { color: #6b7280; font-size: 10px; text-transform: uppercase; letter-spacing: .6px; }
        .value { font-size: 12px; color: #111827; }
        .panel { background: #f9fafb; border-radius: 10px; padding: 22px 24px; margin-top: 26px; }
        .panel .line { width: 100%; }
        .panel .line td { padding: 4px 0; }
        .panel .k { color: #6b7280; }
        .panel .v { text-align: {{ $rtl ? 'left' : 'right' }}; font-weight: bold; }
        .total-k { font-size: 13px; font-weight: bold; color: #111827; }
        .total-v { font-size: 22px; font-weight: bold; color: {{ $accent }}; text-align: {{ $rtl ? 'left' : 'right' }}; }
        .note { margin-top: 16px; padding: 10px 14px; background: #fef3c7; border-radius: 8px; color: #92400e; font-size: 11px; }
        .footer { margin-top: 38px; text-align: center; color: #9ca3af; font-size: 10px; }
    </style>
</head>
<body>
<div class="strip"></div>
<div class="page">

    <table class="row">
        <tr>
            <td>
                <div class="merchant">{{ $receipt->merchant['name'] ?? '' }}</div>
                @if(!empty($receipt->merchant['address']))
                    <div class="muted">{{ $receipt->merchant['address'] }}</div>
                @endif
            </td>
            <td style="text-align: {{ $rtl ? 'left' : 'right' }};">
                <div class="title">{{ $title }}</div>
                <div class="pill">{{ $status }}</div>
            </td>
        </tr>
    </table>

    <div class="divider"></div>

    <table class="meta row">
        <tr>
            <td width="50%">
                <div class="label">{{ __('parakit::receipts.reference') }}</div>
                <div class="value">{{ $receipt->reference }}</div>
            </td>
            <td width="50%">
                <div class="label">{{ __('parakit::receipts.transaction_id') }}</div>
                <div class="value">{{ $receipt->gatewayTransactionId ?? $receipt->transactionId }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="label">{{ __('parakit::receipts.gateway') }}</div>
                <div class="value">{{ ucfirst($receipt->gateway) }}</div>
            </td>
            <td>
                <div class="label">{{ $receipt->paidAt ? __('parakit::receipts.paid_at') : __('parakit::receipts.issued') }}</div>
                <div class="value">{{ ($receipt->paidAt ?? $receipt->issuedAt)->format('Y-m-d H:i') }}</div>
            </td>
        </tr>
        @if($receipt->customerName || $receipt->customerEmail)
        <tr>
            <td colspan="2">
                <div class="label">{{ __('parakit::receipts.billed_to') }}</div>
                <div class="value">{{ $receipt->customerName ?? $receipt->customerEmail }}</div>
                @if($receipt->customerName && $receipt->customerEmail)
                    <div class="muted">{{ $receipt->customerEmail }}</div>
                @endif
            </td>
        </tr>
        @endif
    </table>

    <div class="panel">
        @if($refund)
            <table class="line">
                <tr><td class="k">{{ __('parakit::receipts.amount') }}</td>
                    <td class="v">{{ $receipt->amountFormatted }} {{ $receipt->currencySymbol() }}</td></tr>
                <tr><td class="k">{{ __('parakit::receipts.refunded') }}</td>
                    <td class="v">{{ $receipt->refundedFormatted }} {{ $receipt->currencySymbol() }}</td></tr>
            </table>
            <div class="divider" style="margin: 14px 0;"></div>
        @endif
        <table class="line">
            <tr>
                <td class="total-k">{{ $refund ? __('parakit::receipts.total_refunded') : __('parakit::receipts.total_paid') }}</td>
                <td class="total-v">{{ ($refund ? $receipt->refundedFormatted : $receipt->amountFormatted) }} {{ $receipt->currencySymbol() }}</td>
            </tr>
        </table>
    </div>

    @if($receipt->isPartialRefund)
        <div class="note">{{ __('parakit::receipts.partial_refund_note') }}</div>
    @endif

    <div class="footer">
        <div>{{ $refund ? __('parakit::receipts.refund_processed') : __('parakit::receipts.thank_you') }}</div>
        @if(!empty($receipt->merchant['support_email']))
            <div>{{ __('parakit::receipts.support', ['email' => $receipt->merchant['support_email']]) }}</div>
        @endif
    </div>

</div>
</body>
</html>
