<?php

namespace App\Console\Commands;

use App\Mail\MailInterceptStillOnMail;
use App\Models\DevSetting;
use App\Support\OutboundMailGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * AT-URGENT-2026-09-09 — the daily nag. Scheduled once a day; that cadence
 * IS the throttle, deliberately no extra cooldown logic. Only fires when
 * interception is on AND that is an override (isOverridden()) — a QA1
 * environment quietly doing what it always does never nags anyone.
 *
 * Never auto-resumes sending — that is explicitly out of scope by design
 * (Johan: "automatically resuming sending could re-flood a host we are
 * still blocked by, which is the original incident again").
 */
class MailInterceptStaleAlert extends Command
{
    protected $signature = 'corex:mail-intercept-stale-alert';

    protected $description = 'Nag configured emails while outbound mail interception has been force-enabled';

    public function handle(): int
    {
        if (! (OutboundMailGuard::isActive() && OutboundMailGuard::isOverridden())) {
            $this->info('Outbound mail interception is not force-enabled — nothing to nag about.');
            return self::SUCCESS;
        }

        $since = OutboundMailGuard::forcedSince();
        $heldCount = OutboundMailGuard::heldCount();
        $lastChange = OutboundMailGuard::lastToggleChange();

        $sinceLabel = 'unknown duration';
        if ($since) {
            $mins = max(0, $since->diffInMinutes(now()));
            $d = intdiv($mins, 1440);
            $h = intdiv($mins % 1440, 60);
            $m = $mins % 60;
            $sinceLabel = trim(($d > 0 ? "{$d}d " : '') . ($h > 0 || $d > 0 ? "{$h}h " : '') . "{$m}m");
        }

        Log::warning('OUTBOUND MAIL INTERCEPTION STILL ON', [
            'app_env' => config('app.env'),
            'since_label' => $sinceLabel,
            'held_count' => $heldCount,
        ]);

        $recipients = DevSetting::mailInterceptAlertEmails();
        if (empty($recipients)) {
            $this->warn('No mail_intercept_alert_emails configured — logged only.');
            return self::SUCCESS;
        }

        Mail::to($recipients)->send(new MailInterceptStillOnMail(
            sinceLabel: $sinceLabel,
            heldCount: $heldCount,
            turnedOnBy: $lastChange?->user?->name,
            reason: $lastChange?->reason,
        ));

        $this->info("Nagged " . count($recipients) . " recipient(s) — on for {$sinceLabel}, {$heldCount} held.");

        return self::SUCCESS;
    }
}
