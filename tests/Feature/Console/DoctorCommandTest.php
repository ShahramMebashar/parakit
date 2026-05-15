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
