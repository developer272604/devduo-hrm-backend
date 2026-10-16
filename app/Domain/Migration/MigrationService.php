<?php

namespace App\Domain\Migration;

use App\Domain\Audit\Audit;
use App\Domain\Migration\Models\MigrationEvent;
use App\Domain\Migration\Models\MigrationProject;
use App\Domain\Migration\Models\MigrationReconciliation;
use App\Domain\Migration\Models\MigrationStage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orchestrates the existing module imports in dependency order and proves each one with reconciliation checks before cutover.
 * It never imports rows itself and never decides: a stage is verified by a person, and a variance can only be accepted with a reason.
 */
final class MigrationService
{
    private const SATISFIED = ['imported', 'verified', 'skipped'];

    private const PROVEN = ['verified', 'skipped'];

    public function create(User $actor, array $d): MigrationProject
    {
        return DB::transaction(function () use ($actor, $d) {
            $p = MigrationProject::create(['name' => $d['name'], 'source_system' => $d['source_system'] ?? null, 'target_cutover_on' => $d['target_cutover_on'] ?? null, 'notes' => $d['notes'] ?? null,
                'created_by' => $actor->id, 'status' => 'planning']);
            foreach (MigrationCatalog::order() as $i => $code) {
                MigrationStage::create(['project_id' => $p->id, 'code' => $code, 'position' => $i + 1]);
            }
            $this->event($p, 'created', null, 'Project created with '.count(MigrationCatalog::STAGES).' stages.', $actor);
            Audit::record('migration.project.create', 'integrations', 'migration:'.$p->uuid, ['name' => $p->name]);

            return $p;
        });
    }

    public function find(string $uuid): MigrationProject
    {
        return MigrationProject::where('uuid', $uuid)->firstOrFail();
    }

    private function stage(MigrationProject $p, string $code): MigrationStage
    {
        if (! isset(MigrationCatalog::STAGES[$code])) {
            throw ValidationException::withMessages(['stage' => 'Unknown stage.']);
        }

        return MigrationStage::where('project_id', $p->id)->where('code', $code)->firstOrFail();
    }

    private function assertEditable(MigrationProject $p): void
    {
        if (in_array($p->status, ['live', 'cancelled'], true)) {
            throw ValidationException::withMessages(['project' => "A {$p->status} project can no longer be changed."]);
        }
    }

    public function index(): array
    {
        return MigrationProject::orderByDesc('id')->limit(100)->get()->map(fn ($p) => $this->summary($p))->all();
    }

    private function summary(MigrationProject $p): array
    {
        $stages = $p->stages()->get();
        $done = $stages->whereIn('status', self::PROVEN)->count();

        return ['uuid' => $p->uuid, 'name' => $p->name, 'source_system' => $p->source_system, 'status' => $p->status, 'target_cutover_on' => $p->target_cutover_on?->toDateString(),
            'stages_total' => $stages->count(), 'stages_done' => $done, 'went_live_at' => $p->went_live_at?->toIso8601String()];
    }

    /** Re-reads each linked import so the project always reflects what is really in the module (e.g. a rolled-back import). */
    public function refresh(MigrationProject $p): void
    {
        foreach ($p->stages()->get() as $s) {
            if (! $s->import_uuid || $s->status === 'skipped') {
                continue;
            }
            $snap = $this->snapshot($s);
            if ($snap === null) {
                $s->update(['status' => 'pending', 'import_uuid' => null, 'import_snapshot' => null, 'verified_by' => null, 'verified_at' => null]);

                continue;
            }
            $def = MigrationCatalog::STAGES[$s->code];
            $reached = in_array($snap['status'], $def['done'], true);
            $status = $s->status;
            if ($s->status === 'verified' && (! $reached || ($s->import_snapshot['ok'] ?? null) !== $snap['ok'])) {
                $status = $reached ? 'imported' : 'linked';
                $this->event($p, 'invalidated', $s->code, 'The linked import changed after verification (status '.$snap['status'].', '.$snap['ok'].' rows). Re-verify.', null);
            } elseif ($s->status !== 'verified') {
                $status = $reached ? 'imported' : 'linked';
            }
            $s->update(['status' => $status, 'import_snapshot' => $snap] + ($status !== 'verified' ? ['verified_by' => null, 'verified_at' => null] : []));
        }
        if (in_array($p->status, ['planning', 'in_progress', 'ready_for_cutover'], true)) {
            $stages = $p->stages()->get();
            $next = $stages->whereIn('status', self::PROVEN)->count() === $stages->count() && $stages->where('status', 'verified')->isNotEmpty() ? 'ready_for_cutover'
                : ($stages->every(fn ($s) => $s->status === 'pending') ? 'planning' : 'in_progress');
            if ($next !== $p->status) {
                $p->update(['status' => $next]);
            }
        }
    }

