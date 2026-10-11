<?php

namespace App\Domain\Payroll\Support;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Keyed registry of pluggable payroll handlers (country packs, payment formats, accounting mappers). Workstreams register handlers
 * from their own Boot class; the engine only ever asks the registry, so adding a country or a bank format never edits the core.
 */
abstract class HandlerRegistry
{
    /** @var array<string,class-string|object> */
    private array $handlers = [];

    public function __construct(private readonly Container $container) {}

    /** Interface every handler must implement. */
    abstract protected function contract(): string;

    abstract protected function label(): string;

    /** @param class-string|object $handler */
    public function register(string $key, string|object $handler): void
    {
        $this->handlers[$key] = $handler;
    }

    public function has(string $key): bool
    {
        return isset($this->handlers[$key]);
    }

    /** @return array<int,string> */
    public function keys(): array
    {
        $keys = array_keys($this->handlers);
        sort($keys);

        return $keys;
    }

    public function resolve(string $key): object
    {
        $handler = $this->handlers[$key] ?? throw new InvalidArgumentException('No '.$this->label().' is registered as "'.$key.'". Registered: '.(implode(', ', $this->keys()) ?: 'none').'.');
        $instance = is_object($handler) ? $handler : $this->container->make($handler);
        $contract = $this->contract();
        if (! $instance instanceof $contract) {
            throw new InvalidArgumentException($this->label().' "'.$key.'" must implement '.$contract.'.');
        }

        return $instance;
    }
}
