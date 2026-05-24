<?php
declare(strict_types=1);

namespace Froshly\Parakit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Froshly\Parakit\Gateways\Fib\FibTokenCache;

class DoctorCommand extends Command
{
    protected $signature = 'parakit:doctor {--gateway=}';
    protected $description = 'Verify parakit configuration and gateway connectivity';

    public function handle(): int
    {
        $only = (string) $this->option('gateway');
        $gateways = $only !== ''
            ? [$only => config("parakit.gateways.{$only}")]
            : (array) config('parakit.gateways', []);

        if ($gateways === []) {
            $this->error('No gateways configured under parakit.gateways.');
            return self::FAILURE;
        }

        $ok = true;
        foreach ($gateways as $name => $cfg) {
            if (!is_array($cfg)) {
                $this->error("[{$name}] not configured");
                $ok = false;
                continue;
            }
            $this->line("Checking {$name}...");

            $driverType = (string) ($cfg['driver'] ?? $name);
            $required = $this->requiredKeysFor($driverType);

            if ($required === null) {
                // Unknown driver type — likely registered via PaymentManager::extend().
                // We can't validate fields blindly; surface the gap rather than
                // silently reporting OK.
                $this->warn("  - driver '{$driverType}' has no built-in config check; verify manually");
                continue;
            }

            foreach ($required as $k) {
                if (empty($cfg[$k])) {
                    $this->error("  - missing config: parakit.gateways.{$name}.{$k}");
                    $ok = false;
                }
            }

            if ($driverType === 'qicard' && empty($cfg['public_key'])) {
                // Running QiCard without the public key means we cannot prove
                // webhook authenticity from the body alone — parakit falls
                // back to a server-to-server status re-check on every
                // notification. Acceptable in dev; almost never what you want
                // in production. Surface it so it's a deliberate choice.
                $this->warn("  - QiCard public_key not set: webhooks will be verified by status re-check, not RSA signature.");
            }

            if ($driverType === 'fib' && !empty($cfg['client_id']) && !empty($cfg['client_secret'])) {
                // Force a fresh fetch — a cached token from a rotated secret
                // would otherwise mask the credential rotation and let the
                // doctor report OK while real charges 401.
                Cache::forget('parakit:fib:token');
                try {
                    (new FibTokenCache(
                        (string) $cfg['base_url'],
                        (string) $cfg['client_id'],
                        (string) $cfg['client_secret'],
                    ))->token();
                    $this->info('  - FIB token: OK');
                } catch (\Throwable $e) {
                    $this->error('  - FIB token: ' . $e->getMessage());
                    $ok = false;
                }
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** @return string[]|null */
    private function requiredKeysFor(string $driverType): ?array
    {
        return match ($driverType) {
            'fib' => ['base_url', 'client_id', 'client_secret', 'callback_url'],
            'zaincash' => ['base_url', 'client_id', 'client_secret', 'api_key'],
            'nass' => ['base_url', 'username', 'password', 'callback_url'],
            'nasswallet' => [
                'base_url',
                'portal_url',
                'basic_token',
                'username',
                'password',
                'transaction_pin',
                'callback_url',
            ],
            'fastpay' => ['base_url', 'store_id', 'store_password', 'callback_url'],
            'qicard' => ['base_url', 'username', 'password', 'terminal_id'],
            default => null,
        };
    }
}
