<?php

namespace App\Listeners\Demo;

use App\Events\Demo\DemoAccessGranted;
use App\Mail\DemoAccessGrantMail;
use App\Support\Instance;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Mails the invitation when a grant is issued.
 *
 * Spec: .ai/specs/demo-access-control.md §6.1, §7
 *
 * ══ SYNCHRONOUS LISTENER, QUEUED MAIL ══
 *
 * This listener is deliberately NOT ShouldQueue, and the SMTP send is still off
 * the request path. Two reasons, in order of importance:
 *
 * 1. CORRECTNESS. Queueing the LISTENER means serialising the EVENT. Every CoreX
 *    domain event inherits readonly $eventId/$occurredAt/$traceId from
 *    AbstractDomainEvent, and SerializesModels cannot restore a parent-declared
 *    readonly property from the child's scope — PHP throws
 *    "Cannot initialize readonly property ... from scope". A queued listener on a
 *    domain event fatals on deserialisation. (Caught by
 *    DemoAccessGrantTest::test_the_listener_sends_the_mail_with_the_plaintext_code.)
 *
 * 2. IT IS UNNECESSARY. DemoAccessGrantMail implements ShouldQueue, so
 *    Mail::to()->send() hands the actual SMTP work to the worker anyway. All this
 *    listener does inline is build a Mailable — microseconds. Queueing it would
 *    buy nothing and cost the bug above.
 *
 * The mail lands on the `default` queue. The CoreX workers (corex-worker-live,
 * hfc-staging-queue) run `queue:work` with NO --queue flag and drain only
 * `default` — anything on a named queue is stranded forever. Do not pin a queue.
 *
 * REGISTRATION: Laravel 12 event auto-discovery (shouldDiscoverEvents). Do NOT
 * also add an explicit Event::listen — that binds the listener twice and the
 * prospect receives two emails with the same code.
 */
class SendDemoAccessGrantEmail
{
    public function handle(DemoAccessGranted $event): void
    {
        // Belt and braces. Grants are only ever issued on primary, so this should
        // be unreachable — but if it ever isn't, the demo host's mailer is Mailpit
        // and the prospect would get NOTHING, with no error anywhere. Refusing
        // loudly beats delivering into a black hole.
        if (Instance::isDemo()) {
            Log::error('[demo-access] Refusing to send a grant email from a DEMO instance — its mailer is Mailpit and the prospect would never receive it. Grants must be issued on primary.', [
                'grant_id' => $event->grant->id,
            ]);

            return;
        }

        Mail::to($event->grant->contact_email)
            ->send(new DemoAccessGrantMail(
                grant:      $event->grant,
                accessCode: $event->plaintextCode,
                gateUrl:    self::gateUrl(),
            ));
    }

    /** The demo's base address. Configurable so staging can point elsewhere. */
    public static function demoUrl(): string
    {
        return rtrim((string) config('corex.instance.demo_url', 'https://demo1.corexos.co.za'), '/');
    }

    /**
     * Where we send the prospect: straight at the gate, not the site root.
     *
     * The root works — it 302s to /demo/gate — but the emailed link is the first
     * thing a prospect ever sees of CoreX, and a link whose destination is the form
     * it actually opens is one they can trust before they click it.
     *
     * Built by CONCATENATION, deliberately — NOT route('demo.gate'). This email is
     * sent from PRIMARY (the demo host's mailer is a local catcher; see the guard
     * above), so route() would resolve against primary's own domain and mail the
     * prospect a staging URL that 404s. The demo's address is only ever knowable
     * from config, never from the URL generator of the box doing the sending.
     */
    public static function gateUrl(): string
    {
        return self::demoUrl() . '/demo/gate';
    }
}