    private function snapshot(MigrationStage $s): ?array
    {
        $def = MigrationCatalog::STAGES[$s->code];
        $q = DB::table($def['table'])->where('tenant_id', $s->tenant_id)->where('uuid', $s->import_uuid);
        if ($def['kind']) {
            $q->where('kind', $def['kind']);
        }
        $row = $q->first();
        if (! $row) {
            return null;
        }
        $c = $def['columns'];

        return ['status' => $row->status, 'total' => (int) $row->{$c['total']}, 'ok' => (int) $row->{$c['ok']}, 'error' => (int) $row->{$c['error']}, 'read_at' => now()->toIso8601String()];
    }

    public function show(MigrationProject $p): array
    {
        $this->refresh($p);
        $p->refresh();
        $stages = $p->stages()->with('reconciliations')->get()->keyBy('code');
        $out = [];
        foreach ($stages as $code => $s) {
            $def = MigrationCatalog::STAGES[$code];
            $blocked = collect($def['depends'])->reject(fn ($d) => in_array($stages[$d]->status, self::SATISFIED, true))->map(fn ($d) => MigrationCatalog::STAGES[$d]['label'])->values()->all();
            $unproven = collect($def['depends'])->reject(fn ($d) => in_array($stages[$d]->status, self::PROVEN, true))->map(fn ($d) => MigrationCatalog::STAGES[$d]['label'])->values()->all();
            $out[] = ['code' => $code, 'label' => $def['label'], 'position' => $s->position, 'status' => $s->status, 'import_uuid' => $s->import_uuid, 'skip_reason' => $s->skip_reason, 'override_reason' => $s->override_reason,
                'where_to_import' => $def['where'], 'href' => $def['href'], 'depends_on' => $def['depends'], 'waiting_for' => $blocked, 'verify_waiting_for' => $unproven, 'import' => $s->import_snapshot,
                'allowed_metrics' => collect($def['metrics'])->mapWithKeys(fn ($m) => [$m => MigrationCatalog::METRICS[$m]])->all(),
                'reconciliations' => $s->reconciliations->map(fn ($r) => ['metric' => $r->metric, 'source_value' => (float) $r->source_value, 'tolerance' => (float) $r->tolerance, 'imported_value' => $r->imported_value !== null ? (float) $r->imported_value : null,
                    'variance' => $r->variance !== null ? (float) $r->variance : null, 'status' => $r->status, 'note' => $r->note])->all(),
                'verified_at' => $s->verified_at?->toIso8601String(), 'verified_by' => $s->verified_by ? User::find($s->verified_by)?->name : null];
        }
        $blockers = $this->cutoverBlockers($p, $stages);

        return $this->summary($p) + ['notes' => $p->notes, 'stages' => $out, 'cutover_blockers' => $blockers, 'can_go_live' => $p->status === 'ready_for_cutover' && ! $blockers,
            'history' => MigrationEvent::where('project_id', $p->id)->orderBy('id')->get()->map(fn ($e) => ['type' => $e->type, 'stage' => $e->stage_code, 'detail' => $e->detail,
                'actor' => $e->actor_user_id ? User::find($e->actor_user_id)?->name : 'System', 'at' => $e->created_at?->toIso8601String()])->all(),
            'limitations' => 'Migration Studio orders and verifies the existing imports. It does not import policies, and it does not read the old system directly.'];
    }

    private function cutoverBlockers(MigrationProject $p, $stages): array
    {
        $b = [];
        foreach ($stages as $s) {
            if (! in_array($s->status, self::PROVEN, true)) {
                $b[] = MigrationCatalog::STAGES[$s->code]['label'].' is '.$s->status.'; verify or skip it with a reason.';
            }
        }
        if ($stages->where('status', 'verified')->isEmpty()) {
            $b[] = 'At least one stage must be verified.';
        }

        return $b;
    }

