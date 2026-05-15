<?php
declare(strict_types=1);

namespace Gutian\Parakit\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Gutian\Parakit\Contracts\PaymentGateway driver(?string $name = null)
 * @method static void extend(string $driver, \Closure $creator)
 * @method static void resolveMerchantUsing(\Closure $resolver)
 * @method static \Gutian\Parakit\PaymentBuilder for(object|string $reference, string $keyAttribute = 'id')
 */
class Payment extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'parakit.manager';
    }
}
