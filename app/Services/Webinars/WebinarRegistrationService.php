<?php

namespace App\Services\Webinars;

use App\Events\Webinars\WebinarRegistered;
use App\Listeners\Demo\SendDemoAccessGrantEmail;
use App\Mail\WebinarConfirmationMail;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use App\Services\Demo\DemoAccessService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Turning a form post on the marketing website into a registration, a demo grant,
 * and one email.
 *
 * Spec: .ai/specs/webinar-registration.md §6.1
 *
 * One home for the rules, so the public API and any future admin "register someone
 * manually" path cannot drift apart on them.
 */
class WebinarRegistrationService
{
    public function __construct(private readonly DemoAccessService $demoAccess) {}

    /**
     * Register someone, or re-issue for someone already registered.
     *
     * @param  array{name:string, email:string, company_name:string, phone?:?string}  $data
     * @return array{registration:WebinarRegistration, throttled:bool}
     */
    public function register(Webinar $webinar, array $data, ?string $ip = null, ?string $userAgent = null): array
    {
        $now   = Carbon::now();
        $email = strtolower(trim($data['email']));

        // ── The registration + grant, atomically ───────────────────────────────
        //
        // The mail is deliberately NOT sent inside this transaction. A grant that
        // exists without its email is recoverable — the person registers again and
        // gets a fresh code. An email whose grant was rolled back is a dead
        // credential sitting in a prospect's inbox, and they only discover it at the
        // gate.
        $result = DB::transaction(function () use ($webinar, $data, $email, $ip, $userAgent, $now) {

            $registration = WebinarRegistration::firstOrNew([
                'webinar_id' => $webinar->id,
                'email'      => $email,
            ]);

            // Later submissions win: someone correcting a typo in their name or
            // company is telling us the newer value is the right one.
            $registration->fill([
                'name'         => trim($data['name']),
                'company_name' => trim($data['company_name']),
                'phone'        => isset($data['phone']) ? (trim((string) $data['phone']) ?: null) : $registration->phone,
                'ip_address'   => $ip,
                'user_agent'   => $userAgent ? Str::limit($userAgent, 250, '') : null,
            ]);

            if (! $registration->exists) {
                $registration->source = 'website';
            }

            $registration->save();

            // Re-registering is legitimate (the code cannot be looked up or re-sent,
            // so a fresh grant is the only way to help someone who lost theirs) — but
            // it must not be a tap. Inside the cooldown we keep the updated details
            // and send nothing.
            if ($registration->isWithinReissueCooldown($now)) {
                return ['registration' => $registration, 'throttled' => true, 'code' => null];
            }

            [$grant, $code] = $this->demoAccess->issue([
                'company_name'  => $registration->company_name,
                'contact_email' => $registration->email,
                'contact_name'  => $registration->name,

                // NOT linked to a Contact. Johan's decision (§0 A5) — webinar
                // registrants do not enter the CRM.
                'contact_id'    => null,

                // THE WHOLE POINT: an absolute deadline shared by this webinar's
                // cohort, fixed at issue. Not expiry_hours — a clock that only starts
                // at first login would leave a never-used credential live forever,
                // and Johan's rule is that anyone who doesn't use the login loses
                // access on the date like everyone else.
                'expires_at'    => $webinar->demoAccessEndsAt(),

                // Why this grant has no human issuer, readable from the grant screen.
                'notes'         => 'Webinar: ' . $webinar->title . ' — self-serve registration',

                // We send our own combined email (§6.2). Without this the standard
                // demo invitation goes out too, and the prospect gets the same access
                // code twice.
                'deliver_email' => false,
            ], $webinar->created_by_user_id);

            $registration->forceFill([
                'demo_access_grant_id' => $grant->id,
                'last_issued_at'       => $now,
                'confirmation_sent_at' => $now,
            ])->save();

            return ['registration' => $registration, 'throttled' => false, 'code' => $code];
        });

        $registration = $result['registration'];

        if ($result['throttled']) {
            return ['registration' => $registration, 'throttled' => true];
        }

        // After commit. The Mailable is ShouldQueue, so this hands the SMTP work to
        // the worker — the `corex` mailer is chosen HERE because Mailer::queue()
        // stamps the sending mailer onto the mailable, so the call site always wins.
        Mail::mailer('corex')
            ->to($registration->email)
            ->send(new WebinarConfirmationMail(
                registration: $registration->fresh(['webinar']),
                accessCode:   $result['code'],
                gateUrl:      SendDemoAccessGrantEmail::gateUrl(),
            ));

        WebinarRegistered::dispatch($registration, $registration->wasRecentlyCreated === false);

        return ['registration' => $registration, 'throttled' => false];
    }
}
