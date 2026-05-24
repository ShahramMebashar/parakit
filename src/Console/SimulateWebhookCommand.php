<?php
declare(strict_types=1);

namespace Froshly\Parakit\Console;

use Firebase\JWT\JWT;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SimulateWebhookCommand extends Command
{
    protected $signature = 'parakit:webhooks:simulate
        {gateway}
        {--status=paid}
        {--reference=}
        {--transaction-id=}
        {--sign-with=}';

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
            // QiCard signs a flat JSON body. parakit verifies the X-Signature
            // header against the configured public key, or falls back to a
            // server-to-server status re-check if no key is set.
            'qicard' => [
                'paymentId'    => $txnId,
                'requestId'    => $reference !== '' ? $reference : (string) Str::uuid(),
                'status'       => strtoupper($status) === 'PAID' ? 'SUCCESS' : strtoupper($status),
                'amount'       => 5000,
                'currency'     => 'IQD',
                'creationDate' => gmdate('Y-m-d\TH:i:s'),
            ],
            default => [],
        };

        // NassWallet's gateway appends /callback to the merchant callback URL.
        $suffix = $gw === 'nasswallet' ? '/callback' : '';
        $url = url("/{$path}/{$gw}{$suffix}");

        // NassWallet and QiCard expect a JSON body; the others post a flat
        // form, matching how each real gateway delivers its callback.
        $client = in_array($gw, ['nasswallet', 'qicard'], true) ? Http::asJson() : Http::asForm();

        // QiCard signs the canonical webhook string with the merchant's
        // RSA-2048 private key. When --sign-with is supplied we mimic that
        // here so the receiving app can exercise its verification path
        // end-to-end without a live gateway.
        $signWith = (string) $this->option('sign-with');
        if ($gw === 'qicard' && $signWith !== '') {
            $canonical = \Froshly\Parakit\Gateways\QiCard\QiCardSignatureVerifier::canonicalString($body);
            $pkey = @file_get_contents($signWith);
            if ($pkey === false) {
                $this->error("Could not read private key from {$signWith}");
                return self::FAILURE;
            }
            $sig = '';
            if (!openssl_sign($canonical, $sig, $pkey, OPENSSL_ALGO_SHA256)) {
                $this->error('openssl_sign failed; check the key format');
                return self::FAILURE;
            }
            $client = $client->withHeaders(['X-Signature' => base64_encode($sig)]);
        }

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
