<?php

namespace App\Domain\Recruitment\Contracts;

final class NullChannelAdapter implements CandidateChannelAdapter
{
    public function publishPosting(array $posting): ?string
    {
        return null;
    }

    public function interviewScheduled(array $interview): ?string
    {
        return null;
    }
}
