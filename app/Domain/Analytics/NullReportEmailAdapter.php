<?php

namespace App\Domain\Analytics;

use App\Models\User;

final class NullReportEmailAdapter implements ReportEmailAdapter
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function send(User $to, string $subject, string $body, string $link): bool
    {
        return false;
    }
}
