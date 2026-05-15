<?php
declare(strict_types=1);

namespace Shah\Parakit\Console;

use Illuminate\Console\Command;
use Shah\Parakit\DTOs\PaymentRequest;
use Shah\Parakit\Enums\Currency;
use Shah\Parakit\PaymentManager;

class TestChargeCommand extends Command
{
    protected $signature = 'parakit:test-charge {gateway} {--amount=1000} {--currency=IQD}';
    protected $description = 'Run a sandbox roundtrip charge to prove end-to-end works';

    public function handle(PaymentManager $manager): int
    {
        $currency = Currency::tryFrom((string) $this->option('currency'));
        if ($currency === null) {
            $this->error('Unknown currency: ' . $this->option('currency'));
            return self::INVALID;
        }

        $resp = $manager->driver((string) $this->argument('gateway'))->charge(new PaymentRequest(
            // Sandbox-only random suffix; not collision-safe for production volumes.
            reference: 'test_' . bin2hex(random_bytes(4)),
            amount: (int) $this->option('amount'),
            currency: $currency,
            description: 'parakit test-charge',
        ));

        $this->info('OK: ' . ($resp->gatewayTransactionId ?? '(no id)'));
        if ($resp->redirectUrl) {
            $this->line("redirect: {$resp->redirectUrl}");
        }
        if ($resp->readableCode) {
            $this->line("readable: {$resp->readableCode}");
        }
        if ($resp->deepLink) {
            $this->line("deep-link: {$resp->deepLink}");
        }

        return self::SUCCESS;
    }
}