    public function linkImport(User $actor, MigrationProject $p, string $code, string $importUuid): MigrationProject
    {
        $this->assertEditable($p);
        $s = $this->stage($p, $code);
        $this->refresh($p);
        $s->refresh();
        if ($s->status === 'verified') {
            throw ValidationException::withMessages(['stage' => 'Unlink is not possible after verification. Re-open the stage first.']);
        }
        $stages = $p->stages()->get()->keyBy('code');
        foreach (MigrationCatalog::STAGES[$code]['depends'] as $d) {
            if (! in_array($stages[$d]->status, self::SATISFIED, true)) {
                throw ValidationException::withMessages(['stage' => 'Finish "'.MigrationCatalog::STAGES[$d]['label'].'" first.']);
            }
        }
        $taken = MigrationStage::where('import_uuid', $importUuid)->where('id', '!=', $s->id)->exists();
        if ($taken) {
            throw ValidationException::withMessages(['import_uuid' => 'This import is already linked to another stage.']);
        }
        $s->import_uuid = $importUuid;
        $s->skip_reason = null;
        $snap = $this->snapshot($s);
        if ($snap === null) {
            throw ValidationException::withMessages(['import_uuid' => 'No matching '.MigrationCatalog::STAGES[$code]['label'].' import found in your company.']);
        }
        $reached = in_array($snap['status'], MigrationCatalog::STAGES[$code]['done'], true);
        $s->update(['import_uuid' => $importUuid, 'import_snapshot' => $snap, 'status' => $reached ? 'imported' : 'linked', 'skip_reason' => null, 'verified_by' => null, 'verified_at' => null, 'override_reason' => null]);
        $s->reconciliations()->update(['status' => 'pending', 'imported_value' => null, 'variance' => null, 'checked_at' => null]);
        $this->event($p, 'linked', $code, 'Linked import '.$importUuid.' ('.$snap['status'].', '.$snap['ok'].'/'.$snap['total'].' rows).', $actor);
        Audit::record('migration.stage.link', 'integrations', 'migration:'.$p->uuid, ['stage' => $code, 'import' => $importUuid]);
        $this->refresh($p);

        return $p->refresh();
    }

    public function unlink(User $actor, MigrationProject $p, string $code): MigrationProject
    {
        $this->assertEditable($p);
        $s = $this->stage($p, $code);
        if ($s->status === 'verified') {
            throw ValidationException::withMessages(['stage' => 'Verified stages cannot be unlinked.']);
        }
        $s->update(['import_uuid' => null, 'import_snapshot' => null, 'status' => 'pending']);
        $this->event($p, 'unlinked', $code, null, $actor);
        $this->refresh($p);

        return $p->refresh();
    }

    public function skip(User $actor, MigrationProject $p, string $code, string $reason): MigrationProject
    {
        $this->assertEditable($p);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required to skip a stage.']);
        }
        $s = $this->stage($p, $code);
        if ($s->status === 'verified') {
            throw ValidationException::withMessages(['stage' => 'A verified stage cannot be skipped.']);
        }
        $s->update(['status' => 'skipped', 'skip_reason' => $reason, 'import_uuid' => null, 'import_snapshot' => null]);
        $this->event($p, 'skipped', $code, $reason, $actor);
        Audit::record('migration.stage.skip', 'integrations', 'migration:'.$p->uuid, ['stage' => $code, 'reason' => $reason]);
        $this->refresh($p);

