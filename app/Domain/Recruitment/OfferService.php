<?php

namespace App\Domain\Recruitment;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Payroll\Contracts\CompensationResolver;
use App\Domain\People\Models\Employee;
use App\Domain\Recruitment\Models\Offer;
use App\Domain\Work\TaskService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Versioned offers with maker-checker approval. Compensation is a REFERENCE only (text / payroll structure id), never an amount. */
final class OfferService
{
    public const OPEN = ['draft', 'pending_approval', 'approved', 'sent'];

    public function create(User $actor, string $applicationUuid, array $data): Offer
    {
        abort_unless($actor->allows('recruitment.offer.manage'), 403);
        return DB::transaction(function () use ($actor, $applicationUuid, $data) {
            DB::table('tenants')->where('id', $actor->tenant_id)->lockForUpdate()->first();
            $app = app(HiringScope::class)->applications($actor, 'recruitment.offer.manage')->where('uuid', $applicationUuid)->lockForUpdate()->firstOrFail();
            abort_unless($app->status === 'active' && app(PipelineService::class)->stageKind($app->requisition, $app->stage) === 'offer', 409, 'Offers can be drafted only for active applications in the offer stage.');
            abort_if(Offer::where('application_id', $app->id)->where('status', 'accepted')->exists(), 409, 'An offer was already accepted for this application.');
            $reference = $data['compensation_reference'] ?? null;
            if (!empty($data['benchmark_employee_uuid'])) $reference = $this->benchmark($actor, $data['benchmark_employee_uuid'], $data['start_date'], $reference);
            Offer::where('application_id', $app->id)->whereIn('status', self::OPEN)->each(function ($o) {
                $o->update(['status' => 'superseded']);
                app(TaskService::class)->completeBySource('recruitment', 'offer:'.$o->uuid);
            });
            $offer = Offer::create(['application_id' => $app->id, 'version' => (Offer::where('application_id', $app->id)->max('version') ?? 0) + 1, 'status' => 'draft',
                'job_title' => $data['job_title'], 'start_date' => $data['start_date'], 'expires_on' => $data['expires_on'] ?? null, 'compensation_reference' => $reference,
                'currency' => $data['currency'] ?? null, 'notes' => $data['notes'] ?? null, 'created_by' => $actor->id]);
            Audit::record('offer.drafted', 'recruitment', $offer->uuid, ['version' => $offer->version]);
            return $offer;
        });
    }

    /** Compensation context from Payroll through its contract, only when bound and the actor may view compensation. Stores ids/labels, no amounts. */
    private function benchmark(User $actor, string $employeeUuid, string $date, ?string $note): string
    {
        if (!app()->bound(CompensationResolver::class)) throw ValidationException::withMessages(['benchmark_employee_uuid' => 'Payroll compensation is not available in this workspace.']);
        abort_unless($actor->allows('payroll.compensation.view'), 403);
        $employee = Employee::where('uuid', $employeeUuid)->firstOrFail();
        abort_unless(app(DataScope::class)->canAccess($actor, $employee, 'payroll.compensation.view'), 404);
        $comp = app(CompensationResolver::class)->current($employee->id, $date);
        if (!$comp) throw ValidationException::withMessages(['benchmark_employee_uuid' => 'No approved compensation applies to that employee on the start date.']);
        Audit::record('offer.compensation_reference', 'recruitment', 'employee:'.$employeeUuid);
        return trim(($note ? $note.' | ' : '').'Payroll structure #'.$comp['structure_id'].' ('.$comp['currency'].', '.$comp['pay_frequency'].')');
    }

    public function submit(User $actor, string $uuid): Offer
    {
        return $this->step($actor, $uuid, 'recruitment.offer.manage', ['draft'], function (Offer $o) use ($actor) {
            $o->update(['status' => 'pending_approval']);
            foreach (RecruitmentNotify::holders('recruitment.offer.approve', $actor->id) as $approver) {
                if (app(HiringScope::class)->applications($approver, 'recruitment.offer.approve')->whereKey($o->application_id)->exists())
                    RecruitmentNotify::task($approver->id, 'Review a job offer', 'An offer is waiting for an independent approval.', 'offer:'.$o->uuid);
            }
            Audit::record('offer.submitted', 'recruitment', $o->uuid);
        });
    }

