<?php
namespace App\Domain\Assets;

use App\Domain\Assets\Models\{Asset, AssetProcurement, OperationsPreview};
use App\Domain\Audit\Audit;
use App\Domain\Operations\Money;
use App\Domain\People\Models\Employee;
use App\Models\User;
use App\Support\Spreadsheet\{SpreadsheetException, TabularReader};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Two-step asset operations: a stored, expiring PREVIEW (nothing changes), then an explicit COMMIT that re-validates against current data. */
final class AssetBulkService {
    private const MAX_ROWS = 1000;
    public function __construct(private AssetService $assets) {}

    private function guard(User $user): void { abort_unless($this->assets->manages($user), 403); }
    private function summary(array $rows): array {
        $invalid = count(array_filter($rows, fn ($r) => $r['errors'])); return ['total' => count($rows), 'valid' => count($rows) - $invalid, 'invalid' => $invalid];
    }
    private function view(OperationsPreview $p, bool $rows = true): array {
        return ['uuid' => $p->uuid, 'kind' => $p->kind, 'action' => $p->action, 'status' => $p->status, 'summary' => $p->summary, 'expires_at' => $p->expires_at->toIso8601String()] + ($rows ? ['rows' => $p->payload['rows']] : []);
    }
    public function show(User $user, string $uuid): array { $this->guard($user); return $this->view($this->find($user, $uuid)); }
    private function find(User $user, string $uuid, bool $lock = false): OperationsPreview {
        $q = OperationsPreview::where('uuid', $uuid)->where('created_by', $user->id); if ($lock) $q->lockForUpdate();
        return $q->firstOrFail();
    }
    private function open(OperationsPreview $p): void {
        if ($p->status !== 'preview') throw ValidationException::withMessages(['preview' => 'This preview was already committed.']);
        if ($p->expires_at->isPast()) { $p->status = 'expired'; $p->save(); throw ValidationException::withMessages(['preview' => 'This preview expired. Upload it again.']); }
    }

