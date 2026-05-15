<?php
declare(strict_types=1);

namespace Froshly\Parakit\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'parakit:install {--force : Overwrite an existing published config file}';
    protected $description = 'Publish parakit config + migrations and run migrate';

    public function handle(): int
    {
        // Config publish: never force by default, so re-running install does
        // not clobber operator edits to config/parakit.php. Operators can
        // opt in with --force.
        $this->call('vendor:publish', [
            '--tag' => 'parakit-config',
            '--force' => (bool) $this->option('force'),
        ]);

        // Migrations are timestamped and additive — forced republish is safe
        // and matches typical Laravel package-install UX.
        $this->call('vendor:publish', [
            '--tag' => 'parakit-migrations',
            '--force' => true,
        ]);

        // Auto-confirm migrations in production so non-interactive deploys
        // don't hang on Laravel's confirmation prompt.
        $this->call('migrate', [
            '--force' => app()->environment('production'),
        ]);

        $this->info('parakit installed.');
        return self::SUCCESS;
    }
}
