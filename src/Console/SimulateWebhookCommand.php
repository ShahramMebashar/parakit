<?php
declare(strict_types=1);

namespace Gutian\Parakit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Gutian\Parakit\Gateways\ZainCash\ZainCashJwt;

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
                'token' => (new ZainCashJwt((string) ($cfg['secret'] ?? '')))->encode([
                    'id' => (string) $this->option('transaction-id'),
                    'status' => $this->mapStatus((string) $this->option('status')),
                    'orderid' => (string) $this->option('reference'),
                    'amount' => 5000,
                    'iat' => time(),
                ]),
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
        $s = strtolower($s);
        // "paid" is the parakit-canonical name; ZainCash calls a successful
        // payment "success". Every other status passes through unchanged.
        return $s === 'paid' ? 'success' : $s;
    }
}
