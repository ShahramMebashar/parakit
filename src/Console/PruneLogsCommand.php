<?php
declare(strict_types=1);

namespace Shah\Parakit\Console;

use Illuminate\Console\Command;
use Shah\Parakit\Models\PaymentLog;

class PruneLogsCommand extends Command
{
    protected $signature = 'parakit:logs:prune {--days=}';
    protected $description = 'Delete payment_logs rows older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('parakit.logging.retention_days', 90));
        $cut = now()->subDays($days);
        $n = PaymentLog::where('created_at', '<', $cut)->delete();
        $this->info("Pruned {$n} payment_logs older than {$days} days.");
        return self::SUCCESS;
    }
}
