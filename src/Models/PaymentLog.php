<?php
declare(strict_types=1);

namespace Gutian\Parakit\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentLog extends Model
{
    protected $table = 'payment_logs';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'request' => 'array',
        'response' => 'array',
    ];
}
