<?php

namespace App\Domain\Workflow;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Turns a plain-language process description (English, Tamil, or Tanglish) into a DRAFT workflow spec.
 *
 * This is a deterministic, keyword-based interpreter. It uses no AI model, so it understands a limited vocabulary and says so: every step
 * reports the words it matched, anything it could not resolve (usually who is responsible) is returned as a question, and the result is only
 * a draft. Saving still goes through WorkflowService::draft (validation) and publishing still needs a second administrator. The
 * interpreter name is stored with each generation so a model-based interpreter can be added behind the same output contract and compared.
 */
final class WorkflowCompiler
{
    public const INTERPRETER = 'rules-v1';

    private const DEFAULT_TIMEOUT_HOURS = 72;

    private const TRIGGERS = [
        ['EmployeeSeparated', ['resign', 'resigns', 'resigned', 'exit', 'leaves the company', 'separated', 'separation', 'terminat', 'ராஜினாமா', 'rajinama', 'relieve']],
        ['EmployeeRehired', ['rehire', 'rehired', 're-hire']],
        ['EmployeePromoted', ['promoted', 'promotion', 'பதவி உயர்வு', 'pathavi uyarvu']],
        ['EmployeeTransferred', ['transferred', 'transfer', 'இடமாற்றம்', 'idamatram']],
        ['ProbationDue', ['probation', 'confirmation due', 'தகுதிகாண்', 'probation mudiyum']],
        ['DocumentExpiring', ['document expir', 'documents expir', 'expiring document', 'expires', 'passport expir', 'visa expir']],
        ['RecruitmentHired', ['candidate is hired', 'offer accepted', 'recruitment hire', 'hired candidate', 'handover from recruitment']],
        ['leave.request.submitted', ['leave request', 'applies for leave', 'applies leave', 'apply leave', 'leave apply', 'விடுப்பு', 'leave kekkum', 'leave edukkum']],
        ['RegularizationSubmitted', ['regulariz', 'missed punch', 'forgot to punch']],
        ['OvertimeRequested', ['overtime']],
        ['OnDutyRequested', ['on-duty', 'on duty']],
        ['AttendanceExceptionDetected', ['attendance exception', 'absent without', 'late arrival']],
        ['PerformanceReviewFinalized', ['review is finalized', 'review finalised', 'review finalized', 'appraisal complete']],
        ['EmployeeActivated', ['becomes active', 'is activated', 'activated']],
        ['EmployeeCreated', ['joins', 'joined', 'joining', 'new employee', 'new joiner', 'new hire', 'onboard', 'சேரும்', 'serumbodhu', 'join pannumbodhu', 'join pannum', 'join aagum']],
    ];

    private const ACTIONS = [
        'wait' => ['wait', 'pause', 'hold for', 'காத்திரு', 'kaathiru', 'kathiru'],
        'approval' => ['approve', 'approval', 'approves', 'sign off', 'sign-off', 'sanction', 'ஒப்புதல்', 'அனுமதி', 'oppudhal', 'anumathi', 'approve pannu', 'approve pannanum'],
        'notify' => ['notify', 'inform', 'alert', 'intimate', 'let them know', 'send a message', 'தெரியப்படுத்து', 'தெரிவி', 'therivi', 'theriyapadutha', 'inform pannu'],
    ];

    private const MANAGER = ['reporting manager', 'line manager', 'manager', 'supervisor', 'team lead', 'மேலாளர்', 'melalar', 'manager kitta'];

    private const GENERIC_ROLE_WORDS = ['team', 'workspace', 'member', 'administrator', 'admin'];

    private const SEPARATORS = '/\s*(?:\R+|;|\s+(?:then|and then|after that|afterwards|next|appuram|apram|aprm|pinne|பிறகு|அப்புறம்|பின்னர்)\s+|,\s*(?:then\s+)?)\s*/iu';

