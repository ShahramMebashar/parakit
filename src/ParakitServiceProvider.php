<?php
declare(strict_types=1);

namespace Shah\Parakit;

use Illuminate\Support\ServiceProvider;

class ParakitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/parakit.php', 'parakit');

        $this->app->singleton(PaymentManager::class, fn ($app) => new PaymentManager($app));
        $this->app->alias(PaymentManager::class, 'parakit.manager');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/webhooks.php');
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'parakit');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/parakit.php' => config_path('parakit.php'),
            ], 'parakit-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'parakit-migrations');

            $this->publishes([
                __DIR__ . '/../resources/lang' => $this->app->langPath('vendor/parakit'),
            ], 'parakit-lang');

            $this->commands([
                \Shah\Parakit\Console\InstallCommand::class,
                \Shah\Parakit\Console\DoctorCommand::class,
                \Shah\Parakit\Console\SweepPendingCommand::class,
                \Shah\Parakit\Console\TestChargeCommand::class,
                \Shah\Parakit\Console\SimulateWebhookCommand::class,
                \Shah\Parakit\Console\PruneLogsCommand::class,
            ]);
        }

        $this->app->booted(function () {
            /** @var \Illuminate\Console\Scheduling\Schedule $schedule */
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

            if (config('parakit.sweeper.enabled', true)) {
                $schedule->command('parakit:sweep-pending')
                    ->everyFiveMinutes()
                    ->withoutOverlapping();
            }

            $schedule->command('parakit:logs:prune')
                ->daily()
                ->withoutOverlapping();
        });
    }
}
