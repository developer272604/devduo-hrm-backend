<?php

namespace App\Domain\Payroll\Security;

use App\Domain\Audit\Audit;
use App\Domain\Payroll\Operations\PayrollOps;
use App\Domain\Payroll\Support\PayrollSettings;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purpose-based access and the append-only sensitive-access log for compensation / payroll result / payslip / export data.
 * A viewer with the route permission sees figures only when (a) they hold payroll.compensation.reveal (compensation) or
 * payroll.results.view (results) and (b) a purpose is stated when privacy.reveal_requires_purpose is on. Otherwise values are masked (null).
 * Every figure reveal is logged with who, what, which employee, why. Logs never contain amounts.
 */
final class SensitiveAccess
{
    public const PURPOSES = ['payroll_processing', 'compensation_review', 'approval', 'audit', 'employee_query', 'investigation', 'other'];

    /** @return array{0:?string,1:?string} [code, note] from the request (purpose_code / purpose_note). */
    public static function purpose(?Request $r = null): array
    {
        $r ??= request();
        $code = $r->input('purpose_code');
        $note = $r->input('purpose_note');

        return [in_array($code, self::PURPOSES, true) ? $code : null, is_string($note) ? mb_substr(trim($note), 0, 300) : null];
    }

    public static function purposeStated(?Request $r = null): bool
    {
        [$code, $note] = self::purpose($r);

        return $code !== null && ($code !== 'other' || mb_strlen((string) $note) >= 10);
    }

    public static function purposeRequired(): bool
    {
        return (bool) PayrollSettings::get('privacy.reveal_requires_purpose', true);
    }

    /** Validation failure when the endpoint reveals figures and no purpose was stated. */
    public static function demandPurpose(?Request $r = null): void
    {
        if (self::purposeRequired() && ! self::purposeStated($r)) {
            throw ValidationException::withMessages(['purpose_code' => 'State why you need this data: choose a purpose ('.implode(', ', self::PURPOSES).'); "other" needs a note of at least 10 characters.']);
        }
    }

    /** Figures visible = holds the permission and a purpose is stated (when required). */
    public static function canSeeFigures(User $user, string $permission, ?Request $r = null): bool
    {
        return $user->allows($permission) && (! self::purposeRequired() || self::purposeStated($r));
    }

    public static function log(User $user, ?int $employeeId, string $dataType, string $action, bool $revealed, ?string $surface = null, ?string $ref = null, ?Request $r = null): void
    {
        [$code, $note] = self::purpose($r);
        DB::table('payroll_sensitive_access_logs')->insert(['tenant_id' => PayrollOps::tenantId(), 'user_id' => $user->id, 'employee_id' => $employeeId, 'data_type' => $dataType, 'action' => $action,
            'purpose_code' => $code, 'purpose_note' => $note, 'revealed' => $revealed, 'surface' => $surface, 'record_ref' => $ref, 'created_at' => now()]);
        Audit::record('payroll.sensitive_'.$action, 'payroll', $dataType, ['employee_id' => $employeeId, 'revealed' => $revealed, 'purpose' => $code, 'surface' => $surface]);
    }
}
