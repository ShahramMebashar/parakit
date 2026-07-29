<?php
declare(strict_types=1);

namespace Froshly\Parakit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $correlation_id
 * @property string $gateway
 * @property string $action
 * @property string|null $endpoint
 * @property int|null $status_code
 * @property int|null $duration_ms
 * @property array<string, mixed>|null $request
 * @property array<string, mixed>|null $response
 * @property string|null $error_message
 * @property Carbon $created_at
 */
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
