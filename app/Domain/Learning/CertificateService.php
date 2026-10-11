<?php

namespace App\Domain\Learning;

use App\Domain\Audit\Audit;
use App\Domain\Learning\Models\{Certification, Course, EmployeeCert, Enrolment};
use App\Domain\Notifications\NotificationService;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Work\TaskService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Certification definitions, issue on completion/pass, expiry + renewal reminders (idempotent) and a printable HTML certificate. */
final class CertificateService
{
    public const DEFAULT_REMINDERS = [60, 30, 7];

    public function __construct(private LearningAccess $access) {}

    public function presentDefinition(Certification $c): array
    {
        return ['uuid' => $c->uuid, 'code' => $c->code, 'name' => $c->name, 'issuer' => $c->issuer, 'validity_months' => $c->validity_months, 'active' => (bool) $c->active, 'reminder_days' => $c->reminder_days ?? self::DEFAULT_REMINDERS,
            'renewal_course' => $c->renewal_course_id ? Course::whereKey($c->renewal_course_id)->first(['uuid', 'code', 'title']) : null];
    }

    public function saveDefinition(User $actor, array $d, ?Certification $c = null): Certification
    {
        if (! $c && Certification::where('code', $d['code'])->exists()) throw ValidationException::withMessages(['code' => 'A certification with this code already exists.']);
        $data = array_intersect_key($d, array_flip(['code', 'name', 'issuer', 'validity_months', 'active']));
        if (array_key_exists('renewal_course_uuid', $d)) $data['renewal_course_id'] = $d['renewal_course_uuid'] ? Course::where('uuid', $d['renewal_course_uuid'])->firstOrFail()->id : null;
        if (isset($d['reminder_days'])) $data['reminder_days'] = array_values(array_unique(array_map('intval', $d['reminder_days'])));
        $c = $c ? tap($c)->update($data) : Certification::create($data + ['reminder_days' => $d['reminder_days'] ?? self::DEFAULT_REMINDERS, 'active' => $d['active'] ?? true]);
        Audit::record('certification.saved', 'learning', 'certification:'.$c->uuid);

        return $c;
    }

    /** Idempotent per enrolment. A new issue supersedes the employee's earlier active certificate of the same certification (renewal chain). */
    public function issueForEnrolment(Enrolment $e, Course $course): ?EmployeeCert
    {
        if (! $course->certification_id) return null;
        if ($existing = EmployeeCert::where('enrolment_id', $e->id)->first()) return $existing;
        $cert = Certification::findOrFail($course->certification_id);
        $today = TenantClock::today();
        $new = EmployeeCert::create(['employee_id' => $e->employee_id, 'certification_id' => $cert->id, 'issued_on' => $today, 'course_id' => $course->id, 'enrolment_id' => $e->id, 'status' => 'active',
            'expires_on' => $cert->validity_months ? CarbonImmutable::parse($today)->addMonthsNoOverflow($cert->validity_months)->toDateString() : null, 'credential_id' => 'CRT-'.strtoupper(Str::random(10))]);
        foreach (EmployeeCert::where('employee_id', $e->employee_id)->where('certification_id', $cert->id)->whereIn('status', ['active', 'expired'])->where('id', '!=', $new->id)->get() as $old) {
            $old->update(['status' => 'renewed', 'renewed_by_id' => $new->id]);
            app(TaskService::class)->completeBySource('learning', 'cert-renewal:'.$old->uuid);
        }
        Audit::record('certificate.issued', 'learning', 'certificate:'.$new->uuid, ['certification' => $cert->code]);
        if ($uid = $this->access->userIdOf($e->employee_id)) app(NotificationService::class)->send($uid, 'Certificate issued', $cert->name.' was issued to you.', 'learning', '/learning/certificates');

        return $new;
    }

    public function present(EmployeeCert $c, array $people = []): array
    {
        $cert = Certification::find($c->certification_id);
        $days = $c->expires_on ? (int) CarbonImmutable::parse(TenantClock::today())->diffInDays($c->expires_on, false) : null;

        return ['uuid' => $c->uuid, 'certification' => ['code' => $cert?->code, 'name' => $cert?->name, 'issuer' => $cert?->issuer], 'employee' => $people[$c->employee_id] ?? ($this->access->people([$c->employee_id])[$c->employee_id] ?? null),
            'issued_on' => $c->issued_on->format('Y-m-d'), 'expires_on' => $c->expires_on?->format('Y-m-d'), 'days_to_expiry' => $days, 'status' => $c->status, 'credential_id' => $c->credential_id];
    }

