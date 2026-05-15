<?php
declare(strict_types=1);

it('boots the service provider without error', function () {
    expect(app()->getProviders(\Gutian\Parakit\ParakitServiceProvider::class))->not->toBeEmpty();
});
