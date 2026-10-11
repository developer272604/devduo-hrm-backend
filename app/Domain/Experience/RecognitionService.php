<?php

namespace App\Domain\Experience;

use App\Domain\Audit\Audit;
use App\Domain\Access\DataScope;
use App\Domain\Experience\Models\{Recognition, RecognitionPreference};
use App\Domain\People\Models\Employee;
use App\Models\User;
use App\Domain\Notifications\NotificationService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RecognitionService
{
    public const VALUES = ['teamwork', 'customer_care', 'ownership', 'innovation', 'inclusion'];

    public function __construct(private DataScope $scope) {}

    public function feed(User $viewer)
    {
        return Recognition::where('status', 'approved')
            ->whereNotIn('recipient_employee_id', RecognitionPreference::where('opted_out', true)->select('employee_id'))
            ->latest('id');
    }

    public function pending()
    {
        return Recognition::where('status', 'pending')->latest('id');
    }

    public function recipients(User $viewer, string $search): array
    {
        $ownIds = $this->scope->ownEmployeeIds($viewer);
        if (! $ownIds) abort(403, 'Only employees may give recognition.');
        return Employee::with('person')->whereIn('status', Employee::WORKING)->whereNotIn('id', $ownIds)
            ->whereNotIn('id', RecognitionPreference::where('opted_out', true)->select('employee_id'))
            ->whereHas('person', fn ($q) => $q->where('legal_first_name', 'like', '%'.$search.'%')->orWhere('legal_last_name', 'like', '%'.$search.'%'))
            ->orderBy('id')->limit(25)->get()->map(fn ($employee) => ['uuid' => $employee->uuid,
                'name' => trim(($employee->person?->legal_first_name ?? '').' '.($employee->person?->legal_last_name ?? ''))])->all();
    }

    public function present(Recognition $recognition): array
    {
        $employee = Employee::with('person')->find($recognition->recipient_employee_id);
        return ['uuid' => $recognition->uuid, 'message' => $recognition->message, 'recipient' => ['uuid' => $employee?->uuid,
            'name' => trim(($employee?->person?->legal_first_name ?? '').' '.($employee?->person?->legal_last_name ?? ''))],
            'value_tags' => $recognition->value_tags ?? [], 'reward_reference' => $recognition->reward_reference,
            'status' => $recognition->status, 'created_at' => $recognition->created_at?->toIso8601String()];
    }

    public function create(User $actor, array $data): Recognition
    {
        $senderIds = $this->scope->ownEmployeeIds($actor);
        if (! $senderIds) abort(403, 'Only employees may give recognition.');
        $recipient = Employee::where('uuid', $data['recipient_uuid'])->firstOrFail();
        if (in_array($recipient->id, $senderIds, true)) throw ValidationException::withMessages(['recipient_uuid' => 'You cannot recognize yourself.']);
        if (RecognitionPreference::where('employee_id', $recipient->id)->where('opted_out', true)->exists()) {
            throw ValidationException::withMessages(['recipient_uuid' => 'This employee has opted out of recognition.']);
        }
        $recognition = Recognition::create(['uuid' => (string) Str::uuid(), 'sender_id' => $actor->id, 'recipient_employee_id' => $recipient->id,
            'message' => $data['message'], 'value_tags' => $data['value_tags'], 'reward_reference' => $data['reward_reference'] ?? null, 'status' => 'pending']);
        Audit::record('recognition.submitted', 'experience', 'recognition:'.$recognition->uuid, ['value_tags' => $data['value_tags']]);
        return $recognition;
    }

    public function moderate(Recognition $recognition, User $actor, string $decision): Recognition
    {
        if ($recognition->status !== 'pending') throw ValidationException::withMessages(['status' => 'Only pending recognition can be moderated.']);
        $recognition->update(['status' => $decision, 'moderated_by' => $actor->id, 'moderated_at' => now()]);
        if ($decision === 'approved' && ! RecognitionPreference::where('employee_id', $recognition->recipient_employee_id)->where('opted_out', true)->exists()) {
            $recipientUserId = Employee::with('person')->find($recognition->recipient_employee_id)?->person?->user_id;
            if ($recipientUserId) app(NotificationService::class)->send((int) $recipientUserId, 'You received recognition', 'A new recognition is available in your employee feed.', 'experience.recognition', '/experience/recognition');
        }
        Audit::record('recognition.'.$decision, 'experience', 'recognition:'.$recognition->uuid);
        return $recognition->refresh();
    }

    public function preference(User $actor, bool $optedOut): RecognitionPreference
    {
        $employeeId = $this->scope->ownEmployeeIds($actor)[0] ?? abort(403, 'Only employees may update this preference.');
        $preference = RecognitionPreference::firstOrNew(['employee_id' => $employeeId]);
        $preference->opted_out = $optedOut;
        $preference->save();
        Audit::record($optedOut ? 'recognition.opted_out' : 'recognition.opted_in', 'experience', 'employee:'.$employeeId);
        return $preference->refresh();
    }

    public function currentPreference(User $actor): bool
    {
        $employeeId = $this->scope->ownEmployeeIds($actor)[0] ?? null;
        return $employeeId ? RecognitionPreference::where('employee_id', $employeeId)->where('opted_out', true)->exists() : false;
    }
}
