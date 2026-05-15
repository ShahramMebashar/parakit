<?php
declare(strict_types=1);

namespace Shah\Parakit;

use Closure;
use Illuminate\Contracts\Container\Container;
use Shah\Parakit\Contracts\PaymentGateway;
use Shah\Parakit\Exceptions\UnsupportedGatewayException;
use Shah\Parakit\Gateways\Fib\FibGateway;
use Shah\Parakit\Gateways\ZainCash\ZainCashGateway;

class PaymentManager
{
    /** @var array<string, PaymentGateway> */
    private array $resolved = [];

    /** @var array<string, Closure(array, Container, string): PaymentGateway> */
    private array $customCreators = [];

    public function __construct(private readonly Container $container) {}

    public function driver(?string $name = null): PaymentGateway
    {
        $name ??= (string) config('parakit.default');

        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $cfg = config("parakit.gateways.{$name}");
        if (!is_array($cfg)) {
            throw new UnsupportedGatewayException("Unknown gateway: {$name}");
        }

        $driver = $cfg['driver'] ?? $name;

        // The configured key ($name, e.g. "fib_branch_a") is the identity the
        // gateway must use for its breaker key, idempotency cache, webhook
        // routing and stored `gateway` column — NOT the driver type ($driver,
        // e.g. "fib"). Two configs pointing at the same driver must remain
        // independent.
        if (isset($this->customCreators[$driver])) {
            return $this->resolved[$name] = ($this->customCreators[$driver])($cfg, $this->container, $name);
        }

        $method = 'create' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $driver))) . 'Driver';
        if (method_exists($this, $method)) {
            return $this->resolved[$name] = $this->{$method}($cfg, $name);
        }

        throw new UnsupportedGatewayException("No driver registered for: {$driver}");
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
}
