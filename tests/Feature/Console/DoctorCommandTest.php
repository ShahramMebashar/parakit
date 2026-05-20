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

it('reports OK when all checks pass', function () {
    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
    ]);
    $this->artisan('parakit:doctor --gateway=fib')->assertSuccessful();
});

it('exits non-zero when required config is missing', function () {
    config()->set('parakit.gateways.fib.client_secret', null);
    $this->artisan('parakit:doctor --gateway=fib')->assertFailed();
});

it('reports OK for a fully-configured ZainCash gateway', function () {
    config()->set('parakit.gateways.zaincash', [
        'driver' => 'zaincash',
        'base_url' => 'https://pg-api-uat.zaincash.iq',
        'client_id' => 'cid',
        'client_secret' => 'csecret',
        'api_key' => 'shared-secret-shared-secret-1234',
    ]);
    $this->artisan('parakit:doctor --gateway=zaincash')->assertSuccessful();
});

it('exits non-zero when a ZainCash credential is missing', function () {
    config()->set('parakit.gateways.zaincash', [
        'driver' => 'zaincash',
        'base_url' => 'https://pg-api-uat.zaincash.iq',
        'client_id' => 'cid',
        'client_secret' => 'csecret',
        // api_key intentionally omitted
    ]);
    $this->artisan('parakit:doctor --gateway=zaincash')->assertFailed();
});
