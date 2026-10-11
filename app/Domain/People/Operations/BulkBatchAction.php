<?php

namespace App\Domain\People\Operations;

use App\Models\User;
use Illuminate\Support\Collection;

/** For actions that act on the whole set at once (for example export). */
interface BulkBatchAction
{
    /** @param Collection<int,\App\Domain\People\Models\Employee> $employees  @return array<string,mixed> result data */
    public function applyBatch(User $user, Collection $employees, array $params): array;
}
