<?php

namespace App\Http\Controllers\Recruitment;

use App\Domain\Audit\Audit;
use App\Domain\People\Models\Employee;
use App\Domain\Recruitment\Contracts\CandidateChannelAdapter;
use App\Domain\Recruitment\HandoverService;
use App\Domain\Recruitment\HiringScope;
use App\Domain\Recruitment\InterviewService;
use App\Domain\Recruitment\Models\Application;
use App\Domain\Recruitment\Models\Handover;
use App\Domain\Recruitment\Models\Interview;
use App\Domain\Recruitment\Models\Offer;
use App\Domain\Recruitment\Models\Posting;
use App\Domain\Recruitment\Models\Requisition;
use App\Domain\Recruitment\OfferService;
use App\Domain\Recruitment\PipelineAnalytics;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class RecruitmentCoreController
{
    // ---- requisition lifecycle -------------------------------------------------------------------------------------------------------
    public function closeRequisition(Request $r, string $uuid)
    {
        $req = app(HiringScope::class)->requisitions($r->user(), 'recruitment.manage')->where('uuid', $uuid)->firstOrFail();
        abort_unless(in_array($req->status, ['pending', 'open'], true), 409, 'Only pending or open requisitions can be closed.');
        $req->update(['status' => 'closed', 'closed_at' => now()]);
        Posting::where('requisition_id', $req->id)->where('status', 'published')->update(['status' => 'closed']);
        Audit::record('requisition.closed', 'recruitment', $req->uuid);

        return ['uuid' => $req->uuid, 'status' => $req->status];
    }

    // ---- postings --------------------------------------------------------------------------------------------------------------------
    public function postings(Request $r, HiringScope $scope)
    {
        return Posting::whereIn('requisition_id', $scope->requisitions($r->user())->select('id'))->with('requisition')->latest('id')->paginate(25)
            ->through(fn ($p) => ['uuid' => $p->uuid, 'requisition_uuid' => $p->requisition->uuid, 'title' => $p->title, 'channel' => $p->channel, 'status' => $p->status, 'published_at' => $p->published_at, 'closes_on' => $p->closes_on?->toDateString()]);
    }

    public function createPosting(Request $r, HiringScope $scope)
    {
        $d = $r->validate(['requisition_uuid' => 'required|uuid', 'title' => 'required|string|max:160', 'description' => 'required|string|max:20000', 'channel' => ['required', Rule::in(['internal', 'external', 'referral'])],
            'location_text' => 'nullable|string|max:160', 'closes_on' => 'nullable|date_format:Y-m-d|after:today']);
        $req = $scope->requisitions($r->user(), 'recruitment.manage')->where('uuid', $d['requisition_uuid'])->firstOrFail();
        unset($d['requisition_uuid']);
        $p = Posting::create($d + ['requisition_id' => $req->id, 'created_by' => $r->user()->id]);
        Audit::record('posting.created', 'recruitment', $p->uuid);

        return response()->json(['uuid' => $p->uuid], 201);
    }

    public function publishPosting(Request $r, string $uuid, HiringScope $scope)
    {
        return $this->postingState($r, $uuid, $scope, 'published');
    }

    public function closePosting(Request $r, string $uuid, HiringScope $scope)
    {
        return $this->postingState($r, $uuid, $scope, 'closed');
    }

    private function postingState(Request $r, string $uuid, HiringScope $scope, string $to)
    {
        $p = Posting::where('uuid', $uuid)->whereIn('requisition_id', $scope->requisitions($r->user(), 'recruitment.manage')->select('id'))->with('requisition')->firstOrFail();
        if ($to === 'published') {
            abort_unless($p->status === 'draft', 409, 'Only draft postings can be published.');
            abort_unless($p->requisition->status === 'open', 422, 'Postings can be published only for approved, open requisitions.');
            app(CandidateChannelAdapter::class)->publishPosting(['uuid' => $p->uuid, 'channel' => $p->channel, 'title' => $p->title]);
            $p->update(['status' => 'published', 'published_at' => now()]);
        } else {
            abort_unless(in_array($p->status, ['draft', 'published'], true), 409, 'This posting is already closed.');
            $p->update(['status' => 'closed']);
        }
        Audit::record('posting.'.$to, 'recruitment', $p->uuid);

        return ['uuid' => $p->uuid, 'status' => $p->status];
    }

    // ---- candidates (privacy) --------------------------------------------------------------------------------------------------------
    public function anonymize(Request $r, string $uuid)
    {
        $c = app(HiringScope::class)->candidates($r->user(), 'recruitment.privacy.manage')->where('uuid', $uuid)->firstOrFail();
        abort_if($c->anonymized_at, 409, 'This candidate is already anonymized.');
        abort_if($c->retention_until >= now('UTC')->toDateString() && ! $r->boolean('erasure_request'), 409, 'The retention period has not ended. Confirm an erasure request to anonymize earlier.');
        abort_if(Application::where('candidate_id', $c->id)->whereNotNull('employee_id')->exists(), 409, 'Hired applicants are retained through the employee record.');
        DB::transaction(function () use ($c) {
            $c->update(['name' => 'Anonymized applicant', 'email' => 'anonymized-'.$c->uuid.'@invalid.local', 'email_hash' => hash('sha256', $c->uuid), 'phone' => null, 'consent_reference' => 'removed', 'anonymized_at' => now()]);
            Application::where('candidate_id', $c->id)->where('status', 'active')->update(['status' => 'withdrawn', 'closed_at' => now()]);
            app(\App\Domain\Recruitment\ResumeService::class)->purgeForCandidate($c);
            app(\App\Domain\Recruitment\OfferDocumentService::class)->purgeForCandidate($c);
            Audit::record('candidate.anonymized', 'recruitment', $c->uuid);
        });

        return ['uuid' => $c->uuid, 'anonymized' => true];
    }

    // ---- interviews ------------------------------------------------------------------------------------------------------------------
    public function interviews(Request $r, HiringScope $scope)
    {
        $q = $scope->interviews($r->user())->with('application.candidate')->orderBy('starts_at');
        if ($r->filled('application_uuid')) {
            $q->whereIn('application_id', Application::where('uuid', $r->query('application_uuid'))->select('id'));
        }

        return $q->paginate(25)->through(fn ($i) => $this->interview($i));
    }

    public function scheduleInterview(Request $r, InterviewService $service)
    {
        $d = $r->validate(['application_uuid' => 'required|uuid', 'round' => 'required|string|max:80', 'starts_at' => 'required|date_format:Y-m-d\TH:i', 'ends_at' => 'required|date_format:Y-m-d\TH:i', 'timezone' => 'required|timezone',
            'mode' => ['nullable', Rule::in(['video', 'phone', 'onsite'])], 'location_text' => 'nullable|string|max:255', 'panelist_ids' => 'required|array|min:1|max:10', 'panelist_ids.*' => 'integer']);

        return response()->json(['uuid' => $service->schedule($r->user(), $d)->uuid], 201);
    }

    public function rescheduleInterview(Request $r, string $uuid, InterviewService $service)
    {
        $data = $r->validate(['starts_at' => 'required|date_format:Y-m-d\TH:i', 'ends_at' => 'required|date_format:Y-m-d\TH:i', 'timezone' => 'required|timezone',
            'expected_starts_at' => 'required|date', 'reason' => 'required|string|max:1000']);

        return $this->interview($service->reschedule($r->user(), $uuid, $data));
    }

    public function cancelInterview(Request $r, string $uuid, InterviewService $service)
    {
        return ['uuid' => $service->cancel($r->user(), $uuid)->uuid, 'status' => 'cancelled'];
    }

    public function submitScorecard(Request $r, string $uuid, InterviewService $service)
    {
        $d = $r->validate(['rating' => 'required|integer|between:1,5', 'recommendation' => ['required', Rule::in(['strong_yes', 'yes', 'no', 'strong_no'])], 'strengths' => 'nullable|string|max:4000', 'concerns' => 'nullable|string|max:4000']);
        $service->submitScorecard($r->user(), $uuid, $d);

        return response()->json(['submitted' => true], 201);
    }

    public function scorecards(Request $r, string $uuid, HiringScope $scope, InterviewService $service)
    {
        $i = $scope->interviews($r->user())->where('uuid', $uuid)->firstOrFail();
        Audit::record('scorecards.viewed', 'recruitment', $i->uuid);

        return ['data' => $service->scorecardsFor($r->user(), $i)];
    }

    private function interview(Interview $i): array
    {
        return ['uuid' => $i->uuid, 'application_uuid' => $i->application->uuid, 'candidate_name' => $i->application->candidate->name, 'round' => $i->round, 'starts_at' => $i->starts_at->toIso8601String(), 'ends_at' => $i->ends_at->toIso8601String(),
            'timezone' => $i->timezone, 'mode' => $i->mode, 'status' => $i->status];
    }

    // ---- offers ----------------------------------------------------------------------------------------------------------------------
    public function offers(Request $r, HiringScope $scope)
    {
        $q = Offer::whereIn('application_id', $scope->applications($r->user(), 'recruitment.offer.manage')->select('id'))->with('application.candidate')->latest('id');
        if ($r->filled('application_uuid')) {
            $q->whereIn('application_id', Application::where('uuid', $r->query('application_uuid'))->select('id'));
        }

        return $q->paginate(25)->through(fn ($o) => $this->offer($o, $r->user()));
    }

    public function createOffer(Request $r, OfferService $service)
    {
        $d = $r->validate(['application_uuid' => 'required|uuid', 'job_title' => 'required|string|max:160', 'start_date' => 'required|date_format:Y-m-d|after_or_equal:today', 'expires_on' => 'nullable|date_format:Y-m-d|after_or_equal:today|before_or_equal:start_date',
            'compensation_reference' => 'nullable|string|max:1000', 'currency' => 'nullable|alpha|size:3', 'notes' => 'nullable|string|max:4000', 'benchmark_employee_uuid' => 'nullable|uuid']);
        if (isset($d['currency'])) {
            $d['currency'] = strtoupper($d['currency']);
        }
        $o = $service->create($r->user(), $d['application_uuid'], $d);

        return response()->json(['uuid' => $o->uuid, 'version' => $o->version], 201);
    }

    public function offerAction(Request $r, string $uuid, string $action, OfferService $service)
    {
        $o = match ($action) {
            'submit' => $service->submit($r->user(), $uuid),
            'approve' => $service->decide($r->user(), $uuid, 'approved', $r->validate(['note' => 'nullable|string|max:500'])['note'] ?? null),
            'reject' => $service->decide($r->user(), $uuid, 'rejected', $r->validate(['note' => 'required|string|max:500'])['note']),
            'send' => $service->send($r->user(), $uuid),
            'accept' => $service->respond($r->user(), $uuid, 'accepted'),
            'decline' => $service->respond($r->user(), $uuid, 'declined'),
            'withdraw' => $service->withdraw($r->user(), $uuid),
        };

        return ['uuid' => $o->uuid, 'status' => $o->status];
    }

    private function offer(Offer $o, User $viewer): array
    {
        return ['uuid' => $o->uuid, 'application_uuid' => $o->application->uuid, 'candidate_name' => $o->application->candidate->name, 'version' => $o->version, 'status' => $o->status, 'job_title' => $o->job_title,
            'start_date' => $o->start_date->toDateString(), 'expires_on' => $o->expires_on?->toDateString(), 'currency' => $o->currency,
            'compensation_reference' => $viewer->allows('recruitment.offer.manage') || $viewer->allows('recruitment.offer.approve') ? $o->compensation_reference : null,
            'can_review' => $o->status === 'pending_approval' && $o->created_by !== $viewer->id && $viewer->allows('recruitment.offer.approve')];
    }

    // ---- handover --------------------------------------------------------------------------------------------------------------------
    public function handover(Request $r, string $uuid, HandoverService $service)
    {
        $d = $r->validate(['employee_number' => 'nullable|string|max:40', 'work_email' => 'nullable|email|max:254', 'worker_type' => ['nullable', Rule::in(['employee', 'contractor', 'intern'])], 'probation_applicable' => 'boolean', 'date_of_birth' => 'nullable|date_format:Y-m-d|before:today',
            'manager_uuid' => 'nullable|uuid', 'confirm_duplicates' => 'boolean',
            'previous_employee_uuid' => 'nullable|uuid', 'rehire_identity_confirmed' => 'exclude_without:previous_employee_uuid|required|accepted', 'override_reason' => 'nullable|string|max:1000',
            'assignment' => 'required|array', 'assignment.department_id' => 'required|integer', 'assignment.designation_id' => 'required|integer', 'assignment.employee_type_id' => 'required|integer', 'assignment.location_id' => 'required|integer',
            'assignment.legal_entity_id' => 'nullable|integer', 'assignment.position_id' => 'nullable|integer', 'assignment.grade_id' => 'nullable|integer', 'assignment.level_id' => 'nullable|integer', 'assignment.work_mode' => ['nullable', Rule::in(['onsite', 'remote', 'hybrid'])]]);
        $h = $service->handover($r->user(), $uuid, $d);
        $employee = Employee::findOrFail($h->employee_id);

        return response()->json(['employee_uuid' => $employee->uuid, 'employee_number' => $employee->employee_number, 'status' => $employee->status], 201);
    }

    // ---- analytics -------------------------------------------------------------------------------------------------------------------
    public function analytics(Request $r, PipelineAnalytics $analytics)
    {
        return $analytics->summary($r->user());
    }
}
