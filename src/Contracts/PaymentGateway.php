<?php
declare(strict_types=1);

namespace Shah\Parakit\Contracts;

use Illuminate\Http\Request;
use Shah\Parakit\DTOs\PaymentRequest;
use Shah\Parakit\DTOs\PaymentResponse;
use Shah\Parakit\DTOs\WebhookPayload;

interface PaymentGateway
{
    public function charge(PaymentRequest $request): PaymentResponse;
    public function handleWebhook(Request $request): WebhookPayload;
    public function name(): string;
}
