<?php

namespace App\Domain\People\Employee360;

use App\Domain\Access\FieldAccess;
use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/** Registry + assembler of Employee 360. A failing provider is logged and skipped; it never breaks the others. */
final class Employee360
{
    /** @var array<int,Employee360Provider|string> */
    private array $providers = [];

    public function register(Employee360Provider|string $provider): void
    {
        $this->providers[] = $provider;
    }

    /** @return array<string,Employee360Provider> */
    public function providers(): array
    {
        $out = [];
        foreach ($this->providers as $i => $p) {
            $p = is_string($p) ? ($this->providers[$i] = app($p)) : $p;
            $out[$p->key()] = $p;
        }

        return $out;
    }

    /** @return array{employee_uuid:string,sections:array<int,array<string,mixed>>} */
    public function build(User $viewer, Employee $employee): array
    {
        $relation = app(FieldAccess::class)->relation($viewer, $employee);
        $sections = [];
        foreach ($this->providers() as $key => $provider) {
            try {
                foreach ($provider->sections($viewer, $employee, $relation) as $section) {
                    if (isset($section['key'], $section['title']) && ! empty($section['items'])) {
                        $sections[] = $section + ['order' => 100, 'source' => $key];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Employee 360 provider failed', ['provider' => $key, 'error' => $e->getMessage()]);
            }
        }
        usort($sections, fn ($a, $b) => $a['order'] <=> $b['order']);

        return ['employee_uuid' => $employee->uuid, 'sections' => $sections];
    }
}
