<?php
declare(strict_types=1);

namespace Froshly\Parakit\Support;

use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Froshly\Parakit\DTOs\WebhookPayload;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Events\PaymentCancelled;
use Froshly\Parakit\Events\PaymentFailed;
use Froshly\Parakit\Events\PaymentRefunded;
use Froshly\Parakit\Events\PaymentSucceeded;
use Froshly\Parakit\Exceptions\DuplicateWebhookException;
use Froshly\Parakit\Exceptions\IllegalStateTransitionException;
use Froshly\Parakit\Models\PaymentTransaction;
use Froshly\Parakit\Models\PaymentWebhookEvent;

final class WebhookProcessor
{
    public function isReplay(WebhookPayload $payload, int $toleranceSeconds): bool
    {
        return (new DateTimeImmutable())->getTimestamp() - $payload->occurredAt->getTimestamp() > $toleranceSeconds;
    }

    /** @throws DuplicateWebhookException */
    public function recordEvent(WebhookPayload $payload): PaymentWebhookEvent
    {
        try {
            return PaymentWebhookEvent::create([
                'gateway' => $payload->gateway,
                'event_id' => $payload->eventId,
                'status' => $payload->status->value,
                'payload' => $payload->raw,
            ]);
        } catch (QueryException $e) {
            // SQLSTATE class 23 = integrity constraint violation
            // (23000 MySQL/SQLite, 23505 Postgres). Anything else is a real
            // DB error and must surface — do not silently mask it as a
            // "duplicate" or the caller will return 200 to the gateway and
            // drop the event.
            if (!str_starts_with((string) $e->getCode(), '23')) {
                throw $e;
            }
            throw new DuplicateWebhookException(
                "Duplicate event: {$payload->gateway}/{$payload->eventId}",
                0,
                $e,
            );
        }
    }

    public function applyToTransaction(WebhookPayload $p, PaymentWebhookEvent $eventRow): void
    {
        DB::transaction(function () use ($p, $eventRow) {
            $tx = PaymentTransaction::query()
                ->where('gateway', $p->gateway)
                ->where(function ($q) use ($p) {
                    $q->where('gateway_transaction_id', $p->gatewayTransactionId)
                      ->orWhere('reference', $p->reference);
                })
                ->lockForUpdate()
                ->first();

            if ($tx === null) {
                // Webhook arrived before charge() committed the local row
                // (FIB's QR flow can complete in <1s). Leave processed_at
                // NULL so a later sweeper / reconciliation pass can replay
                // this event once the transaction lands. Do NOT mark it
                // processed — that's silent data loss.
                Log::warning('parakit.webhook.no_local_tx', [
                    'gateway' => $p->gateway,
                    'reference' => $p->reference,
                    'gateway_transaction_id' => $p->gatewayTransactionId,
                    'event_id' => $eventRow->event_id,
                ]);
                return;
            }

            if ($tx->gateway_transaction_id === null) {
                $tx->gateway_transaction_id = $p->gatewayTransactionId;
            }
            $tx->last_raw_response = $p->raw;

            // Amount integrity: a Paid webhook must settle the amount we
            // charged. A driver that re-verifies via status-check may report
            // amount 0 (no amount field) — that is "not reported", not a
            // mismatch. on_amount_mismatch controls the action: 'log' (default)
            // records a warning and proceeds; 'reject' refuses the transition.
            if ($this->isAmountMismatch($p, $tx)) {
                Log::warning('parakit.webhook.amount_mismatch', [
                    'gateway' => $p->gateway,
                    'tx' => $tx->id,
                    'event_id' => $eventRow->event_id,
                    'expected_amount' => (int) $tx->amount,
                    'webhook_amount' => $p->amount,
                ]);

                if (config('parakit.webhooks.on_amount_mismatch', 'log') === 'reject') {
                    $tx->save();
                    $eventRow->update(['processed_at' => now()]);
                    return;
                }
            }

            try {
                $tx->transitionTo($p->status);
            } catch (IllegalStateTransitionException) {
                Log::info('parakit.webhook.illegal_transition', [
                    'from' => $tx->status->value,
                    'to' => $p->status->value,
                    'tx' => $tx->id,
                ]);
                $tx->save();
                $eventRow->update(['processed_at' => now()]);
                return;
            }

            $tx->save();
            $eventRow->update(['processed_at' => now()]);

            // transitionTo() returns true even for idempotent no-ops, so we
            // rely on Eloquent's wasChanged() to discriminate.
            if ($tx->wasChanged('status')) {
                $this->fireEventFor($p->status, $tx);
            }
        });
    }

    /**
     * True when a Paid webhook carries a non-zero amount that disagrees with
     * the stored charge. Restricted to Paid: a Refunded/PartiallyRefunded
     * webhook legitimately carries the refund amount, not the charge amount.
     */
    private function isAmountMismatch(WebhookPayload $p, PaymentTransaction $tx): bool
    {
        return $p->status === PaymentStatus::Paid
            && $p->amount !== 0
            && $p->amount !== (int) $tx->amount;
    }

    private function fireEventFor(PaymentStatus $status, PaymentTransaction $tx): void
    {
        match ($status) {
            PaymentStatus::Paid => event(new PaymentSucceeded($tx)),
            PaymentStatus::Failed => event(new PaymentFailed($tx)),
            PaymentStatus::Cancelled, PaymentStatus::Expired => event(new PaymentCancelled($tx)),
            PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded => event(new PaymentRefunded($tx)),
            default => null,
        };
    }
}
