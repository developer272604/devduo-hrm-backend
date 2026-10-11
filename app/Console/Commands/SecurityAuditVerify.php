<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditChain;
use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Illuminate\Console\Command;

class SecurityAuditVerify extends Command
{
    protected $signature = 'security:audit-verify {--tenant= : Only this tenant id}';

    protected $description = 'Verify the tamper-evident audit hash chain of every tenant (exit code 1 when a chain is broken)';

    public function handle(TenantRunner $runner): int
    {
        $broken = false;
        foreach (Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->pluck('id') as $id) {
            $result = $runner->run((int) $id, fn () => AuditChain::verify());
            $this->line('tenant '.$id.': '.($result['ok'] ? 'OK' : 'BROKEN').' checked='.$result['checked'].' failures='.$result['failure_count']);
            foreach ($result['failures'] as $failure) {
                $this->error('  sequence '.$failure['sequence'].': '.$failure['reason']);
            }
            $broken = $broken || ! $result['ok'];
        }

        return $broken ? self::FAILURE : self::SUCCESS;
    }
}
