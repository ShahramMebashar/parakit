<?php
declare(strict_types=1);

namespace Froshly\Parakit;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

class ParakitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/parakit.php', 'parakit');

        $this->app->singleton(PaymentManager::class, fn ($app) => new PaymentManager($app));
        $this->app->alias(PaymentManager::class, 'parakit.manager');

        $this->app->singleton(
            \Froshly\Parakit\Receipts\PdfRenderer::class,
            fn () => new \Froshly\Parakit\Receipts\PdfRenderer(config('parakit.receipts.pdf', [])),
        );

        $this->app->singleton(
            \Froshly\Parakit\Receipts\ReceiptManager::class,
            fn ($app) => new \Froshly\Parakit\Receipts\ReceiptManager($app),
        );
        $this->app->alias(\Froshly\Parakit\Receipts\ReceiptManager::class, 'parakit.receipts');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/webhooks.php');
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'parakit');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'parakit');

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

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/parakit'),
            ], 'parakit-views');

            $this->commands([
                \Froshly\Parakit\Console\InstallCommand::class,
                \Froshly\Parakit\Console\DoctorCommand::class,
                \Froshly\Parakit\Console\SweepPendingCommand::class,
                \Froshly\Parakit\Console\TestChargeCommand::class,
                \Froshly\Parakit\Console\SimulateWebhookCommand::class,
                \Froshly\Parakit\Console\WebhookReplayCommand::class,
                \Froshly\Parakit\Console\PruneLogsCommand::class,
                \Froshly\Parakit\Console\PreviewReceiptCommand::class,
            ]);
        }

        $this->registerOctaneFlusher();

        $this->app->booted(function () {
            /** @var \Illuminate\Console\Scheduling\Schedule $schedule */
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

            if (config('parakit.sweeper.enabled', true)) {
                $schedule->command('parakit:transactions:sweep-pending')
                    ->everyFiveMinutes()
                    ->withoutOverlapping();
            }

            if (config('parakit.webhooks.replay.enabled', true)) {
                $older = (int) config('parakit.webhooks.replay.older_than_minutes', 5);
                $schedule->command('parakit:webhooks:replay', ['--older-than' => $older])
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
            // Prevents stale tenant credentials leaking across requests under Octane.
            $events->listen(
                \Illuminate\Foundation\Http\Events\RequestHandled::class,
                fn () => $this->app->make(PaymentManager::class)->flushResolved()
            );

            // Octane fires RequestTerminated after its sandbox reset; listen to both.
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
