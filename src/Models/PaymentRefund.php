<?php
declare(strict_types=1);

namespace Froshly\Parakit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $gateway
 * @property string $idempotency_key
 * @property string $gateway_transaction_id
 * @property int $amount
 * @property string $status
 * @property string|null $gateway_refund_id
 * @property int $refunded_amount
 * @property array<string, mixed>|null $response
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PaymentRefund extends Model
{
    protected $table = 'payment_refunds';

    protected $guarded = [];

    protected $casts = [
        'response' => 'array',
        'completed_at' => 'datetime',
    ];
}
