<?php

namespace Ametecs\Qronex\Services;

use Ametecs\Qronex\Qronex;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Plain emails through the host's own mailer (its SMTP settings). A mail failure never breaks attendance. */
class Notify
{
    public static function send(?string $to, string $subject, string $body, ?int $tenantId = null): void
    {
        if (! $to || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        try {
            // A host with its own mail setup (SmartEPT: company SMTP from the console + mail log) sends it; else Laravel's default mailer.
            $host = Qronex::host();
            if (method_exists($host, 'sendMail')) {
                $host->sendMail($to, $subject, $body, $tenantId ?? Qronex::tenantId());

                return;
            }
            Mail::raw($body, fn ($m) => $m->to($to)->subject($subject));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
