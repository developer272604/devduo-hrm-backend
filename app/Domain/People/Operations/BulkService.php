<?php

namespace App\Domain\People\Operations;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\People\Models\BulkRun;
use App\Domain\People\Models\Employee;
use App\Domain\People\Services\EmployeeDirectory;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\People\ProcessBulkRun;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Preview → signed confirmation token → apply (inline up to the queue threshold, queued above it). */
final class BulkService
{
    public function __construct(private BulkActions $actions, private DataScope $scope, private EmployeeDirectory $directory) {}

    /** @return array<string,mixed> */
    public function preview(User $user, string $key, array $params, ?array $uuids, ?array $filters): array
    {
        $action = $this->actions->get($key);
        abort_unless($user->allows('people.bulk') && $user->allows($action->permission()), 403, 'You do not have permission to run this bulk action.');
        $params = $action->validate($params);
        $max = (int) config('people_operations.bulk.max_selection', 10000);

        $missing = [];
        if ($uuids !== null) {
            $uuids = array_values(array_unique($uuids));
            $found = $this->scoped($user)->whereIn('uuid', $uuids)->pluck('id', 'uuid');
            $missing = array_values(array_diff($uuids, $found->keys()->all()));
            $ids = $found->values()->all();
        } else {
            $q = $this->directory->query($user, $filters ?? [], 'manage');
            $this->scope->constrain($q, $user, 'people.manage', 'e.id');
            $ids = $q->limit($max + 1)->pluck('e.id')->map(fn ($i) => (int) $i)->unique()->values()->all();
        }
        abort_if(count($ids) > $max, 422, 'Too many employees selected. Narrow the selection to '.number_format($max).' or fewer.');
        abort_if($ids === [] && $missing === [], 422, 'No employees match this selection.');
        sort($ids);

        $rows = [];
        $counts = ['ok' => 0, 'skipped' => 0, 'error' => 0];
        foreach (array_chunk($ids, 200) as $chunk) {
            $employees = Employee::query()->with('person')->whereIn('id', $chunk)->get();
            $results = $action->preview($user, $employees, $params);
            foreach ($employees as $e) {
                $r = $results[$e->id] ?? ['status' => 'error', 'reason' => 'Could not be checked.'];
                $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
                $rows[] = $this->row($e, $r['status'], $r['reason'] ?? null);
            }
        }
        foreach ($missing as $uuid) {
            $counts['error']++;
            $rows[] = ['uuid' => $uuid, 'employee_number' => null, 'name' => null, 'status' => 'error', 'reason' => 'Employee not found.'];
        }
        usort($rows, fn ($a, $b) => ['error' => 0, 'skipped' => 1, 'ok' => 2][$a['status']] <=> ['error' => 0, 'skipped' => 1, 'ok' => 2][$b['status']]);

        $expires = now()->addMinutes((int) config('people_operations.bulk.token_ttl_minutes', 15));
        $run = BulkRun::create(['user_id' => $user->id, 'action' => $key, 'params' => $params, 'employee_ids' => $ids, 'status' => 'previewed', 'token_expires_at' => $expires, 'total' => count($ids)]);

        return ['action' => $key, 'affected_count' => count($ids), 'counts' => $counts, 'rows' => array_slice($rows, 0, 500), 'rows_truncated' => count($rows) > 500,
            'will_queue' => count($ids) > (int) config('people_operations.bulk.queue_above', 100), 'confirmation_token' => $this->token($run, $user, $expires->getTimestamp()), 'expires_at' => $expires->toIso8601String()];
    }

    public function apply(User $user, string $token): BulkRun
    {
        $run = $this->verify($user, $token);
        $action = $this->actions->get($run->action);
        abort_unless($user->allows('people.bulk') && $user->allows($action->permission()), 403, 'You do not have permission to run this bulk action.');
        $claimed = BulkRun::query()->whereKey($run->id)->where('status', 'previewed')->update(['status' => 'queued']);
        abort_unless($claimed, 409, 'This confirmation was already used. Preview the action again.');
        Audit::record('bulk_started', 'people', 'bulk-run:'.$run->uuid, ['action' => $run->action, 'count' => $run->total, 'params' => $run->params]);
        $run->refresh();
        if ($run->total > (int) config('people_operations.bulk.queue_above', 100)) {
            try {
                ProcessBulkRun::dispatch(app(TenantContext::class)->id, $run->id, $user->id);
            } catch (\Throwable $e) {
                report($e);
            }
        } else {
            $this->execute($run, $user);
        }

        return $run->refresh();
    }

