<?php
declare(strict_types=1);

namespace Froshly\Parakit\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentWebhookEvent extends Model
{
    protected $table = 'payment_webhook_events';
    protected $guarded = [];
    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}
