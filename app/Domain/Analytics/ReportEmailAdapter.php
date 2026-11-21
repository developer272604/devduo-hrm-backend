<?php

namespace App\Domain\Analytics;

use App\Models\User;

/** Email delivery seam. Deliveries are available when a supported transport is configured. */
interface ReportEmailAdapter
{
    public function isConfigured(): bool;

    /** Send a short notice with a link; never attach report data. */
    public function send(User $to, string $subject, string $body, string $link): bool;
}
