<?php

namespace App\Domain\Quality;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantRunner;
use App\Domain\Work\TaskService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Data-quality engine: a registry of QualityRules and the scan that turns their output into data_quality_findings.
 *
 * Scan semantics (per tenant, per rule):
 *  - a finding is identified by (tenant, rule, fingerprint); a new one is inserted as "open";
 *  - a finding that is still detected has last_seen_at refreshed and its descriptive fields updated, but keeps its status;
 *  - an open/acknowledged finding that is NOT detected any more is auto-resolved ("No longer detected") and its My Work
 *    task is completed;
 *  - a resolved finding that is detected again is reopened;
 *  - a dismissed finding is never touched (a "not a duplicate" decision must survive every future scan);
 *  - if a rule throws, nothing is auto-resolved for that rule (a half-finished scan must not close real problems);
 *  - the engine only ever writes data_quality_findings. It never corrects HR data.
 */
final class DataQualityEngine
{
    public const RESOLUTION_AUTO = 'No longer detected';

    /** @var array<int,QualityRule|string> */
    private array $registered = [];

    public function register(QualityRule|string $rule): void
    {
        $this->registered[] = $rule;
    }

    /** @return array<string,QualityRule> rules keyed by key(); a later registration replaces an earlier one with the same key. */
    public function rules(): array
    {
        $out = [];
        foreach ($this->registered as $i => $rule) {
            if (is_string($rule)) {
                $rule = $this->registered[$i] = app($rule);
            }
            $out[$rule->key()] = $rule;
        }

        return $out;
    }

    /**
     * @param  array<int,string>|null  $only  restrict to these rule keys
     * @return array{rules:int,created:int,reopened:int,resolved:int,detected:int,errors:array<string,string>,skipped?:bool}
     */
    public function scan(?int $tenantId = null, ?array $only = null): array
    {
        $tenantId ??= app(TenantContext::class)->id;
        abort_unless($tenantId, 422, 'Tenant context is required.');
        $lock = Cache::lock('people-quality-scan:'.$tenantId, 900);
        if (! $lock->get()) {
            return ['rules' => 0, 'created' => 0, 'reopened' => 0, 'resolved' => 0, 'detected' => 0, 'errors' => [], 'skipped' => true];
        }
        try {
            return app(TenantRunner::class)->run($tenantId, function () use ($tenantId, $only) {
                $summary = ['rules' => 0, 'created' => 0, 'reopened' => 0, 'resolved' => 0, 'detected' => 0, 'errors' => []];
                foreach ($this->rules() as $key => $rule) {
                    if ($only !== null && ! in_array($key, $only, true)) {
                        continue;
                    }
                    try {
                        $result = $this->scanRule($rule, $tenantId);
                        $summary['rules']++;
                        foreach (['created', 'reopened', 'resolved', 'detected'] as $k) {
                            $summary[$k] += $result[$k];
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Data quality rule failed', ['rule' => $key, 'tenant' => $tenantId, 'error' => $e->getMessage()]);
                        $summary['errors'][$key] = $e->getMessage();
                    }
                }

                return $summary;
            });
        } finally {
            $lock->release();
        }
    }

    /** @return array{created:int,reopened:int,resolved:int,detected:int} */
    private function scanRule(QualityRule $rule, int $tenantId): array
    {
        $key = $rule->key();
        $now = now();
        $existing = DB::table('data_quality_findings')->where('tenant_id', $tenantId)->where('rule', $key)->get()->keyBy('fingerprint');
        $seen = [];
        $inserts = [];
        $touch = [];
        $result = ['created' => 0, 'reopened' => 0, 'resolved' => 0, 'detected' => 0];

        foreach ($rule->scan($tenantId) as $finding) {
            $fingerprint = strlen($finding->fingerprint) > 120 ? 'h:'.sha1($finding->fingerprint) : $finding->fingerprint;
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;
            $result['detected']++;
            $fields = [
                'category' => $rule->category(), 'severity' => $finding->severity, 'employee_id' => $finding->employeeId, 'subject_type' => $finding->subject[0] ?? null,
                'subject_id' => $finding->subject[1] ?? null, 'title' => mb_substr($finding->title, 0, 250), 'detail' => $finding->detail, 'classification' => $finding->classification,
            ];
            $details = $finding->details ?: null;
            $row = $existing[$fingerprint] ?? null;

            if (! $row) {
                $inserts[] = $fields + ['tenant_id' => $tenantId, 'rule' => $key, 'fingerprint' => $fingerprint, 'status' => 'open', 'detected_at' => $now, 'last_seen_at' => $now,
                    'details' => $details === null ? null : json_encode($details), 'created_at' => $now, 'updated_at' => $now];
                $result['created']++;
                if (count($inserts) >= 200) {
                    DB::table('data_quality_findings')->insert($inserts);
                    $inserts = [];
                }

                continue;
            }
            if ($row->status === 'dismissed') {
                continue; // never touched
            }
            if ($row->status === 'resolved') {
                DB::table('data_quality_findings')->where('id', $row->id)->update($fields + ['status' => 'open', 'detected_at' => $now, 'last_seen_at' => $now, 'resolved_at' => null,
                    'resolution' => null, 'acknowledged_at' => null, 'details' => $details === null ? null : json_encode($details), 'updated_at' => $now]);
                $result['reopened']++;

                continue;
            }
            $changed = $this->differs($row, $fields, $details);
            if ($changed) {
                DB::table('data_quality_findings')->where('id', $row->id)->update($fields + ['last_seen_at' => $now, 'details' => $details === null ? null : json_encode($details), 'updated_at' => $now]);
            } else {
                $touch[] = $row->id;
            }
        }
        if ($inserts) {
            DB::table('data_quality_findings')->insert($inserts);
        }
        foreach (array_chunk($touch, 500) as $ids) {
            DB::table('data_quality_findings')->whereIn('id', $ids)->update(['last_seen_at' => $now]);
        }

        // Auto-resolve what is no longer detected (only reached when the rule completed without throwing).
        $gone = $existing->filter(fn ($row) => in_array($row->status, DataQualityFinding::ACTIVE, true) && ! isset($seen[$row->fingerprint]));
        if ($gone->isNotEmpty()) {
            foreach ($gone->pluck('id')->chunk(500) as $ids) {
                DB::table('data_quality_findings')->whereIn('id', $ids->all())->update(['status' => 'resolved', 'resolved_at' => $now, 'resolution' => self::RESOLUTION_AUTO, 'updated_at' => $now]);
            }
            $tasks = app(TaskService::class);
            foreach ($gone->whereNotNull('assignee_id') as $row) {
                $tasks->completeBySource('people', 'quality:'.$row->id);
            }
            $result['resolved'] = $gone->count();
        }

        return $result;
    }

    private function differs(object $row, array $fields, ?array $details): bool
    {
        foreach ($fields as $column => $value) {
            if ((string) ($row->{$column} ?? '') !== (string) ($value ?? '')) {
                return true;
            }
        }
        $stored = is_string($row->details) ? json_decode($row->details, true) : $row->details;

        return ($stored ?: null) != ($details ?: null);
    }
}
