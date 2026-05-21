<?php
declare(strict_types=1);

namespace Froshly\Parakit\Console;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Froshly\Parakit\DTOs\WebhookPayload;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Models\PaymentWebhookEvent;
use Froshly\Parakit\Support\WebhookProcessor;

/**
 * Re-apply webhook events that were recorded but never processed.
 *
 * The most common cause is a webhook arriving before the local transaction
 * row was committed (FIB's QR flow can complete in <1s). WebhookProcessor
 * intentionally leaves processed_at=null on those rows so the event is not
 * dropped. This command picks them up later, once the transaction has landed,
 * and re-runs applyToTransaction.
 *
 * Rows from before v0.9.1 are skipped — they lack the denormalised lookup
 * columns the replay path needs.
 */
class WebhookReplayCommand extends Command
{
    protected $signature = 'parakit:webhooks:replay {--older-than=5} {--limit=200}';
    protected $description = 'Re-apply webhook events that landed before the local transaction';

    public function handle(WebhookProcessor $processor): int
    {
        $olderThanMin = max(0, (int) $this->option('older-than'));
        $limit = max(1, (int) $this->option('limit'));

        $rows = PaymentWebhookEvent::query()
            ->whereNull('processed_at')
            ->where('created_at', '<=', now()->subMinutes($olderThanMin))
            ->whereNotNull('gateway_transaction_id')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $applied = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $payload = $this->reconstruct($row);
            if ($payload === null) {
                $skipped++;
                continue;
            }
            try {
                $processor->applyToTransaction($payload, $row);
                if ($row->fresh()->processed_at !== null) {
                    $applied++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $this->warn("skip {$row->id}: {$e->getMessage()}");
                $skipped++;
            }
        }

        $this->info("Replay: {$applied} applied, {$skipped} skipped.");
        return self::SUCCESS;
    }

    /**
     * Best-effort WebhookPayload reconstruction from the denormalised event
     * row. Returns null when the row pre-dates v0.9.1 (no lookup columns) or
     * carries an unknown enum value.
     */
    private function reconstruct(PaymentWebhookEvent $row): ?WebhookPayload
    {
        $status = PaymentStatus::tryFrom((string) $row->status);
        $currency = $row->currency !== null ? Currency::tryFrom($row->currency) : Currency::IQD;
        if ($status === null || $currency === null) {
            return null;
        }

        return new WebhookPayload(
            gateway: (string) $row->gateway,
            gatewayTransactionId: (string) $row->gateway_transaction_id,
            reference: (string) ($row->reference ?? ''),
            status: $status,
            amount: (int) ($row->amount ?? 0),
            currency: $currency,
            eventId: (string) $row->event_id,
            occurredAt: $row->created_at?->toDateTimeImmutable() ?? new DateTimeImmutable(),
            raw: $row->payload ?? [],
        );
    }
}
