<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    config()->set('parakit.gateways.fib', [
        'driver' => 'fib',
        'base_url' => 'https://fib.stage.fib.iq',
        'client_id' => 'cid', 'client_secret' => 'csecret',
        'currency' => 'IQD',
        'callback_url' => 'https://app.test/cb',
    ]);
});

it('charges via the named driver and prints the transaction id', function () {
    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        '*/protected/v1/payments' => Http::response(['paymentId' => 'pid_99'], 200),
    ]);

    $this->artisan('parakit:test-charge', ['gateway' => 'fib', '--amount' => 1000])
        ->expectsOutputToContain('pid_99')
        ->assertSuccessful();
});