    public function compile(User $viewer, string $text): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', preg_replace('/^\s*(?:\d+[\).]|[-*•])\s*/mu', '', str_replace("\r", '', $text))));
        $people = $this->participants($viewer);
        $clauses = array_values(array_filter(array_map('trim', preg_split(self::SEPARATORS, $this->normalizeBreaks($text)) ?: []), fn ($c) => mb_strlen($c) > 2));
        $warnings = [];
        $trigger = ['type' => 'manual'];
        $triggerSource = null;
        if ($clauses && $this->isTriggerClause($clauses[0])) {
            [$trigger, $matched] = $this->detectTrigger($clauses[0]);
            $triggerSource = ['clause' => $clauses[0], 'matched' => $matched];
            array_shift($clauses);
        }
        if ($trigger['type'] === 'manual') {
            $warnings[] = 'No start event was recognised, so the workflow starts manually. Pick an event if it should start automatically.';
        }
        if (count($clauses) > 25) {
            $warnings[] = 'Only the first 25 steps were kept.';
            $clauses = array_slice($clauses, 0, 25);
        }
        $steps = [];
        $questions = [];
        foreach ($clauses as $i => $clause) {
            $step = $this->step($clause, $people);
            foreach ($step['issues'] as $issue) {
                $questions[] = ['step' => $i + 1, 'question' => $issue['question'], 'candidates' => $issue['candidates'] ?? []];
            }
            $steps[] = $step;
        }
        if (! $steps) {
            $warnings[] = 'No steps were found. Describe each action, for example: "IT assigns a laptop, then the manager approves, then notify HR".';
        }
        $spec = ['trigger' => $trigger, 'steps' => array_map(fn ($s) => $s['spec'], $steps)];

        return ['interpreter' => self::INTERPRETER, 'language' => $this->language($text), 'name_suggestion' => Str::limit(Str::title(Str::of($text)->before(',')->limit(60, '')->toString()), 100, ''),
            'spec' => $spec, 'trigger_source' => $triggerSource,
            'steps' => array_map(fn ($s) => ['title' => $s['spec']['title'], 'action' => $s['spec']['action'], 'matched' => $s['matched'], 'assignee_names' => $s['assignee_names'], 'confidence' => $s['confidence']], $steps),
            'questions' => $questions, 'warnings' => $warnings, 'ready_to_save' => $steps !== [] && $questions === [],
            'limitations' => 'Generated by a keyword-based interpreter without an AI model. It understands approvals, tasks, notifications, waits, managers and named people or roles. Review every step before saving. Saving creates a draft only; a second administrator must publish it.'];
    }

    private function normalizeBreaks(string $text): string
    {
        return preg_replace('/(?<=[.!?])\s+(?=[A-Z])/u', "\n", $text);
    }

    private function language(string $text): string
    {
        if (preg_match('/\p{Tamil}/u', $text)) {
            return 'tamil';
        }
        if (preg_match('/\b(pannu|pannum|pannanum|kitta|apram|appuram|aprm|naal|irukku|vendum)\b/i', $text)) {
            return 'tanglish';
        }

        return 'english';
    }

    private function isTriggerClause(string $clause): bool
    {
        return (bool) preg_match('/^(when|whenever|once|as soon as|on|if|after)\b/iu', $clause) || (bool) preg_match('/(pothu|pothum|போது|pannumbodhu|aagumbodhu|serumbodhu)\s*$/iu', $clause);
    }

    /** @return array{0:array,1:?string} trigger spec and the words that matched */
    private function detectTrigger(string $clause): array
    {
        $lower = mb_strtolower($clause);
        foreach (self::TRIGGERS as [$event, $words]) {
            foreach ($words as $w) {
                if (str_contains($lower, mb_strtolower($w))) {
                    return [['type' => 'event', 'event_type' => $event], $w];
                }
            }
        }

        return [['type' => 'manual'], null];
    }

    private function step(string $clause, array $people): array
    {
        $lower = mb_strtolower($clause);
        $action = 'task';
        $matched = [];
        foreach (self::ACTIONS as $kind => $words) {
            foreach ($words as $w) {
                if (str_contains($lower, mb_strtolower($w))) {
                    $action = $kind;
                    $matched[] = $w;
                    break 2;
                }
            }
        }
        $assignment = 'fixed';
        $ids = [];
        $names = [];
        foreach (self::MANAGER as $w) {
            if (str_contains($lower, mb_strtolower($w))) {
                $assignment = 'manager';
                $names[] = "the employee's manager";
                $matched[] = $w;
                break;
            }
        }
        $issues = [];
        if ($assignment === 'fixed' && $action !== 'wait') {
            $found = $this->resolvePeople($lower, $people);
            if ($found['ambiguous']) {
                $issues[] = ['question' => 'More than 3 people match "'.$found['label'].'". Choose who should be responsible.', 'candidates' => $found['ambiguous']];
            } elseif ($found['users']) {
                $ids = array_column($found['users'], 'id');
                $names = array_column($found['users'], 'name');
                $matched[] = $found['label'];
            } else {
                $issues[] = ['question' => 'Who is responsible for "'.Str::limit($clause, 70).'"? No matching person or role was found.', 'candidates' => array_slice(array_map(fn ($p) => ['id' => $p['id'], 'name' => $p['name']], $people), 0, 10)];
            }
        }
        $hours = $this->duration($lower);
        $timeout = $hours ?? self::DEFAULT_TIMEOUT_HOURS;
        if ($action === 'wait' && $hours === null) {
            $issues[] = ['question' => 'How long should the workflow wait? Add a duration such as "2 days".'];
        }
        $title = Str::limit(trim(preg_replace('/^(and|then|also|pinne|apram)\s+/iu', '', $clause), " .,"), 150, '');

        return ['spec' => ['title' => Str::ucfirst($title), 'action' => $action, 'assignment' => $assignment, 'assignees' => $ids, 'mode' => 'any', 'timeout_hours' => $timeout],
            'matched' => array_values(array_unique(array_filter($matched))), 'assignee_names' => $names, 'issues' => $issues,
            'confidence' => $issues ? 'needs_input' : ($matched ? 'high' : 'medium')];
    }

    private function duration(string $lower): ?int
    {
        if (preg_match('/(\d{1,4})\s*(hours?|hrs?|hr|மணி|mani|days?|நாள்|naal|naalu|weeks?|வாரம்|vaaram|months?)\b/iu', $lower, $m)) {
            $n = (int) $m[1];
            $u = mb_strtolower($m[2]);
            $hours = match (true) {
                str_starts_with($u, 'h'), $u === 'hr', $u === 'மணி', $u === 'mani' => $n,
                str_starts_with($u, 'd'), $u === 'நாள்', str_starts_with($u, 'naal') => $n * 24,
                str_starts_with($u, 'w'), $u === 'வாரம்', $u === 'vaaram' => $n * 24 * 7,
                default => $n * 24 * 30,
            };

            return max(1, min(8760, $hours));
        }

        return null;
    }

    private function participants(User $viewer): array
    {
        return User::where('tenant_id', $viewer->tenant_id ?? app(TenantContext::class)->id)->where('active', true)
            ->whereHas('roles.permissions', fn ($q) => $q->where('key', 'workflows.act'))->with('roles:id,name')->limit(300)->get()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'roles' => $u->roles->pluck('name')->all()])->all();
    }

    /** Matches people by their name first, then by role name (or its distinctive first word, for example "HR"). */
    private function resolvePeople(string $lower, array $people): array
    {
        $named = array_values(array_filter($people, fn ($p) => mb_strlen($p['name']) > 2 && preg_match('/\b'.preg_quote(mb_strtolower($p['name']), '/').'\b/u', $lower)));
        if ($named) {
            return ['users' => array_map(fn ($p) => ['id' => $p['id'], 'name' => $p['name']], array_slice($named, 0, 10)), 'ambiguous' => [], 'label' => 'name'];
        }
        $roles = [];
        foreach ($people as $p) {
            foreach ($p['roles'] as $role) {
                $roles[$role][] = $p;
            }
        }
        foreach ($roles as $role => $members) {
            $words = array_values(array_filter(preg_split('/\s+/u', mb_strtolower($role)), fn ($w) => mb_strlen($w) >= 2 && ! in_array($w, self::GENERIC_ROLE_WORDS, true)));
            $hit = preg_match('/\b'.preg_quote(mb_strtolower($role), '/').'\b/u', $lower) || ($words && preg_match('/\b'.preg_quote($words[0], '/').'\b/u', $lower));
            if (! $hit || mb_strtolower($role) === 'manager') {
                continue;
            }
            if (count($members) > 3) {
                return ['users' => [], 'ambiguous' => array_map(fn ($p) => ['id' => $p['id'], 'name' => $p['name']], array_slice($members, 0, 10)), 'label' => $role];
            }

            return ['users' => array_map(fn ($p) => ['id' => $p['id'], 'name' => $p['name']], $members), 'ambiguous' => [], 'label' => $role];
        }

        return ['users' => [], 'ambiguous' => [], 'label' => ''];
    }
}
