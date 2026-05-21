<?php
declare(strict_types=1);

namespace Froshly\Parakit\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentRefund extends Model
{
    protected $table = 'payment_refunds';

    protected $guarded = [];

    protected $casts = [
        'response' => 'array',
        'completed_at' => 'datetime',
    ];
}
