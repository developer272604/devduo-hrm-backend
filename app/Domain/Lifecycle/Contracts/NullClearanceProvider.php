<?php

namespace App\Domain\Lifecycle\Contracts;

/** Placeholder until the owning phase (assets, expenses, loans ...) registers a real provider: never blocks. */
final class NullClearanceProvider implements ClearanceProvider
{
    public function __construct(private string $key) {}

    public function key(): string
    {
        return $this->key;
    }

    public function blockers(int $employeeId): array
    {
        return [];
    }
}
