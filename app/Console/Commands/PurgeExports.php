<?php

namespace App\Console\Commands;

use App\Domain\People\Boot\OperationsBoot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PurgeExports extends Command
{
    protected $signature = 'people:purge-exports';

    protected $description = 'Delete expired employee export files';

    public function handle(): int
    {
        $count = 0;
        $rows = DB::table('employee_exports')->whereNotNull('path')->where('expires_at', '<', now())->get(['id', 'path']);
        foreach ($rows as $row) {
            Storage::disk(OperationsBoot::EXPORTS)->delete($row->path);
            DB::table('employee_exports')->where('id', $row->id)->update(['path' => null, 'status' => 'expired', 'updated_at' => now()]);
            $count++;
        }
        $this->info("Purged $count expired export(s).");

        return self::SUCCESS;
    }
}
