<?php
declare(strict_types=1);

namespace Gutian\Parakit;

use Illuminate\Contracts\Events\Dispatcher;
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
                \Gutian\Parakit\Console\InstallCommand::class,
                \Gutian\Parakit\Console\DoctorCommand::class,
                \Gutian\Parakit\Console\SweepPendingCommand::class,
                \Gutian\Parakit\Console\TestChargeCommand::class,
                \Gutian\Parakit\Console\SimulateWebhookCommand::class,
                \Gutian\Parakit\Console\PruneLogsCommand::class,
            ]);
        }

        $this->registerOctaneFlusher();

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

    private function registerOctaneFlusher(): void
    {
        $this->callAfterResolving('events', function (Dispatcher $events) {
            // Flush resolved gateway instances after every HTTP request.
            // Under plain FPM this is a harmless no-op; under Octane it prevents
            // stale tenant credentials from leaking into the next request on the
            // same worker.
            $events->listen(
                \Illuminate\Foundation\Http\Events\RequestHandled::class,
                fn () => $this->app->make(PaymentManager::class)->flushResolved()
            );

            // Belt-and-suspenders: Octane fires RequestTerminated after its own
            // sandbox reset. Listening to both ensures the flush fires regardless
            // of which Octane server (Swoole / RoadRunner) is used.
            if (isset($_SERVER['LARAVEL_OCTANE'])
                && class_exists(\Laravel\Octane\Events\RequestTerminated::class)
            ) {
                $events->listen(
                    \Laravel\Octane\Events\RequestTerminated::class,
                    fn () => $this->app->make(PaymentManager::class)->flushResolved()
                );
            }
        });
    }
}
