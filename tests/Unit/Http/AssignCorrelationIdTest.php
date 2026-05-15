<?php
declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Froshly\Parakit\Http\Middleware\AssignCorrelationId;
use Froshly\Parakit\Support\CorrelationId;

beforeEach(fn () => CorrelationId::reset());

it('honors a well-formed client-supplied X-Correlation-Id', function () {
    $req = Request::create('/test');
    $req->headers->set('X-Correlation-Id', 'good_id-123456789');

    $resp = (new AssignCorrelationId())->handle($req, fn () => new Response('ok'));

    expect($resp->headers->get('X-Correlation-Id'))->toBe('good_id-123456789')
        ->and(app(CorrelationId::CONTEXT_KEY))->toBe('good_id-123456789');
});

it('regenerates when X-Correlation-Id contains forbidden characters (log-injection guard)', function () {
    $req = Request::create('/test');
    $req->headers->set('X-Correlation-Id', "evil\nLog: forged");

    $resp = (new AssignCorrelationId())->handle($req, fn () => new Response('ok'));

    $assigned = $resp->headers->get('X-Correlation-Id');
    expect($assigned)->not->toBe("evil\nLog: forged")
        ->and(strlen($assigned))->toBe(26);
});

it('regenerates when X-Correlation-Id is too short or too long', function () {
    foreach (['short', str_repeat('a', 65)] as $bad) {
        CorrelationId::reset();
        $req = Request::create('/test');
        $req->headers->set('X-Correlation-Id', $bad);
        $resp = (new AssignCorrelationId())->handle($req, fn () => new Response('ok'));
        expect($resp->headers->get('X-Correlation-Id'))->not->toBe($bad);
    }
});

it('terminate() resets the bound correlation id so Octane workers do not leak', function () {
    $req = Request::create('/test');
    $resp = (new AssignCorrelationId())->handle($req, fn () => new Response('ok'));
    expect(app()->bound(CorrelationId::CONTEXT_KEY))->toBeTrue();

    (new AssignCorrelationId())->terminate($req, $resp);
    expect(app()->bound(CorrelationId::CONTEXT_KEY))->toBeFalse();
});
