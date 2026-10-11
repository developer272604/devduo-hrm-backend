<?php

namespace App\Domain\Analytics;

use App\Models\User;

/** Email delivery seam. No provider ships with the product: the default adapter reports itself as unconfigured. */
interface ReportEmailAdapter
{
    public function isConfigured(): bool;

    /** Send a short notice with a link; never attach report data. */
    public function send(User $to, string $subject, string $body, string $link): bool;
}
