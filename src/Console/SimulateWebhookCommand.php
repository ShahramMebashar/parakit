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

    protected $description = 'Post a correctly-signed test webhook to your local app';

    public function handle(): int
    {
        $gw = (string) $this->argument('gateway');
        $cfg = (array) config("parakit.gateways.{$gw}");
        $path = (string) config('parakit.webhooks.route_prefix', 'payments/webhooks');
        $url = url("/{$path}/{$gw}");

        $body = match ($gw) {
            'zaincash' => [
                // ZainCash v2 callbacks deliver an HS256 JWT (signed with the
                // merchant api_key) wrapping a STATUS_CHANGED data{} envelope.
                'token' => JWT::encode([
                    'eventType' => 'STATUS_CHANGED',
                    'eventId' => (string) Str::uuid(),
                    'timestamp' => gmdate('c'),
                    'data' => [
                        'transactionId' => (string) $this->option('transaction-id'),
                        'orderId' => (string) $this->option('reference'),
                        'currentStatus' => $this->mapStatus((string) $this->option('status')),
                        'amount' => ['currency' => 'IQD', 'value' => 5000],
                    ],
                ], (string) ($cfg['api_key'] ?? ''), 'HS256'),
            ],
            'fib' => [
                'id' => (string) $this->option('transaction-id'),
                'status' => strtoupper((string) $this->option('status')),
            ],
            default => [],
        };

        $resp = Http::asForm()->post($url, $body);
        $this->line("HTTP {$resp->status()}: {$resp->body()}");

        return $resp->successful() ? self::SUCCESS : self::FAILURE;
    }

    private function mapStatus(string $s): string
    {
        // "paid" is the parakit-canonical name; ZainCash v2 calls a successful
        // payment "SUCCESS". Every other status is upper-cased to match v2.
        return strtolower($s) === 'paid' ? 'SUCCESS' : strtoupper($s);
    }
}
