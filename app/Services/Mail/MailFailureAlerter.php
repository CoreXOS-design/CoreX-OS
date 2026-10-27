<?php

namespace App\Services\Mail;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Any email that fails to leave CoreX is logged and reported to the platform
 * operator inbox (config mail.failure_alert_to) so it can be checked and resent.
 * The alert travels over the same mail server, which may be the thing that is
 * down — so the log line is written first and an alert failure is only logged.
 */
class MailFailureAlerter
{
    private static ?array $last = null;

    private static bool $alerting = false;

    public static function remember(MessageSending $event): void
    {
        $m = $event->message;
        self::$last = [
            'to'      => collect($m->getTo())->map(fn ($a) => $a->getAddress())->implode(', '),
            'subject' => $m->getSubject(),
        ];
    }

    public static function report(TransportExceptionInterface $e): void
    {
        if (self::$alerting) {
            return;
        }

        $ctx = self::$last ?? ['to' => 'unknown', 'subject' => 'unknown'];
        Log::error('MAIL NOT DELIVERED', $ctx + ['error' => substr($e->getMessage(), 0, 500)]);

        $to = config('mail.failure_alert_to');
        if (blank($to)) {
            return;
        }

        // One alert per distinct failure per 10 minutes — a mail outage must not flood the inbox.
        if (!Cache::add('mail-failure-alert:' . md5($ctx['to'] . $ctx['subject'] . substr($e->getMessage(), 0, 80)), 1, 600)) {
            return;
        }

        self::$alerting = true;
        try {
            $body = "An email did not go through on CoreX and needs checking / resending.\n\n"
                . "To: {$ctx['to']}\nSubject: {$ctx['subject']}\nTime: " . now()->toDateTimeString()
                . "\nError: " . substr($e->getMessage(), 0, 500)
                . "\n\n(Queued emails retry automatically; this alert is sent on every failed attempt, at most once per 10 minutes per email.)";
            Mail::raw($body, fn ($msg) => $msg->to($to)->subject('CoreX: email not delivered — ' . $ctx['to']));
        } catch (\Throwable $alertFailure) {
            Log::error('MAIL NOT DELIVERED: alert to operator also failed', ['error' => $alertFailure->getMessage()]);
        } finally {
            self::$alerting = false;
        }
    }
}
