<?php

namespace App\Domain\Recruitment;

use App\Domain\Audit\Audit;
use App\Domain\Recruitment\Models\Pipeline;
use App\Domain\Recruitment\Models\PipelineStage;
use App\Domain\Recruitment\Models\Requisition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Tenant-defined hiring pipelines. A version becomes immutable once a requisition snapshots it; edits create a new version. */
final class PipelineService
{
    public const DEFAULT_CODE = 'DEFAULT';
    public const FALLBACK_SLA_HOURS = 720; // the former fixed 30-day reminder
    public const FALLBACK_FEEDBACK_HOURS = 24;
    public const KINDS = ['active', 'offer', 'hired', 'rejected'];
    public const RECIPIENTS = ['recruiter', 'hiring_manager'];

    public function ensureDefault(?int $actorId = null): Pipeline
    {
        $default = Pipeline::where('is_default', true)->where('status', 'active')->first();
        if ($default) {
            return $default;
        }

        return DB::transaction(function () use ($actorId) {
            DB::table('tenants')->where('id', app(\App\Domain\Tenancy\TenantContext::class)->id)->lockForUpdate()->first();
            if ($existing = Pipeline::where('is_default', true)->where('status', 'active')->first()) {
                return $existing;
            }
            $version = (Pipeline::where('code', self::DEFAULT_CODE)->max('version') ?? 0) + 1;
            $p = Pipeline::create(['code' => self::DEFAULT_CODE, 'version' => $version, 'name' => 'Standard hiring pipeline', 'status' => 'active', 'is_default' => true, 'allow_skip' => false, 'feedback_reminder_hours' => self::FALLBACK_FEEDBACK_HOURS, 'created_by' => $actorId]);
            foreach (HiringService::STAGES as $i => $code) {
                PipelineStage::create(['pipeline_id' => $p->id, 'position' => $i + 1, 'code' => $code, 'label' => ucfirst($code), 'kind' => $code === 'offer' ? 'offer' : 'active', 'sla_hours' => null, 'reminder_recipients' => ['recruiter']]);
            }

            return $p;
        });
    }

    /** Pipeline governing a requisition without changing it (unbound requisitions use today's default). */
    public function resolve(Requisition $r): Pipeline
    {
        $p = $r->pipeline_id ? Pipeline::find($r->pipeline_id) : null;
        $p ??= $this->ensureDefault();
        $p->loadMissing('stages');

        return $p;
    }

    /** Snapshot the pipeline version onto the requisition and freeze that version. */
    public function bind(Requisition $r): Pipeline
    {
        if ($r->pipeline_id && ($p = Pipeline::find($r->pipeline_id))) {
            $p->loadMissing('stages');
        } else {
            $p = $r->pipeline_code
                ? (Pipeline::where('code', $r->pipeline_code)->where('status', 'active')->orderByDesc('version')->first() ?? $this->ensureDefault())
                : $this->ensureDefault();
            $p->loadMissing('stages');
            $r->update(['pipeline_id' => $p->id]);
        }
        if (! $p->used_at) {
            $p->update(['used_at' => now()]);
        }

        return $p;
    }

    public function firstStage(Pipeline $p): string
    {
        return $p->stages->first()->code;
    }

    public function stageKind(Requisition $r, string $stage): ?string
    {
        return $this->resolve($r)->stages->firstWhere('code', $stage)?->kind;
    }

    /** Effective SLA hours for a stage; falls back to the former fixed threshold. */
    public static function slaHours(?PipelineStage $stage): int
    {
        return $stage && $stage->sla_hours ? (int) $stage->sla_hours : self::FALLBACK_SLA_HOURS;
    }