    // ---- import -------------------------------------------------------------------------------------------------------------
    public function previewImport(User $user, UploadedFile $file): array {
        $this->guard($user);
        try { $reader = TabularReader::open($file->getPathname(), $file->getClientOriginalName(), ['max_rows' => self::MAX_ROWS, 'max_bytes' => 5 * 1048576]); }
        catch (SpreadsheetException $e) { throw ValidationException::withMessages(['file' => $e->getMessage()]); }
        foreach (['identifier', 'name', 'category'] as $required) if (! in_array($required, $reader->headers(), true)) throw ValidationException::withMessages(['file' => 'The file must have an "'.$required.'" column.']);
        $rows = []; $seen = ['identifier' => [], 'serial_number' => []];
        foreach ($reader->rows() as $number => $row) {
            [$data, $errors] = $this->validateImportRow($row, $seen);
            $rows[] = ['row' => $number, 'data' => $data, 'errors' => $errors];
        }
        if (! $rows) throw ValidationException::withMessages(['file' => 'The file has no data rows.']);
        $p = OperationsPreview::create(['kind' => 'asset_import', 'created_by' => $user->id, 'filename' => mb_substr($file->getClientOriginalName(), 0, 250), 'payload' => ['rows' => $rows], 'summary' => $this->summary($rows),
            'status' => 'preview', 'expires_at' => now()->addHour()]);
        Audit::record('asset.import_previewed', 'assets', 'import:'.$p->uuid);
        return $this->view($p);
    }
    private function validateImportRow(array $row, array &$seen): array {
        $e = []; $get = fn ($k) => ($row[$k] ?? null) === null || $row[$k] === '' ? null : (string) $row[$k];
        $d = ['identifier' => $get('identifier'), 'name' => $get('name'), 'category' => $get('category'), 'serial_number' => $get('serial_number'), 'model' => $get('model'), 'location' => $get('location'),
            'purchase_reference' => $get('purchase_reference'), 'warranty_until' => $get('warranty_until'), 'vendor' => $get('vendor'), 'purchased_on' => $get('purchased_on'), 'currency' => $get('currency') ? strtoupper($get('currency')) : null,
            'purchase_cost' => $get('purchase_cost'), 'salvage_value' => $get('salvage_value'), 'useful_life_months' => $get('useful_life_months')];
        foreach (['identifier' => 80, 'name' => 150, 'category' => 80, 'serial_number' => 150, 'model' => 150, 'location' => 150, 'purchase_reference' => 150, 'vendor' => 150] as $k => $max) {
            if (in_array($k, ['identifier', 'name', 'category'], true) && $d[$k] === null) $e[] = $k.' is required.';
            if ($d[$k] !== null && mb_strlen($d[$k]) > $max) $e[] = $k.' is too long.';
        }
        foreach (['warranty_until', 'purchased_on'] as $k) if ($d[$k] !== null && ! (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d[$k]) && checkdate((int) substr($d[$k], 5, 2), (int) substr($d[$k], 8, 2), (int) substr($d[$k], 0, 4)))) $e[] = $k.' must be a date in YYYY-MM-DD form.';
        foreach (['identifier', 'serial_number'] as $k) if ($d[$k] !== null) {
            $key = mb_strtolower($d[$k]);
            if (isset($seen[$k][$key])) $e[] = $k.' repeats row '.$seen[$k][$key].'.'; else $seen[$k][$key] = $row['__row'] ?? count($seen[$k]) + 2;
            if (Asset::whereRaw('lower('.$k.') = ?', [$key])->exists()) $e[] = $k.' already exists in the inventory.';
        }
        if ($d['purchase_cost'] !== null) {
            try {
                if (! $d['currency']) throw new \InvalidArgumentException('currency is required with purchase_cost.');
                $scale = Money::scale($d['currency']); $cost = Money::minor($d['purchase_cost'], $scale, 'purchase_cost');
                $salvage = $d['salvage_value'] !== null ? Money::minor($d['salvage_value'], $scale, 'salvage_value') : 0;
                if ($salvage > $cost) $e[] = 'salvage_value cannot exceed purchase_cost.';
                if ($d['useful_life_months'] !== null && (! ctype_digit($d['useful_life_months']) || (int) $d['useful_life_months'] < 1 || (int) $d['useful_life_months'] > 600)) $e[] = 'useful_life_months must be 1-600.';
            } catch (ValidationException $v) { $e[] = collect($v->errors())->flatten()->first(); } catch (\InvalidArgumentException $v) { $e[] = $v->getMessage(); }
        }
        return [$d, $e];
    }
    public function commitImport(User $user, string $uuid, bool $skipInvalid): array {
        $this->guard($user);
        return DB::transaction(function () use ($user, $uuid, $skipInvalid) {
            $p = $this->find($user, $uuid, true); abort_unless($p->kind === 'asset_import', 404); $this->open($p);
            $created = 0; $skipped = 0; $seen = ['identifier' => [], 'serial_number' => []];
            $rows = array_map(function ($r) use (&$seen) { [$d, $e] = $this->validateImportRow($r['data'], $seen); return ['row' => $r['row'], 'data' => $d, 'errors' => $e]; }, $p->payload['rows']);
            if (array_filter($rows, fn ($r) => $r['errors']) && ! $skipInvalid) throw ValidationException::withMessages(['preview' => 'Fix the invalid rows or choose to skip them before importing.']);
            foreach ($rows as $r) {
                if ($r['errors']) { $skipped++; continue; }
                $d = $r['data'];
                $asset = $this->assets->create($user, collect($d)->only(['identifier', 'name', 'category', 'serial_number', 'model', 'location', 'purchase_reference', 'warranty_until'])->all());
                if ($d['purchase_cost'] !== null) {
                    $scale = Money::scale($d['currency']);
                    AssetProcurement::create(['asset_id' => $asset->id, 'kind' => 'purchase', 'vendor' => $d['vendor'], 'reference' => $d['purchase_reference'], 'purchased_on' => $d['purchased_on'], 'currency' => $d['currency'], 'minor_units' => $scale,
                        'cost_minor' => Money::minor($d['purchase_cost'], $scale), 'salvage_minor' => $d['salvage_value'] !== null ? Money::minor($d['salvage_value'], $scale) : 0,
                        'useful_life_months' => $d['useful_life_months'] !== null ? (int) $d['useful_life_months'] : null, 'warranty_until' => $d['warranty_until'], 'created_by' => $user->id]);
                }
                $created++;
            }
            $p->status = 'committed'; $p->committed_at = now(); $p->summary = $p->summary + ['created' => $created, 'skipped' => $skipped]; $p->save();
            Audit::record('asset.import_committed', 'assets', 'import:'.$p->uuid);
            return $this->view($p, false);
        });
    }

    // ---- bulk custody -------------------------------------------------------------------------------------------------------
    public function previewBulk(User $user, array $d): array {
        $this->guard($user); $action = $d['action']; $rows = []; $seen = [];
        foreach ($d['items'] as $i => $item) {
            $errors = []; $identifier = $item['identifier']; $asset = Asset::whereRaw('lower(identifier) = ?', [mb_strtolower($identifier)])->first();
            $employeeUuid = $item['employee_uuid'] ?? $d['employee_uuid'] ?? null; $employee = null;
            if (isset($seen[mb_strtolower($identifier)])) $errors[] = 'Listed more than once.'; $seen[mb_strtolower($identifier)] = true;
            if (! $asset) $errors[] = 'No asset has this identifier.';
            else {
                if (in_array($action, ['assign', 'transfer'])) {
                    $employee = $employeeUuid ? Employee::where('uuid', $employeeUuid)->whereIn('status', [...Employee::WORKING, 'preboarding'])->first() : null;
                    if (! $employee) $errors[] = 'Choose a working employee.';
                    if ($action === 'assign' && $asset->status !== 'available') $errors[] = 'Only available assets can be assigned.';
                    if ($action === 'transfer' && $asset->status !== 'assigned') $errors[] = 'Only assigned assets can be transferred.';
                    if ($employee && $employee->id === $asset->employee_id) $errors[] = 'Choose a different custodian.';
                } elseif (! in_array($asset->status, ['assigned', 'lost'])) $errors[] = 'Only assigned or lost assets can be returned.';
            }
            $rows[] = ['row' => $i + 1, 'data' => ['identifier' => $identifier, 'asset_uuid' => $asset?->uuid, 'version' => $asset?->version, 'status' => $asset?->status, 'employee_uuid' => $employee?->uuid], 'errors' => $errors];
        }
        $p = OperationsPreview::create(['kind' => 'asset_bulk', 'action' => $action, 'created_by' => $user->id, 'payload' => ['rows' => $rows, 'reason' => $d['reason'], 'condition' => $d['condition'] ?? 'good'],
            'summary' => $this->summary($rows), 'status' => 'preview', 'expires_at' => now()->addMinutes(30)]);
        Audit::record('asset.bulk_previewed', 'assets', 'bulk:'.$p->uuid);
        return $this->view($p);
    }
    public function commitBulk(User $user, string $uuid): array {
        $this->guard($user);
        return DB::transaction(function () use ($user, $uuid) {
            $p = $this->find($user, $uuid, true); abort_unless($p->kind === 'asset_bulk', 404); $this->open($p);
            if ($p->summary['invalid'] > 0) throw ValidationException::withMessages(['preview' => 'Remove the invalid rows and preview again.']);
            foreach ($p->payload['rows'] as $r) {
                // Each change re-checks the asset version captured at preview; any drift aborts the whole batch (409) so nothing is half applied.
                $this->assets->transition($user, $r['data']['asset_uuid'], ['action' => $p->action, 'version' => $r['data']['version'], 'employee_uuid' => $r['data']['employee_uuid'],
                    'condition' => $p->payload['condition'], 'reason' => $p->payload['reason']]);
            }
            $p->status = 'committed'; $p->committed_at = now(); $p->summary = $p->summary + ['applied' => count($p->payload['rows'])]; $p->save();
            Audit::record('asset.bulk_committed', 'assets', 'bulk:'.$p->uuid);
            return $this->view($p, false);
        });
    }
}
