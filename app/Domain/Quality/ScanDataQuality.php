<?php

namespace App\Domain\Quality;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Queued data-quality scan for one tenant. Carries only the trusted tenant id; the engine establishes the tenant context. */
final class ScanDataQuality implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public function __construct(public readonly int $tenantId) {}

    public function handle(DataQualityEngine $engine): void
    {
        $engine->scan($this->tenantId);
    }
}
