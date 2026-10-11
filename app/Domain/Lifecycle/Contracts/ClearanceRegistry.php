<?php

namespace App\Domain\Lifecycle\Contracts;

final class ClearanceRegistry
{
    /** @var array<string,ClearanceProvider> */
    private array $providers = [];

    public function register(ClearanceProvider $p): void
    {
        $this->providers[$p->key()] = $p;
    }

    /** @return array<int,array{provider:string,message:string}> */
    public function blockers(int $employeeId): array
    {
        $out = [];
        foreach ($this->providers as $key => $p) {
            foreach ($p->blockers($employeeId) as $message) $out[] = ['provider' => $key, 'message' => $message];
        }

        return $out;
    }

    public function keys(): array
    {
        return array_keys($this->providers);
    }
}
