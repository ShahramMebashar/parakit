<?php
declare(strict_types=1);

namespace Froshly\Parakit\Enums;

enum Gateway: string
{
    case Fib        = 'fib';
    case ZainCash   = 'zaincash';
    case FastPay    = 'fastpay';
    case NassPay    = 'nasspay';
    case NassWallet = 'nasswallet';
}
