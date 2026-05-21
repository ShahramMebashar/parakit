<?php
declare(strict_types=1);

it('boots the service provider without error', function () {
    expect(app()->getProviders(\Froshly\Parakit\ParakitServiceProvider::class))->not->toBeEmpty();
});

it('schedules parakit:webhooks:replay when enabled', function () {
    $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
    $commands = collect($schedule->events())->map(fn ($e) => $e->command)->all();
    $haystack = implode("\n", $commands);
    expect($haystack)->toContain('parakit:webhooks:replay');
});
