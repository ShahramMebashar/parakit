<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Facades\Payment;

beforeEach(function () {
    Cache::flush();
    $this->artisan('migrate');
    config()->set('parakit.gateways.qicard', [
        'driver'      => 'qicard',
        'base_url'    => 'https://uat-sandbox-3ds-api.qi.iq',
        'username'    => 'u',
        'password'    => 'p',
        'terminal_id' => '237984',
    ]);
});

it('reports SUCCESS as Paid with the confirmed amount', function () {
    Http::fake([
        '*/api/v1/payment/*/status' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/QiCard/status_success.json'), true),
            200,
        ),
    ]);

    $r = Payment::driver('qicard')->status('f2bb43a8-488a-4281-977b-5b3418fc3c67');

    expect($r->status)->toBe(PaymentStatus::Paid)
        ->and($r->amount)->toBe(5000)
        ->and($r->success)->toBeTrue();
});

it('reports FAILED as PaymentStatus::Failed', function () {
    Http::fake([
        '*/api/v1/payment/*/status' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/QiCard/status_failed.json'), true),
            200,
        ),
    ]);

    $r = Payment::driver('qicard')->status('f2bb43a8-488a-4281-977b-5b3418fc3c67');

    expect($r->status)->toBe(PaymentStatus::Failed)
        ->and($r->success)->toBeFalse();
});

it('reports CREATED as Pending', function () {
    Http::fake([
        '*/api/v1/payment/*/status' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/QiCard/create_payment_success.json'), true),
            200,
        ),
    ]);

    $r = Payment::driver('qicard')->status('f2bb43a8-488a-4281-977b-5b3418fc3c67');

    expect($r->status)->toBe(PaymentStatus::Pending)
        ->and($r->success)->toBeTrue();
});
