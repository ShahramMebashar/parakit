<?php
declare(strict_types=1);

namespace Froshly\Parakit\Contracts;

use Illuminate\Http\Request;
use Froshly\Parakit\DTOs\PaymentRequest;
use Froshly\Parakit\DTOs\PaymentResponse;
use Froshly\Parakit\DTOs\WebhookPayload;

interface PaymentGateway
{
    public function charge(PaymentRequest $request): PaymentResponse;
    public function handleWebhook(Request $request): WebhookPayload;
    public function name(): string;
}
