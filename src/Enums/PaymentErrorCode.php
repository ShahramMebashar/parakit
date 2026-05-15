<?php
declare(strict_types=1);

namespace Froshly\Parakit\Enums;

enum PaymentErrorCode: string
{
    case InsufficientFunds   = 'insufficient_funds';
    case InvalidPhone        = 'invalid_phone';
    case UserCancelled       = 'user_cancelled';
    case Expired             = 'expired';
    case InvalidAmount       = 'invalid_amount';
    case InvalidCredentials  = 'invalid_credentials';
    case GatewayUnavailable  = 'gateway_unavailable';
    case DuplicateTransaction = 'duplicate_transaction';
    case NetworkError        = 'network_error';
    case Timeout             = 'timeout';
    case SignatureInvalid    = 'signature_invalid';
    case Unknown             = 'unknown';
}
