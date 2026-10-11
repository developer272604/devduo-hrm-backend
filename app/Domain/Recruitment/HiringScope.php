<?php

namespace App\Domain\Recruitment;

use App\Domain\Access\DataScope;
use App\Domain\Recruitment\Models\{Requisition, Application, Candidate, Panelist, Interview};
use App\Models\User;

/** Hiring-team data scope: tenant-wide holders, or recruiter / hiring manager / creator of the requisition, or an interview panelist for that application. */
final class HiringScope
{
    public function requisitions(User $actor, string $permission = 'recruitment.view')
    {
        $q = Requisition::query();
        if (!app(DataScope::class)->hasTenantWide($actor, $permission)) $q->where(fn ($q) => $q->where('recruiter_id', $actor->id)->orWhere('hiring_manager_id', $actor->id)->orWhere('created_by', $actor->id));
        if (!$actor->allows('recruitment.confidential')) $q->where(fn ($q) => $q->where('confidential', false)->orWhere('recruiter_id', $actor->id)->orWhere('hiring_manager_id', $actor->id)->orWhere('created_by', $actor->id));
        return $q;
    }

    public function applications(User $actor, string $permission = 'recruitment.view')
    {
        $q = Application::whereIn('requisition_id', $this->requisitions($actor, $permission)->select('id'));
        if ($permission !== 'recruitment.view') return $q;
        return Application::where(fn ($w) => $w->whereIn('requisition_id', $this->requisitions($actor, $permission)->select('id'))
            ->orWhereIn('id', Interview::whereIn('id', Panelist::where('user_id', $actor->id)->select('interview_id'))->select('application_id')));
    }

    public function candidates(User $actor, string $permission = 'recruitment.view')
    {
        return Candidate::where(fn ($q) => $q->where('created_by', $actor->id)->orWhereIn('id', $this->applications($actor, $permission)->select('candidate_id')));
    }

    /** Interviews the actor may see: via application scope (view) restricted for pure panelists to those they sit on. */
    public function interviews(User $actor)
    {
        $team = Application::whereIn('requisition_id', $this->requisitions($actor)->select('id'))->select('id');
        return Interview::where(fn ($q) => $q->whereIn('application_id', $team)->orWhereIn('id', Panelist::where('user_id', $actor->id)->select('interview_id')));
    }
}
