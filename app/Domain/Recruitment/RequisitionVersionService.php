<?php

namespace App\Domain\Recruitment;

use App\Domain\Audit\Audit;
use App\Domain\Organization\OrganizationRecord;
use App\Domain\Recruitment\Models\JobDefinition;
use App\Domain\Recruitment\Models\Requisition;
use App\Domain\Recruitment\Models\RequisitionVersion;
use App\Domain\Work\TaskService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Amendments to material requisition fields. Every change is a numbered version with before/after snapshots.
 * Increases of openings or budget wait for an independent approver (maker-checker) and do not touch the live
 * requisition until approved; open applications, offers and the filled counter are never modified here.
 */
final class RequisitionVersionService
{
    public const FIELDS = ['openings', 'budget_amount', 'budget_currency', 'department_id', 'location_id', 'job_id', 'target_date'];

    public function snapshot(Requisition $r): array
    {
        return ['openings' => (int) $r->openings, 'budget_amount' => $r->budget_amount === null ? null : number_format((float) $r->budget_amount, 2, '.', ''), 'budget_currency' => $r->budget_currency,
            'department_id' => $r->department_id, 'location_id' => $r->location_id, 'job_id' => $r->job_id, 'target_date' => $r->target_date->toDateString()];
    }

    /** Compare two non-negative decimal strings exactly (no floats). */
    private static function cents(?string $v): int
    {
        if ($v === null || $v === '') {
            return 0;
        }
        [$i, $f] = array_pad(explode('.', $v, 2), 2, '0');

        return (int) $i * 100 + (int) str_pad(substr($f, 0, 2), 2, '0');
    }