    public function normalize(array $data): array
    {
        $stages = array_values($data['stages']);
        $errors = [];
        $codes = [];
        foreach ($stages as $i => $s) {
            if (in_array($s['code'], $codes, true)) {
                $errors["stages.$i.code"] = 'Stage codes must be unique.';
            }
            $codes[] = $s['code'];
        }
        $kinds = array_column($stages, 'kind');
        if (($kinds[0] ?? null) !== 'active') {
            $errors['stages'] = 'The first stage must be an active stage.';
        } elseif (count(array_keys($kinds, 'offer', true)) !== 1) {
            $errors['stages'] = 'Exactly one offer stage is required.';
        } else {
            $seenTerminal = false;
            foreach ($stages as $i => $s) {
                $terminal = in_array($s['kind'], ['hired', 'rejected'], true);
                if ($seenTerminal && ! $terminal) {
                    $errors['stages'] = 'Hired and rejected stages must come last.';
                }
                if ($terminal && ! empty($s['sla_hours'])) {
                    $errors["stages.$i.sla_hours"] = 'Terminal stages cannot have an SLA.';
                }
                $seenTerminal = $seenTerminal || $terminal;
            }
            foreach (['hired', 'rejected'] as $k) {
                if (count(array_keys($kinds, $k, true)) > 1) {
                    $errors['stages'] = "At most one $k stage is allowed.";
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return array_map(fn ($s, $i) => ['position' => $i + 1, 'code' => $s['code'], 'label' => $s['label'], 'kind' => $s['kind'], 'sla_hours' => $s['sla_hours'] ?? null, 'reminder_recipients' => array_values(array_unique($s['reminder_recipients'] ?? ['recruiter']))], $stages, array_keys($stages));
    }

    private function fill(Pipeline $p, array $stages): void
    {
        PipelineStage::where('pipeline_id', $p->id)->delete();
        foreach ($stages as $s) {
            PipelineStage::create($s + ['pipeline_id' => $p->id]);
        }
    }

    public function create(User $actor, array $data): Pipeline
    {
        $stages = $this->normalize($data);

        return DB::transaction(function () use ($actor, $data, $stages) {
            DB::table('tenants')->where('id', $actor->tenant_id)->lockForUpdate()->first();
            if (Pipeline::where('code', $data['code'])->exists()) {
                abort(409, 'A pipeline with this code exists; create a new version instead.');
            }
            $this->ensureDefault($actor->id);
            $p = Pipeline::create(['code' => $data['code'], 'version' => 1, 'name' => $data['name'], 'status' => 'active', 'is_default' => false, 'allow_skip' => (bool) ($data['allow_skip'] ?? false), 'feedback_reminder_hours' => $data['feedback_reminder_hours'] ?? self::FALLBACK_FEEDBACK_HOURS, 'created_by' => $actor->id]);
            $this->fill($p, $stages);
            Audit::record('pipeline.created', 'recruitment', $p->uuid, ['code' => $p->code, 'version' => 1]);

            return $p->load('stages');
        });
    }

    /** In-place edit; only allowed while no requisition has used this version. */
    public function update(User $actor, string $uuid, array $data): Pipeline
    {
        $stages = $this->normalize($data);

        return DB::transaction(function () use ($actor, $uuid, $data, $stages) {
            $p = Pipeline::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_if($p->used_at || Requisition::where('pipeline_id', $p->id)->exists(), 409, 'This pipeline version is in use and immutable. Create a new version instead.');
            $p->update(['name' => $data['name'], 'allow_skip' => (bool) ($data['allow_skip'] ?? false), 'feedback_reminder_hours' => $data['feedback_reminder_hours'] ?? self::FALLBACK_FEEDBACK_HOURS]);
            $this->fill($p, $stages);
            Audit::record('pipeline.updated', 'recruitment', $p->uuid, ['code' => $p->code, 'version' => $p->version]);

            return $p->load('stages');
        });
    }

    public function newVersion(User $actor, string $uuid, array $data): Pipeline
    {
        $stages = $this->normalize($data);

        return DB::transaction(function () use ($actor, $uuid, $data, $stages) {
            DB::table('tenants')->where('id', $actor->tenant_id)->lockForUpdate()->first();
            $base = Pipeline::where('uuid', $uuid)->firstOrFail();
            abort_unless($base->status === 'active', 409, 'Create the next version from the active version.');
            if ($base->is_default) {
                // Unbound, already-open requisitions keep the version they were running on.
                Requisition::whereNull('pipeline_id')->where('status', '!=', 'pending')->update(['pipeline_id' => $base->id]);
                if (Requisition::where('pipeline_id', $base->id)->exists() && ! $base->used_at) {
                    $base->update(['used_at' => now()]);
                }
            }
            $version = (Pipeline::where('code', $base->code)->max('version') ?? 0) + 1;
            Pipeline::where('code', $base->code)->where('status', 'active')->update(['status' => 'retired', 'is_default' => false]);
            $p = Pipeline::create(['code' => $base->code, 'version' => $version, 'name' => $data['name'], 'status' => 'active', 'is_default' => $base->is_default, 'allow_skip' => (bool) ($data['allow_skip'] ?? false), 'feedback_reminder_hours' => $data['feedback_reminder_hours'] ?? self::FALLBACK_FEEDBACK_HOURS, 'created_by' => $actor->id]);
            $this->fill($p, $stages);
            Audit::record('pipeline.versioned', 'recruitment', $p->uuid, ['code' => $p->code, 'version' => $version, 'previous' => $base->uuid]);

            return $p->load('stages');
        });
    }

    public function makeDefault(User $actor, string $uuid): Pipeline
    {
        return DB::transaction(function () use ($actor, $uuid) {
            DB::table('tenants')->where('id', $actor->tenant_id)->lockForUpdate()->first();
            $p = Pipeline::where('uuid', $uuid)->firstOrFail();
            abort_unless($p->status === 'active', 409, 'Only the active version of a pipeline can be the default.');
            $current = $this->ensureDefault($actor->id);
            if ($current->id !== $p->id) {
                // Requisitions still on the old default stay on it.
                Requisition::whereNull('pipeline_id')->where('status', '!=', 'pending')->update(['pipeline_id' => $current->id]);
                if (Requisition::where('pipeline_id', $current->id)->exists() && ! $current->used_at) {
                    $current->update(['used_at' => now()]);
                }
                Pipeline::where('is_default', true)->update(['is_default' => false]);
                $p->update(['is_default' => true]);
                Audit::record('pipeline.default_changed', 'recruitment', $p->uuid, ['code' => $p->code, 'version' => $p->version]);
            }

            return $p->load('stages');
        });
    }

    public function delete(User $actor, string $uuid): void
    {
        DB::transaction(function () use ($uuid) {
            $p = Pipeline::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_if($p->used_at || $p->is_default || Requisition::where('pipeline_id', $p->id)->exists(), 409, 'Only unused, non-default pipeline versions can be deleted.');
            PipelineStage::where('pipeline_id', $p->id)->delete();
            $p->delete();
            Audit::record('pipeline.deleted', 'recruitment', $uuid, ['code' => $p->code, 'version' => $p->version]);
        });
    }

    public static function present(Pipeline $p): array
    {
        $p->loadMissing('stages');

        return ['uuid' => $p->uuid, 'code' => $p->code, 'version' => $p->version, 'name' => $p->name, 'status' => $p->status, 'is_default' => $p->is_default, 'allow_skip' => $p->allow_skip,
            'feedback_reminder_hours' => $p->feedback_reminder_hours, 'in_use' => (bool) $p->used_at,
            'stages' => $p->stages->map(fn ($s) => ['code' => $s->code, 'label' => $s->label, 'kind' => $s->kind, 'position' => $s->position, 'sla_hours' => $s->sla_hours, 'reminder_recipients' => $s->reminder_recipients ?? ['recruiter']])->values()->all()];
    }
}
