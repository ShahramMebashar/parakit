<?php
declare(strict_types=1);

it('resolves an error message in English by default', function () {
    expect(trans('parakit::payments.errors.insufficient_funds'))
        ->toBe('Insufficient funds.');
});

it('resolves a status label in Kurdish Sorani', function () {
    app()->setLocale('ckb');
    expect(trans('parakit::payments.statuses.paid'))
        ->not->toBe('parakit::payments.statuses.paid');
});

it('resolves a status label in Arabic', function () {
    app()->setLocale('ar');
    expect(trans('parakit::payments.statuses.paid'))
        ->not->toBe('parakit::payments.statuses.paid');
});
