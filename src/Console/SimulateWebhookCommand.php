<?php
declare(strict_types=1);

namespace Froshly\Parakit\Console;

use Firebase\JWT\JWT;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SimulateWebhookCommand extends Command
{
    protected $signature = 'parakit:webhook:simulate
        {gateway}
        {--status=paid}
        {--reference=}
        {--transaction-id=}';

    protected $description = 'Post a test webhook to your local app';

    public function handle(): int
    {
        $gw = (string) $this->argument('gateway');
        $cfg = (array) config("parakit.gateways.{$gw}");
        $path = (string) config('parakit.webhooks.route_prefix', 'payments/webhooks');
        $txnId = (string) $this->option('transaction-id');
        $reference = (string) $this->option('reference');
        $status = (string) $this->option('status');

        $body = match ($gw) {
            'zaincash' => [
                // ZainCash v2 callbacks deliver an HS256 JWT (signed with the
                // merchant api_key) wrapping a STATUS_CHANGED data{} envelope.
                'token' => JWT::encode([
                    'eventType' => 'STATUS_CHANGED',
                    'eventId' => (string) Str::uuid(),
                    'timestamp' => gmdate('c'),
                    'data' => [
                        'transactionId' => $txnId,
                        'orderId' => $reference,
                        'currentStatus' => strtolower($status) === 'paid' ? 'SUCCESS' : strtoupper($status),
                        'amount' => ['currency' => 'IQD', 'value' => 5000],
                    ],
                ], (string) ($cfg['api_key'] ?? ''), 'HS256'),
            ],
            'fib' => [
                'id' => $txnId,
                'status' => strtoupper($status),
            ],
            // NassPay callbacks carry just the orderId; parakit re-fetches the
            // authoritative status via checkStatus, so the body is minimal.
            'nass' => [
                'orderId' => $txnId,
                'responseCode' => '00',
            ],
            // NassWallet POSTs a nested {"data": {...}} envelope and verifies
            // off InitTransactionId. It also appends /callback to the route.
            'nasswallet' => [
                'data' => [
                    'InitTransactionId' => $txnId,
                    'orderId' => $reference,
                    'transactionStatus' => $this->titleStatus($status),
                ],
            ],
            // FastPay IPNs carry order_id; parakit re-fetches via validate.
            'fastpay' => [
                'order_id' => $txnId,
                'status' => $this->titleStatus($status),
            ],
            default => [],
        };

        // NassWallet's gateway appends /callback to the merchant callback URL.
        $suffix = $gw === 'nasswallet' ? '/callback' : '';
        $url = url("/{$path}/{$gw}{$suffix}");

        // NassWallet expects a JSON body (nested envelope); the others post a
        // flat form, matching how each real gateway delivers its callback.
        $client = $gw === 'nasswallet' ? Http::asJson() : Http::asForm();
        $resp = $client->post($url, $body);
        $this->line("HTTP {$resp->status()}: {$resp->body()}");

        return $resp->successful() ? self::SUCCESS : self::FAILURE;
    }

    /** "paid" is the parakit-canonical name; these gateways call it "Success". */
    private function titleStatus(string $s): string
    {
        return strtolower($s) === 'paid' ? 'Success' : ucfirst(strtolower($s));
    }
}
