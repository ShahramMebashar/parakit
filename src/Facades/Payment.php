<?php
declare(strict_types=1);

namespace Froshly\Parakit\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Froshly\Parakit\Contracts\PaymentGateway driver(?string $name = null)
 * @method static void extend(string $driver, \Closure $creator)
 * @method static void resolveMerchantUsing(\Closure $resolver)
 * @method static \Froshly\Parakit\PaymentBuilder for(object|string $reference, string $keyAttribute = 'id')
 */
class Payment extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'parakit.manager';
    }
}
