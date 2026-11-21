<?php

namespace App\Domain\Analytics;

use App\Models\User;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class SmtpReportEmailAdapter implements ReportEmailAdapter
{
    public function isConfigured(): bool
    {
        $origin = parse_url((string) config('app.frontend_url'));

        return config('mail.report_delivery_enabled')
            && config('mail.default') === 'smtp'
            && config('mail.mailers.smtp.transport') === 'smtp'
            && ($origin['scheme'] ?? null) === 'https'
            && isset($origin['host'])
            && ! isset($origin['user'])
            && ! isset($origin['pass'])
            && ! isset($origin['query'])
            && ! isset($origin['fragment']);
    }

    public function send(User $to, string $subject, string $body, string $link): bool
    {
        if (! $this->isConfigured() || ! preg_match('#^/(?!/)#', $link) || str_contains($link, '\\')) {
            return false;
        }

        try {
            $text = $body."\n\n".rtrim(config('app.frontend_url'), '/').$link;

            return Mail::mailer('smtp')->raw($text, function (Message $message) use ($to, $subject) {
                $message->to($to->email)->subject($subject);
            }) !== null;
        } catch (TransportExceptionInterface) {
            return false;
        }
    }
}