    /** Daily job (tenant context set by the caller): mark expired, send one reminder per threshold crossed, open a renewal task. Re-running the same day changes nothing. */
    public function processReminders(): array
    {
        $today = CarbonImmutable::parse(TenantClock::today());
        $out = ['expired' => 0, 'reminded' => 0];
        $certs = Certification::where('active', true)->get()->keyBy('id');
        foreach (EmployeeCert::where('status', 'active')->whereNotNull('expires_on')->whereIn('certification_id', $certs->keys()->all() ?: [0])->orderBy('id')->get() as $ec) {
            $def = $certs[$ec->certification_id]; $left = (int) $today->diffInDays($ec->expires_on, false);
            $uid = $this->access->userIdOf($ec->employee_id);
            $renewal = $def->renewal_course_id ? Course::whereKey($def->renewal_course_id)->value('title') : null;
            $text = $def->name.($left < 0 ? ' expired on ' : ' expires on ').$ec->expires_on->format('Y-m-d').'.'.($renewal ? ' Renew with "'.$renewal.'".' : '');
            if ($left < 0) {
                $ec->update(['status' => 'expired', 'last_reminded_at' => now()]);
                $out['expired']++;
                if ($uid) {
                    app(NotificationService::class)->send($uid, 'A certification has expired', $text, 'learning', '/learning/certificates');
                    app(TaskService::class)->createOnce(['title' => 'Renew an expired certification', 'description' => $text, 'assignee_id' => $uid, 'source_module' => 'learning', 'source_entity' => 'cert-renewal:'.$ec->uuid, 'action' => '/learning/certificates', 'status' => 'open', 'priority' => 'high']);
                }
                continue;
            }
            $thresholds = collect($def->reminder_days ?: self::DEFAULT_REMINDERS)->map(fn ($d) => (int) $d)->sort()->values();
            $hit = $thresholds->first(fn ($d) => $left <= $d);
            if ($hit === null || ($ec->last_reminder_days !== null && $hit >= (int) $ec->last_reminder_days)) continue;
            $ec->update(['last_reminder_days' => $hit, 'last_reminded_at' => now()]);
            $out['reminded']++;
            if ($uid) {
                app(NotificationService::class)->send($uid, 'A certification expires soon', $text, 'learning', '/learning/certificates');
                app(TaskService::class)->createOnce(['title' => 'Renew a certification', 'description' => $text, 'assignee_id' => $uid, 'source_module' => 'learning', 'source_entity' => 'cert-renewal:'.$ec->uuid, 'action' => '/learning/certificates', 'status' => 'open', 'priority' => $left <= 7 ? 'high' : 'normal']);
            }
        }

        return $out;
    }

    /** Self-contained printable HTML; every value is escaped. */
    public function printHtml(EmployeeCert $c): string
    {
        $p = $this->present($c); $e = fn (?string $v) => e($v ?? '');
        $tenant = \App\Models\Tenant::find($c->tenant_id)?->name;
        $revoked = in_array($c->status, ['revoked'], true) ? '<p class="warn">This certificate has been revoked.</p>' : ($c->status === 'expired' ? '<p class="warn">This certificate has expired.</p>' : '');

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Certificate '.$e($p['credential_id']).'</title>'
            .'<style>body{font-family:Georgia,serif;margin:0;padding:2rem;background:#fff;color:#1e1b2e}.cert{max-width:760px;margin:0 auto;border:6px double #7c46f1;padding:3rem;text-align:center}h1{color:#7c46f1;margin:0 0 .5rem}.name{font-size:2rem;margin:1.5rem 0;border-bottom:1px solid #ccc;display:inline-block;padding:0 2rem}.meta{margin-top:2rem;font-size:.9rem;color:#555}.warn{color:#b45309;font-weight:bold}button{margin-top:1.5rem;padding:.6rem 1.2rem}@media print{button{display:none}body{padding:0}}</style></head><body>'
            .'<div class="cert"><h1>Certificate of completion</h1><p>'.$e($tenant).'</p><p>This certifies that</p><div class="name">'.$e($p['employee']['name'] ?? '').'</div><p>has successfully completed</p><h2>'.$e($p['certification']['name']).'</h2>'
            .($p['certification']['issuer'] ? '<p>Issued by '.$e($p['certification']['issuer']).'</p>' : '').$revoked
            .'<p class="meta">Issued '.$e($p['issued_on']).($p['expires_on'] ? ' · Valid until '.$e($p['expires_on']) : ' · No expiry').'<br>Credential ID '.$e($p['credential_id']).'</p>'
            .'<button onclick="window.print()">Print</button></div></body></html>';
    }
}
