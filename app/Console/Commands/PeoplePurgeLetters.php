<?php

namespace App\Console\Commands;

use App\Domain\People\Records\LetterRetention;
use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Illuminate\Console\Command;

final class PeoplePurgeLetters extends Command
{
    protected $signature = 'people:purge-letters {tenant : Tenant ID} {--apply : Purge eligible letters}';

    protected $description = 'Preview or apply the HR letter retention policy for one tenant';

    public function handle(TenantRunner $runner, LetterRetention $retention): int
    {
        $tenant = filter_var($this->argument('tenant'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($tenant === false || ! Tenant::whereKey($tenant)->exists()) {
            $this->error('Provide an existing tenant ID.');

            return self::FAILURE;
        }

        $result = $runner->run($tenant, fn () => $retention->run((bool) $this->option('apply')));
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
