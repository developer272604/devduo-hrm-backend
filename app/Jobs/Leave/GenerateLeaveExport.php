<?php

namespace App\Jobs\Leave;

use App\Domain\Leave\Reports\ReportCatalogue;
use App\Domain\People\Boot\OperationsBoot as PeopleOps;
use App\Domain\Tenancy\TenantRunner;
use App\Models\User;
use App\Support\Spreadsheet\TabularWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/** Builds a CSV/XLSX leave export as the requesting user (so data scope, permissions and medical masking apply exactly as on screen). */
class GenerateLeaveExport implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $tenantId, public int $exportId) {}

    public function handle(ReportCatalogue $reports): void
    {
        app(TenantRunner::class)->run($this->tenantId, function () use ($reports) {
            $row = DB::table('leave_exports')->where('tenant_id', $this->tenantId)->where('id', $this->exportId)->first();
            if (! $row || $row->status !== 'queued') {
                return;
            }
            DB::table('leave_exports')->where('id', $row->id)->update(['status' => 'processing', 'updated_at' => now()]);
            $tmp = null;
            try {
                $user = User::where('tenant_id', $this->tenantId)->findOrFail($row->user_id);
                abort_unless($user->allows('leave.export'), 403);
                $filters = json_decode($row->filters ?? '[]', true) ?: [];
                $tmp = tempnam(sys_get_temp_dir(), 'lvx');
                $writer = null;
                foreach ($reports->stream($user, $row->report, $filters) as $r) {
                    $writer ??= TabularWriter::open($row->format, $tmp, array_keys($r));
                    $writer->write(array_values($r));
                }
                $writer ??= TabularWriter::open($row->format, $tmp, ['employee_uuid', 'employee_number', 'employee_name']);
                $count = $writer->count();
                $writer->close();
                $path = 'leave/'.$this->tenantId.'/'.$row->uuid.'.'.$row->format;
                Storage::disk(PeopleOps::EXPORTS)->put($path, file_get_contents($tmp));
                DB::table('leave_exports')->where('id', $row->id)->update(['status' => 'completed', 'path' => $path, 'row_count' => $count, 'expires_at' => now()->addDays(3), 'updated_at' => now()]);
            } catch (\Throwable $e) {
                Log::warning('Leave export failed', ['export' => $row->uuid, 'tenant' => $this->tenantId, 'error' => $e->getMessage()]);
                DB::table('leave_exports')->where('id', $row->id)->update(['status' => 'failed', 'error' => 'The export could not be created. Please try again or narrow the filters.', 'updated_at' => now()]);
            } finally {
                if ($tmp && is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        });
    }
}
