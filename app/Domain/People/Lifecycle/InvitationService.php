<?php

namespace App\Domain\People\Lifecycle;

use App\Domain\Access\Classification;
use App\Domain\Access\PeopleSettings;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Notifications\NotificationService;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\EmployeeInvitation;
use App\Domain\People\Models\Person;
use App\Domain\People\Services\EmployeeService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invites an existing employee (person + employment already exist) to activate a login. The token is random, only its SHA-256 hash is stored,
 * and it is shown nowhere except the invitation message. Accepting creates the user, links it to the existing person and never creates
 * a person or employment record.
 */
final class InvitationService
{
    public const DEFAULT_DAYS = 7;

    public const INELIGIBLE = ['separated', 'archived', 'inactive'];

    public function __construct(private DomainEvents $events, private NotificationService $notifications, private EmployeeService $employees) {}

    /** @return array{0:EmployeeInvitation,1:string} the invitation and the plain token (delivered, never stored) */
    public function invite(User $actor, Employee $employee, ?string $email = null): array
    {
        $this->assertEligible($employee);
        $employee->loadMissing('person');
        $email = mb_strtolower(trim((string) ($email ?: ($employee->work_email ?: $employee->person->personal_email))));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => ['Add a work or personal email address for this employee first, or enter one.']]);
        }
        if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => ['An account with this email address already exists.']]);
        }

        return DB::transaction(function () use ($actor, $employee, $email) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
            $this->expireStale($employee);
            if (EmployeeInvitation::query()->where('employee_id', $employee->id)->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['invitation' => ['An invitation is already pending. Resend or cancel it.']]);
            }
            $token = Str::random(48);
            $row = EmployeeInvitation::query()->create(['employee_id' => $employee->id, 'person_id' => $employee->person_id, 'email' => $email, 'token_hash' => $this->hash($token),
                'status' => 'pending', 'expires_at' => now()->addDays($this->days()), 'invited_by' => $actor->id, 'last_sent_at' => now(), 'resend_count' => 0]);
            Audit::record('invited', 'people', 'employee:'.$employee->uuid, ['invitation' => $row->uuid]);
            $this->events->record('EmployeeInvited', ['employee' => $employee, 'subject' => ['employee_invitation', $row->id], 'classification' => Classification::HR_CONFIDENTIAL,
                'summary' => 'Invited to activate an account.', 'payload' => ['invitation' => $row->uuid]]);
            $this->deliver($row, $employee, $token);

            return [$row, $token];
        });
    }

    /** @return array{0:EmployeeInvitation,1:string} a resend replaces the token (the old link stops working) and restarts the expiry */
    public function resend(User $actor, EmployeeInvitation $invitation): array
    {
        return DB::transaction(function () use ($actor, $invitation) {
            $row = EmployeeInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            abort_if($row->status !== 'pending', 422, 'Only a pending invitation can be resent.');
            $employee = Employee::query()->with('person')->findOrFail($row->employee_id);
            $this->assertEligible($employee);
            $token = Str::random(48);
            $row->fill(['token_hash' => $this->hash($token), 'expires_at' => now()->addDays($this->days()), 'last_sent_at' => now(), 'resend_count' => $row->resend_count + 1])->save();
            Audit::record('invitation_resent', 'people', 'employee:'.$employee->uuid, ['invitation' => $row->uuid]);
            $this->deliver($row, $employee, $token);

            return [$row, $token];
        });
    }

    public function cancel(User $actor, EmployeeInvitation $invitation): EmployeeInvitation
    {
        return DB::transaction(function () use ($invitation) {
            $row = EmployeeInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if ($row->status === 'cancelled') {
                return $row;
            }
            abort_if($row->status !== 'pending', 422, 'Only a pending invitation can be cancelled.');
            $row->update(['status' => 'cancelled']);
            Audit::record('invitation_cancelled', 'people', 'employee_invitation:'.$row->uuid);

            return $row;
        });
    }

    /**
     * Public acceptance. Runs without a session: the tenant comes from the invitation itself. Every failure is the same generic 422
     * so tokens cannot be probed. @return array{user:User,employee:Employee}
     */
    public function accept(string $token, string $name, string $password): array
    {
        $generic = fn () => ValidationException::withMessages(['token' => ['This invitation link is invalid or has expired. Ask HR to send a new one.']]);
        $found = EmployeeInvitation::query()->withoutGlobalScopes()->where('token_hash', $this->hash($token))->first();
        if (! $found) {
            throw $generic();
        }
        $context = app(TenantContext::class);
        $previous = $context->id;
        $context->id = $found->tenant_id;
        try {
            return DB::transaction(function () use ($found, $name, $password, $generic) {
                $row = EmployeeInvitation::query()->whereKey($found->id)->lockForUpdate()->first();
                if (! $row || $row->status !== 'pending') {
                    throw $generic();
                }
                if ($row->expires_at->isPast()) {
                    throw $generic();
                }
                $employee = Employee::query()->with('person')->find($row->employee_id);
                $person = Person::query()->find($row->person_id);
                if (! $employee || ! $person || in_array($employee->status, self::INELIGIBLE, true) || $person->user_id) {
                    throw $generic();
                }
                if (User::query()->whereRaw('lower(email) = ?', [$row->email])->exists()) {
                    throw ValidationException::withMessages(['email' => ['An account with this email address already exists. Contact HR.']]);
                }
                $user = new User(['name' => $person->display_name, 'email' => $row->email, 'password' => $password, 'active' => true]);
                $user->tenant_id = $row->tenant_id;
                $user->email_verified_at = now();
                $user->save();
                $role = Role::query()->where('tenant_id', $row->tenant_id)->where('name', 'Team member')->first();
                if ($role) {
                    $user->roles()->attach($role->id);
                }
                $this->employees->linkUser($person, $user->id);
                $row->update(['status' => 'accepted', 'accepted_at' => now(), 'accepted_user_id' => $user->id]);
                $this->events->record('EmployeeInvitationAccepted', ['employee' => $employee, 'subject' => ['employee_invitation', $row->id], 'actor_id' => $user->id, 'classification' => Classification::HR_CONFIDENTIAL,
                    'summary' => 'Activated their account.', 'payload' => ['invitation' => $row->uuid]]);
                Audit::record('invitation_accepted', 'people', 'employee:'.$employee->uuid, ['invitation' => $row->uuid, 'user_id' => $user->id]);
                if (User::query()->where('tenant_id', $row->tenant_id)->whereKey($row->invited_by)->where('active', true)->exists()) {
                    $this->notifications->send($row->invited_by, 'Invitation accepted', $person->display_name.' activated their account.', 'people', '/employees/'.$employee->uuid);
                }

                return ['user' => $user, 'employee' => $employee];
            });
        } finally {
            $context->id = $previous;
        }
    }

    public function present(EmployeeInvitation $row): array
    {
        $status = $row->status === 'pending' && $row->expires_at->isPast() ? 'expired' : $row->status;

        return ['uuid' => $row->uuid, 'email' => $row->email, 'status' => $status, 'expires_at' => $row->expires_at->toIso8601String(), 'last_sent_at' => $row->last_sent_at?->toIso8601String(),
            'resend_count' => $row->resend_count, 'accepted_at' => $row->accepted_at?->toIso8601String(), 'created_at' => $row->created_at?->toIso8601String()];
    }

    public function assertEligible(Employee $employee): void
    {
        abort_if(in_array($employee->status, self::INELIGIBLE, true), 422, 'Separated, inactive or archived employees cannot be invited.');
        $employee->loadMissing('person');
        abort_if($employee->person->user_id, 422, 'This employee already has an account.');
    }

    /** Eligibility reason for bulk previews (null = eligible). */
    public function blocker(Employee $employee): ?string
    {
        $employee->loadMissing('person');
        if (in_array($employee->status, self::INELIGIBLE, true)) {
            return 'Separated, inactive or archived employees cannot be invited.';
        }
        if ($employee->person->user_id) {
            return 'Already has an account.';
        }
        if (EmployeeInvitation::query()->where('employee_id', $employee->id)->where('status', 'pending')->where('expires_at', '>', now())->exists()) {
            return 'An invitation is already pending.';
        }
        $email = $employee->work_email ?: $employee->person->personal_email;
        if (! $email) {
            return 'No email address on file.';
        }
        if (User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->exists()) {
            return 'An account with this email address already exists.';
        }

        return null;
    }

    private function expireStale(Employee $employee): void
    {
        EmployeeInvitation::query()->where('employee_id', $employee->id)->where('status', 'pending')->where('expires_at', '<=', now())->update(['status' => 'expired']);
    }

    private function days(): int
    {
        return max(1, min(60, (int) PeopleSettings::get('invitations.expiry_days', self::DEFAULT_DAYS)));
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function deliver(EmployeeInvitation $row, Employee $employee, string $token): void
    {
        $url = rtrim((string) config('app.frontend_url', config('app.url')), '/').'/accept-invitation?token='.$token;
        try {
            Mail::raw("Hello {$employee->person->display_name},\n\nYou have been invited to activate your account. Open this link to set your password:\n\n$url\n\nThe link expires on ".$row->expires_at->toDateString().'.', function ($m) use ($row) {
                $m->to($row->email)->subject('Activate your account');
            });
        } catch (\Throwable $e) {
            Log::warning('Invitation email could not be sent: '.$e->getMessage()); // never log the link
        }
    }
}
