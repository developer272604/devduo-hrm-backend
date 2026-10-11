<?php

namespace App\Jobs\Payroll;

use App\Domain\Payroll\Models\PayrollExport;
use App\Domain\Payroll\Reports\ReportCatalogue;
use App\Domain\Payroll\Support\PayrollSettings;
use App\Domain\People\Boot\OperationsBoot as PeopleOps;
use App\Domain\Tenancy\TenantRunner;
use App\Models\User;
use App\Support\Spreadsheet\TabularWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/** Builds a payroll CSV/XLSX export AS the requesting user (data scope, permissions and amount masking identical to the screen). Idempotent: only a queued export is processed. */
class GeneratePayrollExport implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $tenantId, public int $exportId) {}

    public function handle(ReportCatalogue $reports): void
    {
        app(TenantRunner::class)->run($this->tenantId, function () use ($reports) {
            $export = PayrollExport::find($this->exportId);
            if (! $export || $export->status !== 'queued') {
                return;
            }
            $export->update(['status' => 'running']);
            $tmp = null;
            try {
                $user = User::where('tenant_id', $this->tenantId)->findOrFail($export->created_by);
                abort_unless($user->allows('payroll.export'), 403);
                $tmp = tempnam(sys_get_temp_dir(), 'pyx');
                $writer = null;
                foreach ($reports->stream($user, $export->report, $export->filters ?? []) as $row) {
                    $writer ??= TabularWriter::open($export->format, $tmp, array_keys($row));
                    $writer->write(array_values($row));
                }
                $writer ??= TabularWriter::open($export->format, $tmp, ['employee_number', 'name']);
                $count = $writer->count();
                $writer->close();
                $path = 'payroll/'.$this->tenantId.'/'.$export->uuid.'.'.$export->format;
                Storage::disk(PeopleOps::EXPORTS)->put($path, file_get_contents($tmp));
                $export->update(['status' => 'ready', 'file_path' => $path, 'row_count' => $count, 'masked' => ! $user->allows('payroll.results.view'),
                    'expires_at' => now()->addDays((int) PayrollSettings::get('exports.retention_days', 3))]);
            } catch (\Throwable $e) {
                Log::warning('Payroll export failed', ['export' => $export->uuid, 'tenant' => $this->tenantId, 'error' => $e->getMessage()]);
                $export->update(['status' => 'failed', 'error' => 'The export could not be created. Please try again or narrow the filters.']);
            } finally {
                if ($tmp && is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        });
    }
}
