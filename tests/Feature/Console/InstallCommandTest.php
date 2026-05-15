<?php
declare(strict_types=1);

it('publishes config and migration tags', function () {
    // Ensure published config is gone before the test so we can detect publish.
    if (file_exists(config_path('parakit.php'))) {
        unlink(config_path('parakit.php'));
    }

    $this->artisan('parakit:install')->assertSuccessful();

    expect(file_exists(config_path('parakit.php')))->toBeTrue();
});
