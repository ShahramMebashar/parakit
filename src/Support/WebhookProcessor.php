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
        // Both-sided: a future occurredAt (forged or clock-skewed) is out of
        // tolerance just like a stale one.
        $skewSeconds = abs((new DateTimeImmutable())->getTimestamp() - $payload->occurredAt->getTimestamp());
        return $skewSeconds > $toleranceSeconds;
    }

    /**
     * Atomically records the event and applies it. A duplicate whose first arrival was
     * parked (no local tx yet) self-heals by re-running applyToTransaction on retry.
     *
     * @throws DuplicateWebhookException
     */
    public function process(WebhookPayload $payload): PaymentWebhookEvent
    {
        try {
            return DB::transaction(function () use ($payload) {
                $eventRow = $this->insertEvent($payload);
                $this->applyToTransaction($payload, $eventRow);
                return $eventRow;
            });
        } catch (DuplicateWebhookException $e) {
            $existing = PaymentWebhookEvent::query()
                ->where('gateway', $payload->gateway)
                ->where('event_id', $payload->eventId)
                ->first();
            if ($existing !== null && $existing->processed_at === null) {
                $this->applyToTransaction($payload, $existing);
                return $existing->refresh();
            }
            throw $e;
        }
    }

    /** @throws DuplicateWebhookException */
    public function recordEvent(WebhookPayload $payload): PaymentWebhookEvent
    {
        return $this->insertEvent($payload);
    }

    /** @throws DuplicateWebhookException */
    private function insertEvent(WebhookPayload $payload): PaymentWebhookEvent
    {
        try {
            return PaymentWebhookEvent::create([
                'gateway' => $payload->gateway,
                'event_id' => $payload->eventId,
                'gateway_transaction_id' => $payload->gatewayTransactionId !== '' ? $payload->gatewayTransactionId : null,
                'reference' => $payload->reference !== '' ? $payload->reference : null,
                'amount' => $payload->amount,
                'currency' => $payload->currency->value,
                'status' => $payload->status->value,
                'payload' => $payload->raw,
            ]);
        } catch (QueryException $e) {
            // SQLSTATE class 23 = integrity constraint violation; anything else must surface (a
            // masked DB error would 200 the gateway and drop the event).
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
            /** @var PaymentWebhookEvent $eventRow */
            $eventRow = PaymentWebhookEvent::query()
                ->whereKey($eventRow->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($eventRow->processed_at !== null) {
                return;
            }

            $tx = PaymentTransaction::query()
                ->where('gateway', $p->gateway)
                ->where(function ($q) use ($p) {
                    $q->where('gateway_transaction_id', $p->gatewayTransactionId)
                      ->orWhere('reference', $p->reference);
                })
                ->lockForUpdate()
                ->first();

            if ($tx === null) {
                // Webhook beat the local row commit. Leave processed_at NULL so the replay
                // sweeper retries once the tx lands; marking processed here is silent data loss.
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

            // Paid + amount != tx.amount is a mismatch, INCLUDING amount=0 (a buggy gateway
            // reporting `Paid, amount: 0` would otherwise silently settle for the full charge).
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

            // transitionTo() already saved if status moved — capture here, before
            // the refunded_amount save clears it.
            $statusChanged = $tx->wasChanged('status');

            // Partial refund webhooks carry the delta, not a running total.
            if ($p->status === PaymentStatus::Refunded) {
                $tx->refunded_amount = (int) $tx->amount;
            } elseif ($p->status === PaymentStatus::PartiallyRefunded && $p->amount > 0) {
                $next = (int) $tx->refunded_amount + $p->amount;
                $charge = (int) $tx->amount;
                if ($next > $charge) {
                    Log::warning('parakit.webhook.refund_overflow', [
                        'gateway' => $p->gateway,
                        'tx' => $tx->id,
                        'event_id' => $eventRow->event_id,
                        'charge_amount' => $charge,
                        'refunded_so_far' => (int) $tx->refunded_amount,
                        'delta' => $p->amount,
                    ]);
                    $next = $charge;
                }
                $tx->refunded_amount = $next;
            }

            $refundedAmountChanged = $tx->isDirty('refunded_amount');

            $tx->save();
            $eventRow->update(['processed_at' => now()]);

            if ($statusChanged) {
                $this->fireEventFor($p->status, $tx);
            } elseif ($refundedAmountChanged && in_array($p->status, [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded], true)) {
                // A second PartiallyRefunded webhook updates the amount without
                // moving status — bookkeeping listeners still need to hear it.
                event(new PaymentRefunded($tx));
            }
        });
    }

    /** Paid only — Refunded/PartiallyRefunded webhooks carry the refund amount, not the charge. */
    private function isAmountMismatch(WebhookPayload $p, PaymentTransaction $tx): bool
    {
        return $p->status === PaymentStatus::Paid
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