    public function decide(User $actor, string $uuid, string $decision, ?string $note): Offer
    {
        return $this->step($actor, $uuid, 'recruitment.offer.approve', ['pending_approval'], function (Offer $o) use ($actor, $decision, $note) {
            abort_if($o->created_by === $actor->id, 403, 'A different authorized person must approve the offer.');
            $o->update(['status' => $decision, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note]);
            app(TaskService::class)->completeBySource('recruitment', 'offer:'.$o->uuid);
            RecruitmentNotify::tell($o->created_by, 'Offer '.$decision, 'An offer you drafted was '.$decision.'.');
            Audit::record('offer.'.$decision, 'recruitment', $o->uuid);
            if ($decision === 'approved') app(OfferDocumentService::class)->autoGenerate($actor, $o, 'approved');
        });
    }

    public function send(User $actor, string $uuid): Offer
    {
        return $this->step($actor, $uuid, 'recruitment.offer.manage', ['approved'], function (Offer $o) use ($actor) {
            abort_if($o->expires_on && $o->expires_on->toDateString() < now('UTC')->toDateString(), 409, 'The offer has expired; draft a new version.');
            $o->update(['status' => 'sent', 'sent_at' => now()]);
            Audit::record('offer.sent', 'recruitment', $o->uuid);
            app(OfferDocumentService::class)->autoGenerate($actor, $o->refresh(), 'sent');
        });
    }

    public function respond(User $actor, string $uuid, string $outcome): Offer
    {
        return $this->step($actor, $uuid, 'recruitment.offer.manage', ['sent'], function (Offer $o) use ($outcome) {
            abort_if($outcome === 'accepted' && $o->expires_on && $o->expires_on->toDateString() < now('UTC')->toDateString(), 409, 'The offer has expired; draft a new version.');
            $o->update(['status' => $outcome, 'responded_at' => now()]);
            if ($outcome === 'accepted') {
                foreach (RecruitmentNotify::holders('recruitment.handover') as $h) RecruitmentNotify::task($h->id, 'Complete pre-joiner handover', 'An offer was accepted. Create the employee record.', 'handover:'.$o->uuid, '/recruitment', 'high');
            }
            RecruitmentNotify::tell($o->application->requisition->recruiter_id, 'Offer '.$outcome, 'A candidate '.$outcome.' an offer.');
            Audit::record('offer.'.$outcome, 'recruitment', $o->uuid);
        });
    }

    public function withdraw(User $actor, string $uuid): Offer
    {
        return $this->step($actor, $uuid, 'recruitment.offer.manage', self::OPEN, function (Offer $o) {
            $o->update(['status' => 'withdrawn']);
            app(TaskService::class)->completeBySource('recruitment', 'offer:'.$o->uuid);
            Audit::record('offer.withdrawn', 'recruitment', $o->uuid);
        });
    }

    private function step(User $actor, string $uuid, string $permission, array $from, \Closure $apply): Offer
    {
        abort_unless($actor->allows($permission), 403);
        return DB::transaction(function () use ($actor, $uuid, $permission, $from, $apply) {
            $o = $this->find($actor, $uuid, $permission, true);
            abort_unless(in_array($o->status, $from, true), 409, 'This offer is '.str_replace('_', ' ', $o->status).' and cannot take that action.');
            $apply($o);
            return $o->refresh();
        });
    }

    public function find(User $actor, string $uuid, string $permission, bool $lock = false): Offer
    {
        $q = Offer::where('uuid', $uuid)->whereIn('application_id', app(HiringScope::class)->applications($actor, $permission)->select('id'));
        return ($lock ? $q->lockForUpdate() : $q)->with('application.requisition')->firstOrFail();
    }
}
