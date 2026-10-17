<?php

namespace App\Http\Controllers\Lifecycle;

use App\Domain\Lifecycle\Graph\LifecycleGraphService;
use Illuminate\Http\Request;

/** Employee Lifecycle Dependency Graph (read-only). */
final class GraphController
{
    public function __construct(private LifecycleGraphService $svc) {}

    public function show(Request $r, string $uuid)
    {
        return $this->svc->forEmployee($r->user(), $uuid);
    }
}
