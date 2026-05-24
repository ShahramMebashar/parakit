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

it('warns when on_amount_mismatch is set to an invalid policy', function () {
    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
    ]);
    config()->set('parakit.webhooks.on_amount_mismatch', 'rejcet');

    $this->artisan('parakit:doctor --gateway=fib')
        ->expectsOutputToContain('on_amount_mismatch')
        ->assertSuccessful();
});

it('does not warn when on_amount_mismatch is a valid policy', function () {
    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
    ]);
    config()->set('parakit.webhooks.on_amount_mismatch', 'reject');

    $this->artisan('parakit:doctor --gateway=fib')
        ->doesntExpectOutputToContain('on_amount_mismatch')
        ->assertSuccessful();
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

it('validates required config for Nass Pay', function () {
    config()->set('parakit.gateways.nass', [
        'driver' => 'nass',
        'base_url' => 'https://uat-gateway.nass.iq:9746',
        'username' => 'merchant',
        'password' => 'secret',
        'callback_url' => 'https://app.test/payments/webhooks/nass',
    ]);
    $this->artisan('parakit:doctor --gateway=nass')->assertSuccessful();

    config()->set('parakit.gateways.nass.password', null);
    $this->artisan('parakit:doctor --gateway=nass')->assertFailed();
});

it('validates required config for NassWallet, including the Basic token', function () {
    config()->set('parakit.gateways.nasswallet', [
        'driver' => 'nasswallet',
        'base_url' => 'https://uatgw1.nasswallet.com/payment/transaction',
        'portal_url' => 'https://uatcheckout1.nasswallet.com',
        'basic_token' => 'BASIC_TOKEN',
        'username' => 'merchant',
        'password' => 'secret',
        'transaction_pin' => '1234',
        'callback_url' => 'https://app.test/payments/webhooks/nasswallet',
    ]);
    $this->artisan('parakit:doctor --gateway=nasswallet')->assertSuccessful();

    config()->set('parakit.gateways.nasswallet.basic_token', null);
    $this->artisan('parakit:doctor --gateway=nasswallet')->assertFailed();
});

it('validates required config for FastPay', function () {
    config()->set('parakit.gateways.fastpay', [
        'driver' => 'fastpay',
        'base_url' => 'https://staging-pgw.fast-pay.iq',
        'store_id' => 'STORE-1',
        'store_password' => 'secret',
        'callback_url' => 'https://app.test/payments/webhooks/fastpay',
    ]);
    $this->artisan('parakit:doctor --gateway=fastpay')->assertSuccessful();

    config()->set('parakit.gateways.fastpay.store_password', '');
    $this->artisan('parakit:doctor --gateway=fastpay')->assertFailed();
});

it('warns (but still succeeds) when QiCard public_key is unset', function () {
    config()->set('parakit.gateways.qicard', [
        'driver'      => 'qicard',
        'base_url'    => 'https://uat-sandbox-3ds-api.qi.iq',
        'username'    => 'u', 'password' => 'p', 'terminal_id' => '237984',
        // public_key intentionally omitted — fallback mode is allowed but
        // should be flagged.
    ]);

    $this->artisan('parakit:doctor --gateway=qicard')
        ->expectsOutputToContain('public_key not set')
        ->assertSuccessful();
});

it('does not warn when QiCard public_key is configured', function () {
    config()->set('parakit.gateways.qicard', [
        'driver'      => 'qicard',
        'base_url'    => 'https://uat-sandbox-3ds-api.qi.iq',
        'username'    => 'u', 'password' => 'p', 'terminal_id' => '237984',
        'public_key'  => '-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----',
    ]);

    $this->artisan('parakit:doctor --gateway=qicard')
        ->doesntExpectOutputToContain('public_key not set')
        ->assertSuccessful();
});
