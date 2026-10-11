<?php

namespace App\Console\Commands;

use App\Domain\People\Records\ExpiryScanner;
use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Illuminate\Console\Command;

class PeopleDocumentExpiry extends Command
{
    protected $signature = 'people:document-expiry {--days= : Expiry window in days (default: tenant setting document_expiry_days, else 30)}';

    protected $description = 'Record expiry events and My Work tasks for documents, identity records and work authorizations nearing expiry';

    public function handle(ExpiryScanner $scanner, TenantRunner $runner): int
    {
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : null;
        foreach (Tenant::query()->pluck('id') as $tenantId) {
            $result = $runner->run($tenantId, fn () => $scanner->scan($tenantId, $days));
            $this->line("Tenant $tenantId: {$result['events']} event(s), {$result['tasks']} task(s).");
        }

        return self::SUCCESS;
    }
}
