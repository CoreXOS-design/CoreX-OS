<?php

namespace App\Console\Commands;

use App\Events\Webinars\WebinarReminderSent;
use App\Mail\WebinarReminderMail;
use App\Models\WebinarRegistration;
use App\Support\Instance;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the single pre-webinar reminder (§0 A4).
 *
 * Spec: .ai/specs/webinar-registration.md §6.4
 *
 * ══ HOURLY, NOT DAILY ══
 *
 * The lead time is per webinar and expressed in HOURS. "24 hours before a 14:00
 * webinar" cannot be honoured by a job that runs once at 08:00 — it would fire six
 * hours early or eighteen hours late depending on the day. Hourly is the coarsest
 * cadence that can actually hit the window it is given.
 *
 * ══ THE STAMP IS THE IDEMPOTENCY ══
 *
 * reminder_sent_at is written immediately after the mail is queued, and the query
 * only picks up rows where it is NULL. A second run in the same hour — or an
 * overlapping run, or a retry — finds nothing to do. `withoutOverlapping()` on the
 * schedule is the belt; this is the braces, and it is the one that actually holds,
 * because it survives the process being killed mid-run.
 */
class SendWebinarReminders extends Command
{
    protected $signature = 'webinars:send-reminders';

    protected $description = 'Email the pre-webinar reminder to registrants whose lead time has arrived';

    public function handle(): int
    {
        // Reminders are sent from PRIMARY. The demo host's mailer is Mailpit — a
        // reminder sent from there lands in a local catcher and the registrant gets
        // nothing, with no error raised. Refusing loudly beats delivering into a
        // black hole.
        if (Instance::isDemo()) {
            $this->error('Refusing to send webinar reminders from a DEMO instance — its mailer is a local catcher.');

            return self::FAILURE;
        }

        $now = Carbon::now();

        $due = WebinarRegistration::query()
            ->with('webinar')
            ->awaitingReminder()
            ->whereHas('webinar', function ($q) use ($now) {
                $q->whereNull('archived_at')
                  // Never remind about a webinar that has already happened. Without
                  // this, switching the feature on would blast every historic
                  // registrant with a reminder for an event that is long over.
                  ->where('starts_at', '>', $now)
                  // The lead time has arrived: now >= starts_at - reminder_hours_before.
                  ->whereRaw('DATE_SUB(starts_at, INTERVAL reminder_hours_before HOUR) <= ?', [$now]);
            })
            ->get();

        if ($due->isEmpty()) {
            $this->info('No webinar reminders due.');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($due as $registration) {
            try {
                Mail::mailer('corex')
                    ->to($registration->email)
                    ->send(new WebinarReminderMail($registration));

                // Stamp IMMEDIATELY after queueing. If this line is never reached the
                // row stays due and the next hourly run retries it — the failure mode
                // is "one extra attempt", never "silently never sent".
                $registration->forceFill(['reminder_sent_at' => Carbon::now()])->save();

                WebinarReminderSent::dispatch($registration);

                $sent++;
            } catch (\Throwable $e) {
                // One bad address must not stop the rest of the cohort. The row keeps
                // its NULL stamp, so it is retried next hour.
                Log::error('[webinars] reminder failed', [
                    'registration_id' => $registration->id,
                    'webinar_id'      => $registration->webinar_id,
                    'error'           => $e->getMessage(),
                ]);
            }
        }

        $this->info("Queued {$sent} webinar reminder(s) of {$due->count()} due.");

        return self::SUCCESS;
    }
}