    public function execute(BulkRun $run, User $user): void
    {
        Auth::setUser($user);
        $run->update(['status' => 'processing', 'started_at' => now()]);
        $action = $this->actions->get($run->action);
        $applied = $skipped = 0;
        $failed = [];
        $skips = [];
        $extra = [];
        $found = [];
        foreach (array_chunk($run->employee_ids, 100) as $chunk) {
            $employees = $this->scoped($user)->with('person')->whereIn('id', $chunk)->get()->keyBy('id');
            foreach ($chunk as $id) {
                if (! $employees->has($id)) {
                    $failed[] = ['uuid' => null, 'employee_number' => null, 'name' => null, 'reason' => 'Employee not found.'];
                }
            }
            $found[] = $employees;
            if ($action instanceof BulkBatchAction) {
                continue;
            }
            $results = $action->preview($user, $employees->values(), $run->params);
            foreach ($employees as $e) {
                $r = $results[$e->id] ?? ['status' => 'error', 'reason' => 'Could not be checked.'];
                if ($r['status'] === 'skipped') {
                    $skipped++;
                    count($skips) < 500 && $skips[] = $this->row($e, 'skipped', $r['reason'] ?? null);

                    continue;
                }
                if ($r['status'] !== 'ok') {
                    $failed[] = $this->row($e, 'error', $r['reason'] ?? null);

                    continue;
                }
                try {
                    DB::transaction(fn () => $action->apply($user, $e, $run->params));
                    $applied++;
                } catch (\Throwable $ex) {
                    $failed[] = $this->row($e, 'error', Friendly::message($ex, 'This employee could not be updated.'));
                }
            }
        }
        if ($action instanceof BulkBatchAction) {
            $all = collect($found)->flatMap(fn ($c) => $c->values()->all())->values();
            $ok = $action->preview($user, $all, $run->params);
            if (collect($ok)->contains(fn ($r) => $r['status'] === 'error')) {
                $failed[] = ['uuid' => null, 'employee_number' => null, 'name' => null, 'reason' => collect($ok)->firstWhere('status', 'error')['reason'] ?? 'Not allowed.'];
            } elseif ($all->isNotEmpty()) {
                try {
                    $extra = $action->applyBatch($user, $all, $run->params);
                    $applied = $all->count();
                } catch (\Throwable $ex) {
                    $failed[] = ['uuid' => null, 'employee_number' => null, 'name' => null, 'reason' => Friendly::message($ex, 'The action could not be completed.')];
                }
            }
        }
        $status = $failed === [] ? 'completed' : ($applied > 0 ? 'partial' : 'failed');
        $run->update(['status' => $status, 'applied' => $applied, 'skipped' => $skipped, 'failed' => count($failed), 'finished_at' => now(),
            'results' => ['failed' => array_slice($failed, 0, 500), 'skipped' => $skips, 'extra' => $extra]]);
        Audit::record('bulk_finished', 'people', 'bulk-run:'.$run->uuid, ['action' => $run->action, 'applied' => $applied, 'skipped' => $skipped, 'failed' => count($failed), 'params' => $run->params]);
    }

    public function present(BulkRun $run): array
    {
        return ['uuid' => $run->uuid, 'action' => $run->action, 'status' => $run->status, 'total' => $run->total, 'applied' => $run->applied, 'skipped' => $run->skipped, 'failed_count' => $run->failed,
            'failed' => $run->results['failed'] ?? [], 'skipped_rows' => $run->results['skipped'] ?? [], 'result' => $run->results['extra'] ?? [],
            'started_at' => $run->started_at?->toIso8601String(), 'finished_at' => $run->finished_at?->toIso8601String()];
    }

    private function scoped(User $user)
    {
        $q = Employee::query();
        $this->scope->constrain($q, $user, 'people.manage', 'employees.id');

        return $q;
    }

    private function row(Employee $e, string $status, ?string $reason): array
    {
        return ['uuid' => $e->uuid, 'employee_number' => $e->employee_number, 'name' => $e->person?->display_name, 'status' => $status, 'reason' => $reason];
    }

    // ---- confirmation token ----------------------------------------------------------------

    private function hashes(BulkRun $run): array
    {
        $ids = $run->employee_ids;
        sort($ids);

        return [hash('sha256', implode(',', $ids)), hash('sha256', json_encode($this->canonical($run->params ?? [])))];
    }

    private function canonical(mixed $v): mixed
    {
        if (is_array($v)) {
            ksort($v);

            return array_map(fn ($x) => $this->canonical($x), $v);
        }

        return $v;
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, 'bulk-confirmation|'.config('app.key'));
    }

    private function token(BulkRun $run, User $user, int $exp): string
    {
        [$ids, $params] = $this->hashes($run);
        $payload = rtrim(strtr(base64_encode(json_encode(['r' => $run->uuid, 'u' => $user->id, 't' => $user->tenant_id, 'a' => $run->action, 'i' => $ids, 'p' => $params, 'e' => $exp])), '+/', '-_'), '=');

        return $payload.'.'.$this->sign($payload);
    }

    private function verify(User $user, string $token): BulkRun
    {
        $invalid = fn () => abort(422, 'This confirmation is not valid. Preview the action again.');
        [$payload, $sig] = array_pad(explode('.', $token, 2), 2, '');
        if ($payload === '' || ! hash_equals($this->sign($payload), $sig)) {
            $invalid();
        }
        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        if (! is_array($data) || ($data['u'] ?? null) !== $user->id || ($data['t'] ?? null) !== $user->tenant_id) {
            $invalid();
        }
        abort_if(($data['e'] ?? 0) < now()->getTimestamp(), 422, 'This confirmation has expired. Preview the action again.');
        $run = BulkRun::query()->where('uuid', $data['r'] ?? '')->where('user_id', $user->id)->first();
        if (! $run || $run->status !== 'previewed' || $run->action !== ($data['a'] ?? null)) {
            abort(422, $run && $run->status !== 'previewed' ? 'This confirmation was already used. Preview the action again.' : 'This confirmation is not valid. Preview the action again.');
        }
        if ($this->hashes($run) !== [$data['i'] ?? null, $data['p'] ?? null]) {
            $invalid();
        }

        return $run;
    }
}
