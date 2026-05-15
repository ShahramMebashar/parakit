<?php
declare(strict_types=1);

namespace Froshly\Parakit;

use Closure;
use Illuminate\Contracts\Container\Container;
use Froshly\Parakit\Contracts\PaymentGateway;
use Froshly\Parakit\Exceptions\UnsupportedGatewayException;
use Froshly\Parakit\Gateways\Fib\FibGateway;
use Froshly\Parakit\Gateways\Nass\NassGateway;
use Froshly\Parakit\Gateways\ZainCash\ZainCashGateway;

class PaymentManager
{
    /** @var array<string, PaymentGateway> */
    private array $resolved = [];

    /** @var array<string, Closure(array, Container, string): PaymentGateway> */
    private array $customCreators = [];

    /**
     * User-supplied resolver. Documented to return an array<string,mixed>
     * config, but typed `mixed` because the callback is untrusted — driver()
     * guards the return at runtime.
     *
     * @var (Closure(string): mixed)|null
     */
    private ?Closure $merchantResolver = null;

    public function __construct(private readonly Container $container) {}

    public function driver(?string $name = null): PaymentGateway
    {
        $name ??= (string) config('parakit.default');

        // Memo: same gateway name reuses the same instance. Flushed by
        // flushResolved() between Octane requests so tenants never share a
        // stale instance across requests.
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        if ($this->merchantResolver !== null) {
            $cfg = ($this->merchantResolver)($name);
            if (!is_array($cfg) || $cfg === []) {
                throw new UnsupportedGatewayException("Merchant resolver returned no config for: {$name}");
            }
            return $this->resolved[$name] = $this->makeDriver($name, $cfg);
        }

        $cfg = config("parakit.gateways.{$name}");
        if (!is_array($cfg)) {
            throw new UnsupportedGatewayException("Unknown gateway: {$name}");
        }

        return $this->resolved[$name] = $this->makeDriver($name, $cfg);
    }

    /**
     * Register a callback that supplies the gateway config array for a given
     * gateway name at request time. Expected to return array<string,mixed>.
     *
     * @param Closure(string): mixed $resolver
     */
    public function resolveMerchantUsing(Closure $resolver): void
    {
        $this->merchantResolver = $resolver;
    }

    /** @param Closure(array, Container): PaymentGateway $creator */
    public function extend(string $driver, Closure $creator): void
    {
        $this->customCreators[$driver] = $creator;
    }

    public function flushResolved(): void
    {
        $this->resolved = [];
    }

    public function for(object|string $reference, string $keyAttribute = 'id'): PaymentBuilder
    {
        $ref = is_object($reference)
            ? (string) data_get($reference, $keyAttribute)
            : (string) $reference;

        return new PaymentBuilder($this, $ref);
    }

    protected function createFibDriver(array $cfg, string $name): PaymentGateway
    {
        return new FibGateway($name, $cfg);
    }

    protected function createZaincashDriver(array $cfg, string $name): PaymentGateway
    {
        return new ZainCashGateway($name, $cfg);
    }

    protected function createNassDriver(array $cfg, string $name): PaymentGateway
    {
        return new NassGateway($name, $cfg);
    }

    /** @param array<string,mixed> $cfg */
    private function makeDriver(string $name, array $cfg): PaymentGateway
    {
        $driver = $cfg['driver'] ?? $name;

        // The configured key ($name, e.g. "fib_branch_a") is the identity the
        // gateway must use for its breaker key, idempotency cache, webhook
        // routing and stored `gateway` column — NOT the driver type ($driver,
        // e.g. "fib"). Two configs pointing at the same driver must remain
        // independent.
        if (isset($this->customCreators[$driver])) {
            return ($this->customCreators[$driver])($cfg, $this->container, $name);
        }

        $method = 'create' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $driver))) . 'Driver';
        if (method_exists($this, $method)) {
            return $this->{$method}($cfg, $name);
        }

        throw new UnsupportedGatewayException("No driver registered for: {$driver}");
    }
}
