<?php
declare(strict_types=1);

namespace Froshly\Parakit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $gateway
 * @property string $event_id
 * @property string|null $gateway_transaction_id
 * @property string|null $reference
 * @property int|null $amount
 * @property string|null $currency
 * @property string $status
 * @property array<string, mixed> $payload
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PaymentWebhookEvent extends Model
{
    protected $table = 'payment_webhook_events';
    protected $guarded = [];
    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}
