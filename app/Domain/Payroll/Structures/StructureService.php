<?php

namespace App\Domain\Payroll\Structures;

use App\Domain\Audit\Audit;
use App\Domain\Payroll\Models\{SalaryComponent, SalaryStructure, SalaryStructureComponent};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Salary structures: reusable, versioned templates of components. A structure version is immutable once active (changes = new version). */
final class StructureService
{
    public function create(array $d, int $actorId, ?string $code = null, ?int $version = null): SalaryStructure
    {
        return DB::transaction(function () use ($d, $actorId, $code, $version) {
            DB::table('tenants')->where('id', auth()->user()->tenant_id)->lockForUpdate()->first();
            $code ??= $d['code'];
            if (! $version && SalaryStructure::where('code', $code)->exists()) throw ValidationException::withMessages(['code' => 'Code already exists. Create a new version of it instead.']);
            $codes = array_column($d['components'], 'component_code');
            if (count($codes) !== count(array_unique($codes))) throw ValidationException::withMessages(['components' => 'A component can appear only once in a structure.']);
            foreach ($d['components'] as $i => $line) {
                $q = SalaryComponent::where('code', $line['component_code'])->where('active', true)->where('effective_from', '<=', $d['effective_from']);
                if (! empty($d['currency'])) $q->where('currency', $d['currency']);
                if (! $q->exists()) throw ValidationException::withMessages(["components.$i.component_code" => 'Unknown, inactive or wrong-currency component "'.$line['component_code'].'" for the structure start date.']);
            }
            $s = SalaryStructure::create(['code' => $code, 'name' => $d['name'], 'version' => $version ?? 1, 'currency' => $d['currency'] ?? null, 'country' => $d['country'] ?? null, 'effective_from' => $d['effective_from'],
                'effective_to' => $d['effective_to'] ?? null, 'status' => 'draft', 'rules' => $d['rules'] ?? null, 'description' => $d['description'] ?? null, 'created_by' => $actorId]);
            foreach ($d['components'] as $i => $line) {
                SalaryStructureComponent::create(['structure_id' => $s->id, 'component_code' => $line['component_code'], 'sequence' => $line['sequence'] ?? (($i + 1) * 10), 'required' => $line['required'] ?? true, 'overrides' => $line['overrides'] ?? null]);
            }
            Audit::record('structure.created', 'payroll', $s->uuid, ['code' => $code, 'version' => $s->version]);

            return $s;
        });
    }

    public function newVersion(SalaryStructure $base, array $d, int $actorId): SalaryStructure
    {
        $latest = SalaryStructure::where('code', $base->code)->orderByDesc('version')->firstOrFail();
        if (substr((string) $latest->effective_from, 0, 10) >= $d['effective_from']) throw ValidationException::withMessages(['effective_from' => 'A new version must start after the latest version.']);

        return $this->create($d + ['name' => $latest->name], $actorId, $base->code, $latest->version + 1);
    }

    public function activate(SalaryStructure $s): SalaryStructure
    {
        if ($s->status !== 'draft') throw ValidationException::withMessages(['status' => 'Only a draft structure can be activated.']);
        DB::transaction(function () use ($s) {
            $prev = SalaryStructure::where('code', $s->code)->where('status', 'active')->where('version', '<', $s->version)->whereNull('effective_to')->first();
            $prev?->update(['effective_to' => CarbonImmutable::parse(substr((string) $s->effective_from, 0, 10))->subDay()->toDateString()]);
            $s->update(['status' => 'active']);
            Audit::record('structure.activated', 'payroll', $s->uuid);
        });

        return $s;
    }

    /** Active structure version in force on a date. */
    public function resolve(string $code, string $date): ?SalaryStructure
    {
        return SalaryStructure::where('code', $code)->where('status', 'active')->where('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->orderByDesc('version')->first();
    }

    public function present(SalaryStructure $s): array
    {
        $s->loadMissing('components');

        return ['uuid' => $s->uuid, 'code' => $s->code, 'name' => $s->name, 'version' => $s->version, 'status' => $s->status, 'currency' => $s->currency, 'country' => $s->country,
            'effective_from' => substr((string) $s->effective_from, 0, 10), 'effective_to' => $s->effective_to ? substr((string) $s->effective_to, 0, 10) : null, 'rules' => $s->rules, 'description' => $s->description,
            'components' => $s->components->sortBy('sequence')->values()->map(fn ($c) => ['component_code' => $c->component_code, 'sequence' => $c->sequence, 'required' => $c->required, 'overrides' => $c->overrides])->all()];
    }
}