    public function amend(User $actor, string $uuid, array $changes, string $reason, ?int $expectedVersion): RequisitionVersion
    {
        abort_unless($actor->allows('recruitment.manage'), 403);

        return DB::transaction(function () use ($actor, $uuid, $changes, $reason, $expectedVersion) {
            $r = app(HiringScope::class)->requisitions($actor, 'recruitment.manage')->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($r->status, ['pending', 'open'], true), 409, 'Only pending or open requisitions can be amended.');
            abort_if($expectedVersion !== null && $expectedVersion !== (int) $r->version, 409, 'The requisition changed; refresh before amending it.');
            abort_if(RequisitionVersion::where('requisition_id', $r->id)->where('status', 'pending')->exists(), 409, 'An amendment is already awaiting approval.');

            if (isset($changes['job_uuid'])) {
                $changes['job_id'] = JobDefinition::where('uuid', $changes['job_uuid'])->firstOrFail()->id;
                unset($changes['job_uuid']);
            }
            foreach (['department_id' => 'departments', 'location_id' => 'locations'] as $key => $type) {
                if (! empty($changes[$key])) {
                    OrganizationRecord::forType($type)->newQuery()->whereKey($changes[$key])->firstOrFail();
                }
            }
            if (isset($changes['budget_amount'])) {
                $changes['budget_amount'] = number_format((float) $changes['budget_amount'], 2, '.', '');
            }
            if (isset($changes['budget_currency'])) {
                $changes['budget_currency'] = strtoupper($changes['budget_currency']);
            }
            $before = $this->snapshot($r);
            $after = array_merge($before, array_intersect_key($changes, array_flip(self::FIELDS)));
            $changed = array_values(array_filter(array_keys($after), fn ($k) => $after[$k] !== $before[$k]));
            if (! $changed) {
                throw ValidationException::withMessages(['reason' => 'No material field changed.']);
            }
            if ($after['openings'] < (int) $r->filled_count) {
                throw ValidationException::withMessages(['openings' => 'Openings cannot be lower than the '.(int) $r->filled_count.' already filled.']);
            }
            if ($after['budget_amount'] !== null && $after['budget_currency'] === null) {
                throw ValidationException::withMessages(['budget_currency' => 'A budget needs a currency.']);
            }
            if ($after['openings'] > $before['openings'] && $r->position_id) {
                $position = OrganizationRecord::forType('positions')->newQuery()->whereKey($r->position_id)->first();
                $cap = $position?->metadata['headcount'] ?? null;
                if ($cap !== null) {
                    $committed = (int) Requisition::where('position_id', $r->position_id)->where('id', '!=', $r->id)->whereIn('status', ['pending', 'open', 'filled'])->sum('openings');
                    if ($committed + $after['openings'] > (int) $cap) {
                        throw ValidationException::withMessages(['openings' => 'Approved headcount for this position is '.(int) $cap.'; '.$committed.' is committed to other requisitions.']);
                    }
                }
            }

            $increase = $after['openings'] > $before['openings']
                || self::cents($after['budget_amount']) > self::cents($before['budget_amount'])
                || ($after['budget_currency'] !== $before['budget_currency'] && $after['budget_amount'] !== null && $before['budget_amount'] !== null);
            $needsApproval = $r->status === 'open' && $increase; // a pending requisition is still awaiting its initial approval

            $next = (RequisitionVersion::where('requisition_id', $r->id)->max('version') ?? 1) + 1;
            $v = RequisitionVersion::create(['requisition_id' => $r->id, 'version' => $next, 'status' => $needsApproval ? 'pending' : 'applied', 'requires_approval' => $needsApproval,
                'before' => $before, 'after' => $after, 'changed_fields' => $changed, 'reason' => $reason, 'created_by' => $actor->id]);
            if ($needsApproval) {
                foreach (RecruitmentNotify::holders('recruitment.approve', $actor->id) as $approver) {
                    if (app(HiringScope::class)->requisitions($approver, 'recruitment.approve')->whereKey($r->id)->exists()) {
                        app(TaskService::class)->createOnce(['title' => 'Review requisition amendment', 'description' => 'An increase in openings or budget needs independent approval.', 'assignee_id' => $approver->id,
                            'source_module' => 'recruitment', 'source_entity' => 'requisition-version:'.$v->uuid, 'action' => '/recruitment', 'status' => 'open', 'priority' => 'normal']);
                    }
                }
                Audit::record('requisition.amendment_requested', 'recruitment', $r->uuid, ['version' => $next, 'fields' => $changed]);
            } else {
                $this->apply($r, $after, $next);
                Audit::record('requisition.amended', 'recruitment', $r->uuid, ['version' => $next, 'fields' => $changed]);
            }

            return $v;
        });
    }

    private function apply(Requisition $r, array $after, int $version): void
    {
        $r->update($after + ['version' => $version]);
    }

    public function decide(User $actor, string $uuid, int $version, string $decision): RequisitionVersion
    {
        abort_unless($actor->allows('recruitment.approve'), 403);

        return DB::transaction(function () use ($actor, $uuid, $version, $decision) {
            $r = app(HiringScope::class)->requisitions($actor, 'recruitment.approve')->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $v = RequisitionVersion::where('requisition_id', $r->id)->where('version', $version)->lockForUpdate()->firstOrFail();
            abort_if($v->created_by === $actor->id, 403, 'A different authorized person must approve the amendment.');
            abort_unless($v->status === 'pending', 409, 'This amendment has already been reviewed.');
            abort_unless($r->status === 'open', 409, 'The requisition is no longer open.');
            if ($decision === 'approved') {
                // The live requisition may only have moved through other versions that were applied; re-check the floor.
                abort_if($v->after['openings'] < (int) $r->filled_count, 409, 'Openings are lower than the number already filled.');
                $this->apply($r, $v->after, $version);
            }
            $v->update(['status' => $decision, 'decided_by' => $actor->id, 'decided_at' => now()]);
            app(TaskService::class)->completeBySource('recruitment', 'requisition-version:'.$v->uuid);
            RecruitmentNotify::tell($v->created_by, 'Requisition amendment '.$decision, 'Your requisition amendment was '.$decision.'.', '/recruitment');
            Audit::record('requisition.amendment_'.$decision, 'recruitment', $r->uuid, ['version' => $version]);

            return $v;
        });
    }

    public static function present(RequisitionVersion $v): array
    {
        return ['uuid' => $v->uuid, 'version' => $v->version, 'status' => $v->status, 'requires_approval' => $v->requires_approval, 'before' => $v->before, 'after' => $v->after, 'changed_fields' => $v->changed_fields,
            'reason' => $v->reason, 'created_by' => $v->created_by, 'decided_by' => $v->decided_by, 'decided_at' => $v->decided_at?->toIso8601String(), 'created_at' => $v->created_at?->toIso8601String()];
    }
}
