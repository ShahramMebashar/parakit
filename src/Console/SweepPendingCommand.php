<?php
declare(strict_types=1);

namespace Shah\Parakit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Shah\Parakit\Contracts\SupportsStatusCheck;
use Shah\Parakit\Enums\PaymentStatus;
use Shah\Parakit\Events\PaymentCancelled;
use Shah\Parakit\Events\PaymentFailed;
use Shah\Parakit\Events\PaymentSucceeded;
use Shah\Parakit\Exceptions\IllegalStateTransitionException;
use Shah\Parakit\Models\PaymentTransaction;
use Shah\Parakit\PaymentManager;

class SweepPendingCommand extends Command
{
    protected $signature = 'parakit:sweep-pending {--gateway=} {--older-than=}';
    protected $description = 'Poll status for pending transactions to recover lost webhooks';

    public function handle(PaymentManager $manager): int
    {
        $olderThanMin = (int) ($this->option('older-than')
            ?? config('parakit.sweeper.older_than_minutes', 5));
        $maxAgeHours = (int) config('parakit.sweeper.max_age_hours', 24);

        $query = PaymentTransaction::query()
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Processing->value])
            ->where('updated_at', '<=', now()->subMinutes($olderThanMin))
            ->where('created_at', '>=', now()->subHours($maxAgeHours))
            ->whereNotNull('gateway_transaction_id');

        if ($gateway = $this->option('gateway')) {
            $query->where('gateway', $gateway);
        }

        $count = 0;
        $query->cursor()->each(function (PaymentTransaction $stale) use ($manager, &$count) {
            if ($this->reconcileOne($manager, $stale)) {
                $count++;
            }
        });

        $this->info("Sweeper: {$count} transactions updated.");
        return self::SUCCESS;
    }

    /**
     * Reconcile a single transaction inside its own DB transaction with
     * row-level locking so we never race with WebhookProcessor (which uses
     * the same lockForUpdate pattern). Returns true iff the row's status
     * actually changed.
     */
    private function reconcileOne(PaymentManager $manager, PaymentTransaction $stale): bool
    {
        try {
            $driver = $manager->driver($stale->gateway);
        } catch (\Throwable $e) {
            $this->warn("skip {$stale->id}: {$e->getMessage()}");
            return false;
        }
        if (!$driver instanceof SupportsStatusCheck) {
            return false;
        }

        try {
            $remote = $driver->status($stale->gateway_transaction_id);
        } catch (\Throwable $e) {
            $this->warn("skip {$stale->id}: {$e->getMessage()}");
            return false;
        }

        return (bool) DB::transaction(function () use ($stale, $remote) {
            // Re-read under lock so we never act on stale Eloquent state
            // (a webhook may have updated this row between the cursor read
            // and now).
            $tx = PaymentTransaction::query()
                ->whereKey($stale->id)
                ->lockForUpdate()
                ->first();

            if ($tx === null) {
                return false;
            }

            try {
                $tx->transitionTo($remote->status);
            } catch (IllegalStateTransitionException) {
                return false;
            }

            if (!$tx->wasChanged('status')) {
                return false;
            }

            $this->fire($remote->status, $tx);
            return true;
        });
    }

    private function fire(PaymentStatus $s, PaymentTransaction $tx): void
    {
        match ($s) {
            PaymentStatus::Paid => event(new PaymentSucceeded($tx)),
            PaymentStatus::Failed => event(new PaymentFailed($tx)),
            PaymentStatus::Cancelled, PaymentStatus::Expired => event(new PaymentCancelled($tx)),
            default => null,
        };
    }
}
