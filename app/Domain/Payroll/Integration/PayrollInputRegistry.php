<?php

namespace App\Domain\Payroll\Integration;

use App\Domain\Payroll\Contracts\Dto\PayrollInputBatch;
use App\Domain\Payroll\Contracts\Dto\PayrollInputQuery;
use App\Domain\Payroll\Contracts\PayrollInputProvider;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/** Collects the registered PayrollInputProviders (attendance, leave, ...). The run engine calls collect(); it never talks to Attendance / Leave directly. */
final class PayrollInputRegistry
{
    /** @var array<string,class-string<PayrollInputProvider>|PayrollInputProvider> */
    private array $providers = [];

    public function __construct(private readonly Container $container) {}

    /** @param class-string<PayrollInputProvider>|PayrollInputProvider $provider */
    public function register(string $source, string|PayrollInputProvider $provider): void
    {
        $this->providers[$source] = $provider;
    }

    /** @return array<int,string> */
    public function sources(): array
    {
        $keys = array_keys($this->providers);
        sort($keys);

        return $keys;
    }

    public function provider(string $source): PayrollInputProvider
    {
        $provider = $this->providers[$source] ?? throw new InvalidArgumentException('No payroll input provider is registered for "'.$source.'".');

        return is_object($provider) ? $provider : $this->container->make($provider);
    }

    /**
     * @param  array<int,string>|null  $sources  null = every registered provider
     * @return array<string,PayrollInputBatch>
     */
    public function collect(PayrollInputQuery $query, ?array $sources = null): array
    {
        $out = [];
        foreach ($sources ?? $this->sources() as $source) {
            $out[$source] = $this->provider($source)->collect($query);
        }

        return $out;
    }
}
