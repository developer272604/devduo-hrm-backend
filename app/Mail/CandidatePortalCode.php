<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class CandidatePortalCode extends Mailable
{
    public function __construct(public string $code, public string $company) {}

    public function build(): self
    {
        return $this->subject('Your candidate portal verification code')->text('mail.candidate-portal');
    }
}