        return $p->refresh();
    }

    public function reconcile(User $actor, MigrationProject $p, string $code, string $metric, float $source, float $tolerance, ?string $note): MigrationProject
    {
        $this->assertEditable($p);
        $s = $this->stage($p, $code);
        if (! in_array($metric, MigrationCatalog::STAGES[$code]['metrics'], true)) {
            throw ValidationException::withMessages(['metric' => 'This metric is not available for this stage.']);
        }
        if ($tolerance < 0) {
            throw ValidationException::withMessages(['tolerance' => 'Tolerance cannot be negative.']);
        }
        $r = MigrationReconciliation::firstOrNew(['stage_id' => $s->id, 'metric' => $metric]);
        $r->fill(['source_value' => $source, 'tolerance' => $tolerance, 'note' => $note, 'entered_by' => $actor->id]);
        $r->save();
        $this->refresh($p);
        $this->check($s->refresh(), $r);
        $this->event($p, 'reconciled', $code, "$metric: source $source", $actor);

        return $p->refresh();
    }

    private function check(MigrationStage $s, MigrationReconciliation $r): void
    {
        $s->refresh();
        $measured = MigrationCatalog::measure($s->tenant_id, $r->metric, $s->import_snapshot);
        if ($measured === null) {
            $r->update(['imported_value' => null, 'variance' => null, 'status' => 'pending', 'checked_at' => now()]);

            return;
        }
        $variance = round($measured - (float) $r->source_value, 4);
        $r->update(['imported_value' => $measured, 'variance' => $variance, 'status' => abs($variance) <= (float) $r->tolerance ? 'matched' : 'variance', 'checked_at' => now()]);
    }

    public function verify(User $actor, MigrationProject $p, string $code, ?string $overrideReason): MigrationProject
    {
        $this->assertEditable($p);
        $this->refresh($p);
        $s = $this->stage($p, $code);
        if ($s->status !== 'imported') {
            throw ValidationException::withMessages(['stage' => 'Only a stage whose import has completed can be verified (now: '.$s->status.').']);
        }
        $stages = $p->stages()->get()->keyBy('code');
        foreach (MigrationCatalog::STAGES[$code]['depends'] as $d) {
            if (! in_array($stages[$d]->status, self::PROVEN, true)) {
                throw ValidationException::withMessages(['stage' => 'Verify "'.MigrationCatalog::STAGES[$d]['label'].'" first.']);
            }
        }
        $recs = $s->reconciliations()->get();
        if ($recs->isEmpty()) {
            throw ValidationException::withMessages(['reconciliation' => 'Add at least one reconciliation check against the source system totals before verifying.']);
        }
        foreach ($recs as $r) {
            $this->check($s, $r);
        }
        $variances = $s->reconciliations()->get()->where('status', '!=', 'matched');
        if ($variances->isNotEmpty() && trim((string) $overrideReason) === '') {
            throw ValidationException::withMessages(['override_reason' => 'Some checks do not match ('.$variances->pluck('metric')->implode(', ').'). Fix the data, or accept the variance with a reason.']);
        }
        $s->update(['status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now(), 'override_reason' => $variances->isNotEmpty() ? $overrideReason : null]);
        $this->event($p, 'verified', $code, $variances->isNotEmpty() ? 'Accepted with variance: '.$overrideReason : 'All checks matched.', $actor);
        Audit::record('migration.stage.verify', 'integrations', 'migration:'.$p->uuid, ['stage' => $code, 'override' => $overrideReason]);
        $this->refresh($p);

        return $p->refresh();
    }

    public function reopen(User $actor, MigrationProject $p, string $code, string $reason): MigrationProject
    {
        $this->assertEditable($p);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }
        $s = $this->stage($p, $code);
        if (! in_array($s->status, ['verified', 'skipped'], true)) {
            throw ValidationException::withMessages(['stage' => 'Only verified or skipped stages can be reopened.']);
        }
        $s->update(['status' => $s->import_uuid ? 'linked' : 'pending', 'verified_by' => null, 'verified_at' => null, 'override_reason' => null, 'skip_reason' => null]);
        $this->event($p, 'reopened', $code, $reason, $actor);
        $this->refresh($p);

        return $p->refresh();
    }

    public function goLive(User $actor, MigrationProject $p): MigrationProject
    {
        $this->assertEditable($p);
        $this->refresh($p);
        $p->refresh();
        $blockers = $this->cutoverBlockers($p, $p->stages()->get());
        if ($blockers) {
            throw ValidationException::withMessages(['project' => $blockers]);
        }
        $p->update(['status' => 'live', 'went_live_at' => now(), 'went_live_by' => $actor->id]);
        $this->event($p, 'live', null, 'Cutover confirmed.', $actor);
        Audit::record('migration.project.live', 'integrations', 'migration:'.$p->uuid, []);

        return $p->refresh();
    }

    public function cancel(User $actor, MigrationProject $p, string $reason): MigrationProject
    {
        $this->assertEditable($p);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }
        $p->update(['status' => 'cancelled']);
        $this->event($p, 'cancelled', null, $reason, $actor);
        Audit::record('migration.project.cancel', 'integrations', 'migration:'.$p->uuid, ['reason' => $reason]);

        return $p->refresh();
    }

    private function event(MigrationProject $p, string $type, ?string $stage, ?string $detail, ?User $actor): void
    {
        MigrationEvent::create(['project_id' => $p->id, 'type' => $type, 'stage_code' => $stage, 'detail' => $detail, 'actor_user_id' => $actor?->id, 'created_at' => now()]);
    }
}
