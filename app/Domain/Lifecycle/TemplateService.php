<?php

namespace App\Domain\Lifecycle;

use App\Domain\Audit\Audit;
use App\Domain\Lifecycle\Models\{Template, TemplateTask, TemplateVersion};
use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Versioned checklist templates. A published version is immutable: change it by creating (and publishing) the next version. */
final class TemplateService
{
    public const KINDS = ['onboarding', 'offboarding', 'transfer', 'promotion', 'absence_return', 'contract_renewal'];

    public const OWNERS = ['manager', 'hr', 'it', 'finance', 'employee', 'user'];

    public const ANCHORS = ['joining', 'last_day', 'effective'];

    public function create(User $actor, array $d): Template
    {
        return DB::transaction(function () use ($actor, $d) {
            $t = Template::create(['kind' => $d['kind'], 'name' => $d['name'], 'active' => true]);
            $this->addVersion($actor, $t, $d);
            Audit::record('template.created', 'lifecycle', $t->uuid, ['kind' => $t->kind]);

            return $t;
        });
    }

    public function addVersion(User $actor, Template $t, array $d): TemplateVersion
    {
        return DB::transaction(function () use ($actor, $t, $d) {
            Template::whereKey($t->id)->lockForUpdate()->first();
            $next = (int) TemplateVersion::where('template_id', $t->id)->max('version') + 1;
            $v = TemplateVersion::create(['template_id' => $t->id, 'version' => $next, 'status' => 'draft', 'conditions' => $this->conditions($d['conditions'] ?? []), 'created_by' => $actor->id]);
            foreach (array_values($d['tasks'] ?? []) as $i => $row) {
                if (!empty($row['owner_user_id']) && !User::where('tenant_id', $actor->tenant_id)->where('active', true)->whereKey($row['owner_user_id'])->exists()) throw \Illuminate\Validation\ValidationException::withMessages(["tasks.$i.owner_user_id" => 'Choose an active user from this workspace.']);
                if ($row['owner_type'] === 'user' && empty($row['owner_user_id'])) throw \Illuminate\Validation\ValidationException::withMessages(["tasks.$i.owner_user_id" => 'A named owner is required.']);
                $dependencies = array_values(array_unique($row['depends_on'] ?? []));
                foreach ($dependencies as $position) {
                    if (!is_int($position) || $position < 1 || $position > $i) throw \Illuminate\Validation\ValidationException::withMessages(["tasks.$i.depends_on" => 'Dependencies must be earlier task numbers (starting at 1).']);
                }
                TemplateTask::create([
                    'depends_on' => $dependencies, 'acknowledgment_text' => $row['acknowledgment_text'] ?? null,
                    'remind_before_days' => $row['remind_before_days'] ?? 2, 'escalate_after_days' => $row['escalate_after_days'] ?? 1,
                    'version_id' => $v->id, 'group_name' => $row['group_name'] ?? 'General', 'title' => $row['title'], 'description' => $row['description'] ?? null,
                    'owner_type' => $row['owner_type'], 'owner_user_id' => $row['owner_user_id'] ?? null, 'due_anchor' => $row['due_anchor'] ?? ($t->kind === 'offboarding' ? 'last_day' : 'joining'),
                    'due_offset_days' => (int) ($row['due_offset_days'] ?? 0), 'mandatory' => (bool) ($row['mandatory'] ?? true), 'clearance_dept' => $row['clearance_dept'] ?? null,
                    'requires_document' => (bool) ($row['requires_document'] ?? false), 'sort' => $i,
                ]);
            }
            Audit::record('template.version_added', 'lifecycle', $t->uuid, ['version' => $next]);

            return $v;
        });
    }

    public function publish(User $actor, TemplateVersion $v): TemplateVersion
    {
        return DB::transaction(function () use ($actor, $v) {
            $v = TemplateVersion::whereKey($v->id)->lockForUpdate()->firstOrFail();
            abort_unless($v->status === 'draft', 409, 'Only a draft version can be published.');
            abort_unless(TemplateTask::where('version_id', $v->id)->exists(), 422, 'Add at least one task before publishing.');
            TemplateVersion::where('template_id', $v->template_id)->where('status', 'published')->update(['status' => 'retired']);
            $v->update(['status' => 'published', 'published_at' => now()]);
            Audit::record('template.published', 'lifecycle', Template::find($v->template_id)->uuid, ['version' => $v->version]);

            return $v;
        });
    }

    /** Most specific published version whose conditions all match; ties go to the newest. */
    public function resolve(string $kind, Employee $employee, array $ctx = [], ?string $date = null): ?TemplateVersion
    {
        $facts = $this->facts($employee, $ctx, $date);
        $best = null;
        $bestScore = -1;
        $versions = TemplateVersion::where('status', 'published')->whereIn('template_id', Template::where('kind', $kind)->where('active', true)->select('id'))->orderByDesc('published_at')->orderByDesc('id')->get();
        foreach ($versions as $v) {
            $score = 0;
            foreach (($v->conditions ?: []) as $key => $want) {
                if (($facts[$key] ?? null) === null || (string) $facts[$key] !== (string) $want) continue 2;
                $score++;
            }
            if ($score > $bestScore) [$best, $bestScore] = [$v, $score];
        }

        return $best;
    }

    public function present(Template $t): array
    {
        $versions = TemplateVersion::where('template_id', $t->id)->orderBy('version')->get()->map(fn ($v) => [
            'uuid' => $v->uuid, 'version' => $v->version, 'status' => $v->status, 'conditions' => $v->conditions ?: new \stdClass, 'published_at' => $v->published_at?->toIso8601String(),
            'tasks' => TemplateTask::where('version_id', $v->id)->orderBy('sort')->get()->map(fn ($x) => [
                'group' => $x->group_name, 'group_name' => $x->group_name, 'title' => $x->title, 'description' => $x->description, 'owner_type' => $x->owner_type, 'owner_user_id' => $x->owner_user_id, 'due_anchor' => $x->due_anchor, 'due_offset_days' => $x->due_offset_days,
                'mandatory' => $x->mandatory, 'clearance_dept' => $x->clearance_dept, 'requires_document' => $x->requires_document, 'depends_on' => $x->depends_on ?? [], 'acknowledgment_text' => $x->acknowledgment_text, 'remind_before_days' => (int) $x->remind_before_days, 'escalate_after_days' => (int) $x->escalate_after_days])->all(),
        ])->all();

        return ['uuid' => $t->uuid, 'kind' => $t->kind, 'name' => $t->name, 'active' => $t->active, 'versions' => $versions];
    }

    private function conditions(array $c): array
    {
        return array_filter(array_intersect_key($c, array_flip(['country', 'legal_entity_id', 'worker_type', 'separation_type', 'is_rehire'])), fn ($v) => $v !== null && $v !== '');
    }

    /** @return array<string,mixed> */
    private function facts(Employee $employee, array $ctx, ?string $date): array
    {
        $a = $employee->assignmentOn($date);
        $country = null;
        if ($a?->legal_entity_id) {
            $meta = DB::table('legal_entities')->where('tenant_id', $employee->tenant_id)->where('id', $a->legal_entity_id)->value('metadata');
            $country = (is_string($meta) ? json_decode($meta, true) : (array) $meta)['country'] ?? null;
        }

        return ['country' => $country, 'legal_entity_id' => $a?->legal_entity_id, 'worker_type' => $employee->worker_type, 'separation_type' => $ctx['separation_type'] ?? null, 'is_rehire' => $employee->employment_sequence > 1];
    }
}
