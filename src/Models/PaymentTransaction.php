<?php
declare(strict_types=1);

namespace Gutian\Parakit\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Gutian\Parakit\Enums\Currency;
use Gutian\Parakit\Enums\PaymentStatus;
use Gutian\Parakit\Exceptions\IllegalStateTransitionException;

class PaymentTransaction extends Model
{
    use HasUlids;

    protected $table = 'payment_transactions';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'status' => PaymentStatus::class,
        'currency' => Currency::class,
        'metadata' => 'array',
        'last_raw_response' => 'array',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    /**
     * Transition the row to a new status via the state machine.
     *
     * Returns true on either a successful save OR an idempotent no-op
     * (same-status transition). Callers should NOT use the return value to
     * decide whether to fire domain events — use {@see wasRecentlyChanged()}
     * or check `wasChanged('status')` instead.
     *
     * @throws IllegalStateTransitionException on illegal transitions
     */
    public function transitionTo(PaymentStatus $next): bool
    {
        if (!$this->status->canTransitionTo($next)) {
            throw new IllegalStateTransitionException(
                "Illegal transition: {$this->status->value} -> {$next->value}"
            );
        }
        if ($this->status === $next) {
            return true;
        }
        $this->status = $next;
        if ($next === PaymentStatus::Paid && $this->paid_at === null) {
            $this->paid_at = now();
        }
        return $this->save();
    }
}
