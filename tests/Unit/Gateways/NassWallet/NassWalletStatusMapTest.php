<?php
declare(strict_types=1);

use Froshly\Parakit\Gateways\NassWallet\NassWalletStatusMap;
use Froshly\Parakit\Enums\PaymentStatus;

it('maps a Success transaction status to Paid', function () {
    expect(NassWalletStatusMap::toStatus('Success'))->toBe(PaymentStatus::Paid)
        ->and(NassWalletStatusMap::toStatus('SUCCESS'))->toBe(PaymentStatus::Paid)
        ->and(NassWalletStatusMap::toStatus('success'))->toBe(PaymentStatus::Paid);
});

it('maps a Failed transaction status to Failed', function () {
    expect(NassWalletStatusMap::toStatus('Failed'))->toBe(PaymentStatus::Failed)
        ->and(NassWalletStatusMap::toStatus('FAILED'))->toBe(PaymentStatus::Failed);
});

it('falls back to Pending for an unrecognised transaction status', function () {
    expect(NassWalletStatusMap::toStatus(''))->toBe(PaymentStatus::Pending)
        ->and(NassWalletStatusMap::toStatus('Whatever'))->toBe(PaymentStatus::Pending);
});
