<?php
declare(strict_types=1);

namespace Gutian\Parakit\Contracts;

use Illuminate\Http\Request;
use Gutian\Parakit\DTOs\PaymentRequest;
use Gutian\Parakit\DTOs\PaymentResponse;
use Gutian\Parakit\DTOs\WebhookPayload;

interface PaymentGateway
{
    public function charge(PaymentRequest $request): PaymentResponse;
    public function handleWebhook(Request $request): WebhookPayload;
    public function name(): string;
}
