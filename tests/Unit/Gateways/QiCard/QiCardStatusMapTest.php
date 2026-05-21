<?php
declare(strict_types=1);

use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Gateways\QiCard\QiCardStatusMap;

it('maps SUCCESS to Paid', function () {
    expect(QiCardStatusMap::toStatus('SUCCESS'))->toBe(PaymentStatus::Paid);
});

it('maps the three failure statuses to Failed', function () {
    expect(QiCardStatusMap::toStatus('FAILED'))->toBe(PaymentStatus::Failed)
        ->and(QiCardStatusMap::toStatus('ERROR'))->toBe(PaymentStatus::Failed)
        ->and(QiCardStatusMap::toStatus('AUTHENTICATION_FAILED'))->toBe(PaymentStatus::Failed);
});

it('maps EXPIRED to Expired', function () {
    expect(QiCardStatusMap::toStatus('EXPIRED'))->toBe(PaymentStatus::Expired);
});

it('maps every pre-terminal status to Pending', function () {
    $pending = [
        'CREATED', 'FORM_SHOWED', 'INITIALIZED', 'STARTED',
        'THREE_DS_METHOD_CALL_REQUIRED', 'AUTHENTICATION_REQUIRED',
        'AUTHENTICATION_STARTED', 'AUTHENTICATED',
    ];
    foreach ($pending as $s) {
        expect(QiCardStatusMap::toStatus($s))->toBe(PaymentStatus::Pending);
    }
});

it('treats an unknown status as Pending and logs a warning', function () {
    \Illuminate\Support\Facades\Log::shouldReceive('warning')
        ->once()
        ->with('parakit.qicard.unknown_status', ['status' => 'NEWFANGLED_THING']);

    expect(QiCardStatusMap::toStatus('NEWFANGLED_THING'))->toBe(PaymentStatus::Pending);
});

it('maps refund SUCCESS / FAILED / PROCESSING correctly', function () {
    expect(QiCardStatusMap::refundOutcome('SUCCESS'))->toBeTrue()
        ->and(QiCardStatusMap::refundOutcome('FAILED'))->toBeFalse()
        ->and(QiCardStatusMap::refundOutcome('PROCESSING'))->toBeNull();
});
