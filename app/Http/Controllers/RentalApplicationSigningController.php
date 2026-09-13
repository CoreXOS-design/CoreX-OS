<?php

namespace App\Http\Controllers;

use App\Mail\OtpMail;
use App\Models\FicaSubmission;
use App\Models\RentalApplication;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\RentalApplicationSignature;
use App\Services\Otp\OtpService;
use App\Services\RentalApplications\RentalApplicationNotifier;
use App\Services\RentalApplications\RentalApplicationPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * AT-392 spec §4b — the public tokenised route, modelled on the existing
 * /sign/{token} mechanism (SigningController) but for a document the agent
 * never signs. Reused, not reinvented: same token shape, same 14-day expiry
 * convention, same no-identity-leak treatment of an expired/used link.
 */
class RentalApplicationSigningController extends Controller
{
    /**
     * Applicant journey audit, 2026-09-12 — single source of truth for the
     * upload size cap. Was a bare `max:15360` literal duplicated across
     * uploadDocuments() and replaceDocument(), with the applicant-facing
     * error message hardcoding "15360 kilobytes" as Laravel's own default
     * wording — a unit nobody actually thinks in, and a number that could
     * silently drift out of sync with the rule if either were ever changed
     * without the other. One constant now drives both the validation rule
     * AND the human message.
     */
    private const MAX_UPLOAD_SIZE_KB = 15360;

    /** The allowed file kinds, in plain words — used in the human message below, never a bare mimes: list shown to an applicant. */
    private const UPLOAD_MIMES = 'pdf,jpg,jpeg,png,doc,docx';

    /**
     * Applicant journey audit, 2026-09-12 — Johan: "these are internal field
     * names and a unit nobody thinks in... say what happened and what to
     * do." Laravel's own default messages for a wildcard array field
     * ('supporting_files.*') read the raw, un-humanised attribute name
     * ("The supporting_files.0 field...") — meaningless to a non-technical
     * applicant — and express size in kilobytes. Shared by both file-upload
     * validation calls on this controller (uploadDocuments() and
     * replaceDocument()) so neither can drift from the other.
     */
    private function humanUploadValidationMessages(): array
    {
        $maxMb = (int) (self::MAX_UPLOAD_SIZE_KB / 1024);

        return [
            'supporting_files.*.mimes' => "We can only accept PDF, Word documents, or photos (JPG or PNG). Please try a different file, or save this one in one of those formats.",
            'supporting_files.*.max' => "That file is too big — we can accept files up to {$maxMb}MB. Try a smaller photo, or save it as a PDF.",
            'replacement_file.mimes' => "We can only accept PDF, Word documents, or photos (JPG or PNG). Please try a different file, or save this one in one of those formats.",
            'replacement_file.max' => "That file is too big — we can accept files up to {$maxMb}MB. Try a smaller photo, or save it as a PDF.",
        ];
    }

    /**
     * A public, unauthenticated route has no agency context to scope to at
     * all — the token itself IS the identity here. Uses the model's own
     * sanctioned cross-tenant escape hatch (BelongsToAgency::
     * queryWithoutAgencyScope()), never a raw withoutGlobalScope() call in
     * request code (CLAUDE.md Non-negotiable #7).
     */
    private function findByToken(string $token): RentalApplication
    {
        return RentalApplication::queryWithoutAgencyScope()
            ->where('token', $token)
            ->with(['contact', 'property', 'signatures', 'agency', 'branch', 'documents'])
            ->firstOrFail();
    }

    /**
     * Return gate, AT-392 round 4, 2026-09-13 — one session flag per
     * token, set either by a successful gate pass or by THIS session's own
     * submit(). Deliberately session-scoped, never a persistent cookie or
     * DB flag: a forwarded link opened in a fresh browser must always
     * re-gate — that is the entire point.
     */
    private function gateSessionKey(string $token): string
    {
        return "rental_application_return_gate_passed:{$token}";
    }

    private function returnGatePassed(RentalApplication $application, Request $request): bool
    {
        if (! $application->isSubmitted()) {
            return true;
        }

        return (bool) $request->session()->get($this->gateSessionKey($application->token));
    }

    private function markReturnGatePassed(RentalApplication $application, Request $request): void
    {
        $request->session()->put($this->gateSessionKey($application->token), true);
    }

    /**
     * Failed attempts must not become an oracle for guessing an ID against
     * a known application (Johan, verbatim) — strips everything but digits
     * on BOTH sides before comparing (conductor's refinement: "a correct
     * ID typed with spaces must pass — rejecting a right answer because of
     * punctuation is the worst possible failure for a security gate"),
     * then a constant-time comparison so a partial match can never be
     * timed out of the response.
     */
    private function idNumberMatches(RentalApplication $application, string $entered): bool
    {
        $enteredDigits = preg_replace('/[^0-9]/', '', $entered) ?? '';
        $realDigits = preg_replace('/[^0-9]/', '', (string) $application->id_number) ?? '';

        return $realDigits !== '' && $enteredDigits !== '' && hash_equals($realDigits, $enteredDigits);
    }

    /**
     * Never stored in clear, never in a URL or query string — OtpService
     * already hashes at rest and this call only ever receives the code
     * via a POST body field (see routes/web.php's gate routes). throttle()
     * is called explicitly (the engine itself doesn't call it) so a
     * malicious or over-eager resend can't spam the applicant's own inbox.
     */
    private function issueGateOtp(RentalApplication $application, string $email): void
    {
        $otpService = app(OtpService::class);

        if ($otpService->throttle('rental_application_return_gate', $email) !== null) {
            return;
        }

        $otpService->issue('rental_application_return_gate', $email, [
            'subject' => $application,
            'expires_minutes' => 10,
            'mail' => fn ($code) => new OtpMail(
                $code, 10,
                "Verify it's you to view your rental application",
                'Your verification code for your rental application',
            ),
        ]);
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($local, 0, 1);

        return $visible . str_repeat('*', max(1, mb_strlen($local) - 1)) . '@' . $domain;
    }

    /**
     * Renders the gate itself. For email_otp, sends the code automatically
     * on first render of a session (never resent on every reload — a
     * session flag tracks "already sent", the applicant's own "Resend
     * code" link is the only other trigger, and OtpService's own throttle
     * caps that too).
     */
    private function renderReturnGate(RentalApplication $application): View
    {
        $method = RentalApplicationQualifyingSetting::returnGateMethodFor($application->agency_id);

        if ($method === 'email_otp') {
            $email = $application->recipientEmail();
            $sentKey = "rental_application_gate_otp_sent:{$application->token}";
            if ($email && ! session($sentKey)) {
                $this->issueGateOtp($application, $email);
                session([$sentKey => true]);
            }

            return view('rental-applications.public.gate', [
                'method' => 'email_otp',
                'maskedEmail' => $email ? $this->maskEmail($email) : null,
                'lockedOut' => false,
                'token' => $application->token,
                'agentName' => $application->createdBy?->name,
                'agentEmail' => $application->createdBy?->email,
                'agentPhone' => $application->createdBy?->cell ?: $application->createdBy?->phone,
            ]);
        }

        return view('rental-applications.public.gate', [
            'method' => 'id_number',
            'lockedOut' => false,
            'token' => $application->token,
            'agentName' => $application->createdBy?->name,
            'agentEmail' => $application->createdBy?->email,
            'agentPhone' => $application->createdBy?->cell ?: $application->createdBy?->phone,
        ]);
    }

    /**
     * POST /{token}/verify-gate — throttled by the named
     * rental-application-gate limiter (see AppServiceProvider::boot()),
     * which handles the lockout response itself; this method only ever
     * runs for an attempt still inside budget.
     */
    public function verifyReturnGate(Request $request, string $token)
    {
        $application = $this->findByToken($token);

        if ($application->token_expires_at && $application->token_expires_at->isPast()) {
            return view('rental-applications.public.unavailable', ['reason' => 'expired']);
        }

        if (! $application->isSubmitted()) {
            return redirect()->route('rental-applications.public.show', $token);
        }

        $method = RentalApplicationQualifyingSetting::returnGateMethodFor($application->agency_id);

        if ($method === 'email_otp') {
            $email = $application->recipientEmail();
            $code = (string) $request->input('otp_code', '');
            $passed = $email !== null && app(OtpService::class)->verify('rental_application_return_gate', $email, $code) !== null;
        } else {
            $passed = $this->idNumberMatches($application, (string) $request->input('id_number', ''));
        }

        if ($passed) {
            $this->markReturnGatePassed($application, $request);

            return redirect()->route('rental-applications.public.show', $token);
        }

        // Deliberately the SAME generic message regardless of method or
        // how close the guess was — no oracle, no hint.
        return redirect()->route('rental-applications.public.show', $token)
            ->withErrors(['gate' => "That didn't match. Please try again."]);
    }

    /**
     * POST /{token}/gate/resend-otp — only meaningful when the agency's
     * gate method is email_otp; a no-op redirect otherwise. Relies
     * entirely on OtpService's own throttle() for abuse protection (resend
     * cooldown + hourly cap) rather than a second limiter here.
     */
    public function resendGateOtp(Request $request, string $token)
    {
        $application = $this->findByToken($token);

        if (! $application->isSubmitted() || RentalApplicationQualifyingSetting::returnGateMethodFor($application->agency_id) !== 'email_otp') {
            return redirect()->route('rental-applications.public.show', $token);
        }

        $email = $application->recipientEmail();
        if ($email) {
            $this->issueGateOtp($application, $email);
        }

        return redirect()->route('rental-applications.public.show', $token)->with('gate_status', 'A new code has been sent.');
    }

    public function show(Request $request, string $token): View
    {
        $application = $this->findByToken($token);

        if ($application->token_expires_at && $application->token_expires_at->isPast()) {
            return view('rental-applications.public.unavailable', ['reason' => 'expired']);
        }

        if ($application->status === 'draft') {
            return view('rental-applications.public.unavailable', ['reason' => 'not_sent']);
        }

        // Return gate, AT-392 round 4, 2026-09-13 — Johan: "initial open is
        // not gated but if the applicant submits... after initial
        // submission we can gate on ID." Checked BEFORE the
        // POST_RETURN_STATUSES branch below so it also covers 'reopened'
        // (deliberately excluded from that list, but isSubmitted() stays
        // true forever once set — a reopened editable form holds the same
        // sensitive data as the read-only view). The session flag is set
        // once, either by a successful gate pass or by THIS SAME session's
        // own submit() — never re-asked mid-session, always re-asked the
        // moment a fresh session opens the link, including a forwarded copy.
        if (! $this->returnGatePassed($application, $request)) {
            return $this->renderReturnGate($application);
        }

        // Reopen/resubmit, 2026-09-08 — 'reopened' is deliberately NOT in
        // POST_RETURN_STATUSES (see RentalApplication::REOPENABLE_STATUSES'
        // docblock), so it falls through to the same editable form as
        // sent/in_progress. Every field renders pre-filled from the model's
        // OWN attributes ($application->full_name etc, unchanged since the
        // last submission) — Johan: "prefilled - its a reopen, not new."
        if (in_array($application->status, RentalApplication::POST_RETURN_STATUSES, true)) {
            // AT-392 round 2, 2026-09-13 — the view needs to know whether
            // documents are open (see RentalApplication::documentUploadsOpen())
            // to decide whether to render the upload widget or the honest
            // closed message, and whether the WHOLE page should read as
            // closed (withdrawn/declined) rather than "already received".
            $documentUploadsOpen = $application->documentUploadsOpen();
            $documentUploadsClosedMessage = $application->documentUploadsClosedMessage();
            $isTerminallyClosed = in_array($application->status, RentalApplication::DOCUMENT_UPLOADS_ALWAYS_CLOSED_STATUSES, true);
            // FICA-mandatory, AT-392 round 3, 2026-09-13 — Johan: "flagged
            // ... on the applicant's confirmation" if they abandoned FICA.
            // ficaAwaitingApplicantAction distinguishes "never started" from
            // "submitted, we're reviewing it" — telling someone who already
            // did their part to go contact their agent would be wrong.
            $ficaOutstanding = $application->ficaOutstanding();
            $ficaAwaitingApplicantAction = $application->ficaAwaitingApplicantAction();

            // Return leg, AT-392 round 5, 2026-09-13 — Johan: the applicant
            // was told at submit to "complete FICA verification" and this
            // page then told them to contact their agent, with no way back
            // to the form that told them to. Only offer the link when the
            // ball is genuinely in the APPLICANT's court (never started, or
            // rejected/needs corrections) — once they've submitted and it's
            // awaiting OUR review, the existing "no action needed from you"
            // message is correct and no button belongs here. A missing or
            // expired token falls back to the existing contact-your-agent
            // wording rather than offering a dead link.
            $ficaContinueUrl = null;
            if ($ficaAwaitingApplicantAction) {
                $latestFicaSubmission = $application->latestFicaSubmission();
                if ($latestFicaSubmission && ! $latestFicaSubmission->isTokenExpired()) {
                    $ficaContinueUrl = route('fica.form', [
                        'token' => $latestFicaSubmission->token,
                        'return_url' => route('rental-applications.public.show', $application->token),
                        'return_context' => 'rental_application',
                    ]);
                }
            }

            return view('rental-applications.public.already-submitted', compact(
                'application', 'documentUploadsOpen', 'documentUploadsClosedMessage', 'isTerminallyClosed', 'ficaOutstanding', 'ficaAwaitingApplicantAction', 'ficaContinueUrl'
            ));
        }

        // Applicant-side autosave, 2026-09-12 — agency-configurable debounce,
        // never hardcoded in the template.
        $autosaveDebounceSeconds = \App\Models\RentalApplicationQualifyingSetting::autosaveDebounceSecondsFor($application->agency_id);

        return view('rental-applications.public.show', compact('application', 'autosaveDebounceSeconds'));
    }

    /**
     * Applicant-side autosave, 2026-09-12 — Johan: "a member of the public
     * part-way through a rental application... losing everything they have
     * typed is a defect on a public form." Debounced client-side (agency-
     * configurable via RentalApplicationQualifyingSetting::
     * autosaveDebounceSecondsFor(), default 5s) and again on blur — this
     * fires far less than once per keystroke.
     *
     * No new data model: every field the applicant types is already a
     * column on THIS row (RentalApplication::fieldValidationRules()), so
     * autosave fills and saves the SAME row the real submit() writes to.
     * Signatures are never accepted here — fieldValidationRules() has no
     * signature keys at all, so a stray declaration_signature/
     * tpn_consent_signature in the POST body is structurally ignored, not
     * just conventionally excluded.
     *
     * Deliberately MORE tolerant than submit(): a single field failing
     * validation (most commonly a date the applicant hasn't finished typing
     * yet, or a still-mid-edit value that would trip
     * current_rental_to's after_or_equal:current_rental_from rule) must
     * never block every OTHER field from saving. Validates the whole
     * payload once (so cross-field rules like the date-order check still
     * see both sides together), then simply excludes whichever field(s)
     * failed from what gets persisted THIS round — they're picked up again
     * on the next debounce once they're valid. This is a background save
     * catching an in-progress, possibly-transient state, not a submission
     * the applicant has said they're finished with.
     *
     * Always returns 200 with a JSON body — never a visible error. A
     * locked/terminal/expired application reports saved:false so the
     * frontend can quietly stop trying, never a 4xx the applicant would
     * see. The frontend treats network failure or any non-2xx the same way:
     * silently skip this round and retry on the next debounce.
     */
    public function autosave(Request $request, string $token)
    {
        $application = $this->findByToken($token);

        if ($application->token_expires_at && $application->token_expires_at->isPast()) {
            return response()->json(['saved' => false]);
        }

        if ($application->status === 'draft'
            || in_array($application->status, RentalApplication::POST_RETURN_STATUSES, true)) {
            return response()->json(['saved' => false]);
        }

        // Return gate, AT-392 round 4, 2026-09-13 — 'reopened' isn't in
        // POST_RETURN_STATUSES above, so it reaches here, but isSubmitted()
        // is still true (set on the original submission, never cleared) —
        // a reopened editing session must not autosave without having
        // passed the gate in this session. Silent, matching this route's
        // own "always 200, never a visible error" contract — never a
        // visible failure, just a quiet skip until the next debounce.
        if (! $this->returnGatePassed($application, $request)) {
            return response()->json(['saved' => false]);
        }

        // Volume cap, per APPLICATION (not per IP) — 2026-09-12, Johan's own
        // audit question: the route's `throttle:40,1` middleware is per-IP,
        // which stops a naive single-source script but does nothing against
        // a caller that rotates IPs or simply waits out each 1-minute
        // window forever — with no second layer that's up to 40 x 1440 =
        // 57,600 writes/day to ONE row. This is that second, independent
        // layer, keyed on the APPLICATION ID — which is resolved from the
        // TOKEN in the URL path (findByToken() above), never from anything
        // the caller supplies in the request body, so this key is not
        // attacker-controlled: reaching a different bucket requires a
        // different valid token, not a different request parameter.
        //
        // Agency-configurable, never hardcoded (Johan's standing rule,
        // restated explicitly after this exact addition): both the cap and
        // its rolling window come from RentalApplicationQualifyingSetting.
        // Sized generous-by-default against the WORST-CASE legitimate rate
        // (see that class's own docblock) — getting this too tight is worse
        // than not having it at all: an applicant silently locked out
        // mid-typing, unaware their work has stopped saving, is a bigger
        // failure than a script over-writing its own one row.
        $rateLimitMax = \App\Models\RentalApplicationQualifyingSetting::autosaveRateLimitMaxFor($application->agency_id);
        $rateLimitWindowSeconds = \App\Models\RentalApplicationQualifyingSetting::autosaveRateLimitWindowMinutesFor($application->agency_id) * 60;
        $rateLimitKey = 'rental-application-autosave:' . $application->id;
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($rateLimitKey, $rateLimitMax)) {
            // The applicant must be TOLD their work has stopped saving, not
            // left to keep typing into a form that silently no longer
            // persists anything — this is the one autosave failure mode
            // that is NOT safe to degrade silently, because unlike a
            // network blip it will not self-resolve on the next debounce.
            // The already-saved draft (everything up to this point) is
            // completely untouched — this returns before fill()/save() run.
            return response()->json(['saved' => false, 'rate_limited' => true]);
        }
        \Illuminate\Support\Facades\RateLimiter::hit($rateLimitKey, $rateLimitWindowSeconds);

        $rules = RentalApplication::fieldValidationRules();
        $input = $request->only(array_keys($rules));
        $input = array_merge($input, RentalApplication::sanitizeNumericInput($request->only(RentalApplication::NUMERIC_FIELDS)));

        $validator = \Illuminate\Support\Facades\Validator::make($input, $rules);
        $fields = collect($input)->except($validator->errors()->keys())->all();
        $fields = array_map(fn ($v) => $v === '' ? null : $v, $fields);
        $fields = RentalApplication::normalizeStillLiving($fields);

        $application->fill($fields);
        if ($application->status === 'sent') {
            $application->status = 'in_progress';
        }
        $application->draft_saved_at = now();
        $application->save();

        return response()->json([
            'saved' => true,
            'saved_at' => $application->draft_saved_at->toIso8601String(),
        ]);
    }

    /**
     * AT-392 spec §3 — the applicant signs twice here (declaration +
     * TPN consent) in one sitting. Every field optional; only the two
     * signature captures are required to submit online.
     */
    public function submit(Request $request, string $token, \App\Services\RentalApplications\RentalApplicationAuditService $audit)
    {
        $application = $this->findByToken($token);

        if ($application->token_expires_at && $application->token_expires_at->isPast()) {
            return redirect()->route('rental-applications.public.show', $token)
                ->with('error', 'This link has expired.');
        }

        if ($application->status === 'draft') {
            return redirect()->route('rental-applications.public.show', $token);
        }

        // Return gate, AT-392 round 4, 2026-09-13 — a resubmit (reopened
        // status, isSubmitted() already true from the original submission)
        // must not be reachable via a raw direct POST without ever having
        // passed the gate in this session. A genuine first-time submit
        // (isSubmitted() still false) is never gated — matches
        // returnGatePassed()'s own "never submitted, never gated" rule.
        if (! $this->returnGatePassed($application, $request)) {
            return redirect()->route('rental-applications.public.show', $token);
        }

        // Reopen/resubmit — same POST_RETURN_STATUSES check as show() above;
        // 'reopened' is not in that list, so a reopened application can
        // reach submit() same as sent/in_progress can.
        if (in_array($application->status, RentalApplication::POST_RETURN_STATUSES, true)) {
            return redirect()->route('rental-applications.public.show', $token);
        }

        // RA-02 (cc5) — "a real person types 15,000... gets 'must be a
        // number'." Strip thousand separators/spaces/R-prefix on every
        // numeric field before validation, same as the agent-side fix.
        $request->merge(RentalApplication::sanitizeNumericInput($request->only(RentalApplication::NUMERIC_FIELDS)));

        // BUILD_STANDARD §2 — every field is optional (nullable passes on
        // empty/absent), but a MALFORMED value must be rejected with a clear
        // message, never allowed through to crash the date/decimal cast on
        // save(). This is a public, unauthenticated endpoint — validation
        // here is the only thing standing between a tampered field and a 500.
        $validated = $request->validate(array_merge(RentalApplication::fieldValidationRules(), [
            'declaration_signature' => ['required', 'string'],
            'tpn_consent_signature' => ['required', 'string'],
        ]));

        $fields = collect($validated)->except(['declaration_signature', 'tpn_consent_signature'])->all();
        // Optional-and-empty must never error (BUILD_STANDARD §2) — a blank
        // string is stored as NULL, never coerced into breaking a date/decimal cast.
        $fields = array_map(fn ($v) => $v === '' ? null : $v, $fields);
        $fields = RentalApplication::normalizeStillLiving($fields);

        // Standing rule — transactions roll back clean: the record save,
        // both signature captures, AND (reopen/resubmit, 2026-09-08) the
        // sealed generation snapshot must land together or not at all.
        //
        // Generation, 2026-09-08 — current_generation defaults to 1 at
        // creation (matches every pre-reopen signature row, already
        // backfilled to generation=1). The FIRST-EVER submit() must seal
        // under that same 1, not bump past it — signalled by submitted_at
        // still being null (it is set exactly once per RentalApplication::
        // isSubmitted()'s own docblock, on every submit including this one,
        // and never cleared). Every submit() AFTER the first (i.e. a
        // reopen-resubmit, where submitted_at is already set from the prior
        // round) bumps current_generation before sealing. Either way,
        // signatures are stamped with whatever current_generation ends up
        // being for THIS submission — so a resubmit can never collide with
        // (and therefore can never overwrite) the previous round's
        // signature row, which is the "signature landmine" this build was
        // required to fix.
        $isResubmit = $application->submitted_at !== null;

        DB::transaction(function () use ($application, $fields, $validated, $request, $audit, $isResubmit) {
            $fromStatus = $application->status;

            $application->fill($fields);
            $application->delivery_mode = 'online';
            $application->status = 'returned';
            $application->submitted_at = now();
            if ($isResubmit) {
                $application->current_generation = $application->current_generation + 1;
            }
            $application->save();

            $this->storeSignature($application, 'declaration', $validated['declaration_signature'], $request);
            $this->storeSignature($application, 'tpn_consent', $validated['tpn_consent_signature'], $request);

            \App\Models\RentalApplicationGeneration::seal($application, $request);

            // Defect fix, AT-392 (Johan via cc5's journey walk) — neither a
            // first submission nor a resubmit-after-reopen ever wrote to the
            // evidentiary trail; there was no way to tell an applicant had
            // ever responded. No User actor exists on this public,
            // unauthenticated route — both calls take null, same as any
            // other applicant-originated event on this feature.
            \App\Models\RentalApplicationStatusHistory::record(
                $application, $fromStatus, 'returned', null,
                $isResubmit ? 'Applicant resubmitted online.' : 'Applicant submitted online.',
            );
            $audit->log(
                $application,
                eventCategory: 'applicant',
                eventType: $isResubmit ? 'resubmitted' : 'submitted',
                oldValues: ['status' => $fromStatus],
                newValues: ['status' => 'returned'],
                humanSummary: $isResubmit
                    ? 'Applicant resubmitted online after a reopen.'
                    : 'Applicant submitted the application online.',
            );
        });

        // Outside the transaction, deliberately: a notification failure must
        // never roll back the applicant's already-committed submission
        // (Johan, 2026-09-07 — "the agent must be notified", but the
        // applicant's data landing is the more important guarantee of the two).
        $application = $application->fresh();
        app(RentalApplicationNotifier::class)->notifyAgentOfReturn($application);

        // AT-392 — keeps Contact::rental_application_status in sync
        // (App\Listeners\Contact\RecomputeRentalApplicationStatus).
        event(new \App\Events\RentalApplication\RentalApplicationSubmitted($application, $isResubmit));

        // AT-392 round 3, 2026-09-13 — Johan: "played around that initial
        // open is not gated but if the applicant submits we should have the
        // id number which we can update the contact record with." Only
        // backfills an EMPTY field — never overwrites an id_number already
        // on file, same guard every other id_number-writing call site in
        // this codebase uses (see PropertyContactController for the
        // precedent this follows).
        if ($application->id_number && $application->contact && ! $application->contact->id_number) {
            $application->contact->update([
                'id_number' => $application->id_number,
                'id_number_captured_at' => now(),
                'id_number_source' => 'rental_application',
            ]);
        }

        // Return gate, AT-392 round 4, 2026-09-13 — this submit (whether
        // the first-ever one, ungated, or a resubmit that already passed
        // the gate to get here) unlocks the rest of THIS session — the
        // FICA hand-off below, and the applicant's own confirmation once
        // they return from it, must not immediately re-ask something they
        // just proved seconds ago by the act of submitting.
        $this->markReturnGatePassed($application, $request);

        // FICA-mandatory, AT-392 round 3, 2026-09-13 — Johan, a legal
        // position: "submit and complete fica forces them to complete fica
        // whilst we receive the application back." The application is
        // ALREADY fully submitted above, committed and notified — this
        // hand-off can never lose it, whatever happens next. One
        // continuous flow straight into CoreX's existing FICA form (not a
        // second FICA system — see FicaSubmission::firstOrCreate below,
        // same find-or-reuse shape SigningController's own FICA gate
        // already uses), which redirects back here via return_url the
        // moment FICA is done (or is already on file — see
        // fica.form's own already-submitted bypass).
        //
        // Conductor, live on QA1, 2026-09-13 — a real unauthenticated
        // applicant hit a 500 here: fica_submissions.requested_by is
        // NOT NULL (every prior FICA request was staff-initiated; an
        // applicant submitting their own application has no authenticated
        // user to attribute it to). The application's own submission had
        // ALREADY committed by this point (proven live: status/signatures
        // survived the 500 intact), but the exception still propagated to
        // the applicant as a raw crash — the DATA guarantee held, the
        // EXPERIENCE guarantee didn't. Never again: this hand-off is now
        // genuinely non-fatal. Any failure creating/finding the
        // FicaSubmission is logged and the applicant is sent to their own
        // confirmation instead — ficaOutstanding() already reads "true"
        // with no FicaSubmission row, so the outstanding-FICA messaging
        // there is already correct with zero extra state needed.
        try {
            $ficaSubmission = $this->findOrCreateFicaSubmission($application);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AT-392 FICA hand-off failed after a successful submission', [
                'rental_application_id' => $application->id,
                'contact_id' => $application->contact_id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('rental-applications.public.show', $token);
        }

        return redirect()->to(
            route('fica.form', $ficaSubmission->token)
            . '?return_url=' . urlencode(route('rental-applications.public.show', $token))
            . '&return_context=rental_application'
        );
    }

    /**
     * AT-392 spec §5 — reuses the SAME allowlist/size-cap contract as
     * SigningController::uploadSupportingDocuments() (pdf,jpg,jpeg,png,doc,docx,
     * 15MB/file, max 10 files), filed through the shared `documents` table.
     */
    /**
     * Johan, QA1 — "I select docs and click submit... no docs arrive back
     * because i never clicked upload" / "I complete all the information...
     * attach a file, click upload and the screen refreshes, and all my
     * typed info is gone." Root cause of BOTH: this was a synchronous
     * form-POST-redirect action, so ANY use of it reloaded the whole page —
     * discarding whatever the applicant had typed into the main form but
     * not yet submitted (there is no separate "save" step on this public
     * page; everything lives only in the browser until Submit). Fixed by
     * making this endpoint respond with JSON when asked (the new
     * fetch-based upload in show.blade.php) so the file attaches with NO
     * navigation at all — the old synchronous form-POST path is left
     * intact for any caller that still wants it (e.g. a no-JS fallback),
     * unchanged in behaviour.
     */
    /**
     * AT-392 round 2, 2026-09-13 — cc3's finding while investigating the
     * withdraw control: this route (and remove/replace below) never
     * checked status at all, so a withdrawn or declined application's
     * public link kept accepting files indefinitely. Shared here so
     * upload/remove/replace can never drift out of sync on WHEN uploads
     * are closed, same reasoning as assertDocumentsNotLocked() below for
     * WHETHER already-submitted documents are locked. See
     * RentalApplication::documentUploadsOpen()/documentUploadsClosedMessage()
     * for the actual rule (withdrawn/declined always closed; approved is
     * an agency setting, default open).
     */
    private function assertDocumentUploadsOpen(RentalApplication $application, string $token, Request $request)
    {
        if ($application->documentUploadsOpen()) {
            return null;
        }

        $message = $application->documentUploadsClosedMessage();

        if ($request->wantsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return redirect()->route('rental-applications.public.show', $token)->with('error', $message);
    }

    public function uploadDocuments(Request $request, string $token)
    {
        $application = $this->findByToken($token);

        if ($application->token_expires_at && $application->token_expires_at->isPast()) {
            if ($request->wantsJson()) {
                return response()->json(['message' => 'This link has expired.'], 410);
            }

            return redirect()->route('rental-applications.public.show', $token)
                ->with('error', 'This link has expired.');
        }

        if ($closed = $this->assertDocumentUploadsOpen($application, $token, $request)) {
            return $closed;
        }

        if ($application->status === 'draft') {
            if ($request->wantsJson()) {
                return response()->json(['message' => "This application hasn't been sent to you yet."], 410);
            }

            return redirect()->route('rental-applications.public.show', $token);
        }

        $request->validate([
            'supporting_files' => ['required', 'array', 'min:1', 'max:10'],
            'supporting_files.*' => ['file', 'mimes:' . self::UPLOAD_MIMES, 'max:' . self::MAX_UPLOAD_SIZE_KB],
            'document_type_id' => ['nullable', 'integer', 'exists:document_types,id'],
        ], $this->humanUploadValidationMessages());

        $filedDocuments = [];
        foreach ($request->file('supporting_files') as $file) {
            $path = $file->store("rental-applications/{$application->id}/documents", 'local');

            // withoutAgencyStamping() — this route has no authenticated user in
            // the normal case, but an agent previewing their own sent link
            // while still logged in (possibly switched into a different agency
            // context) must never cause the document to be mis-tenanted; it
            // always files against the APPLICATION's own agency, explicitly.
            $document = \App\Models\Document::withoutAgencyStamping(fn () => \App\Models\Document::create([
                'original_name' => $file->getClientOriginalName(),
                'storage_path' => $path,
                'disk' => 'local',
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'document_type_id' => $request->input('document_type_id'),
                'source_type' => 'rental_application',
                'source_id' => $application->id,
                'agency_id' => $application->agency_id,
                'branch_id' => $application->branch_id,
            ]));

            $document->contacts()->syncWithoutDetaching([$application->contact_id]);
            if ($application->property_id) {
                $document->properties()->syncWithoutDetaching([$application->property_id]);
            }

            $filedDocuments[] = $document;
        }

        if ($application->status === 'sent') {
            $application->status = 'in_progress';
            $application->save();
        }

        $filed = count($filedDocuments);

        if ($request->wantsJson()) {
            return response()->json([
                'documents' => collect($filedDocuments)->map(fn ($d) => [
                    'id' => $d->id,
                    'name' => $d->original_name,
                    'view_url' => route('rental-applications.public.documents.view', [$token, $d->id]),
                ]),
            ]);
        }

        return redirect()->route('rental-applications.public.show', $token)
            ->with('success', $filed === 1
                ? 'Your document was uploaded.'
                : "Your {$filed} documents were uploaded.");
    }

    /**
     * FICA-mandatory, AT-392 round 3, 2026-09-13 — same find-or-reuse shape
     * SigningController's own FICA gate already uses for e-sign (checked,
     * not assumed — see SigningController::show(), the FICA gate block),
     * with ONE deliberate difference: this one auto-CREATES a submission
     * when none exists at all, because e-sign's gate assumes an agent has
     * already sent a FICA request via the compliance screen first, but
     * Johan's "one continuous flow" instruction means the applicant must
     * never hit a dead "no FICA link exists yet" state straight off their
     * own submit button.
     *
     * A repeat contact with any submission from ANY prior transaction
     * (approved, or still in progress) is found and reused as-is — this
     * table has never been scoped to a single deal, so "have they FICA'd
     * before" is genuinely a yes/no per contact, not per rental
     * application (confirmed against the actual query shape, not assumed).
     */
    private function findOrCreateFicaSubmission(RentalApplication $application): FicaSubmission
    {
        $existing = FicaSubmission::where('contact_id', $application->contact_id)
            ->whereIn('status', ['draft', 'submitted', 'under_review', 'agent_approved', 'approved'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            // fica_submissions.token is nullable — a tokenless reused draft
            // would otherwise throw UrlGenerationException the moment
            // route('fica.form', ...) is called. Same defensive mint
            // SigningController's own FICA gate already does.
            if (empty($existing->token)) {
                $existing->token = Str::random(64);
                $existing->token_expires_at = now()->addDays(14);
                $existing->save();
            }

            return $existing;
        }

        return FicaSubmission::create([
            'contact_id' => $application->contact_id,
            'agency_id' => $application->agency_id,
            'branch_id' => $application->branch_id,
            'requested_by' => $this->resolveFicaRequestedBy($application),
            'token' => Str::random(64),
            'token_expires_at' => now()->addDays(14),
            'status' => 'draft',
        ]);
    }

    /**
     * Conductor, live on QA1, 2026-09-13 — a real 500: fica_submissions.
     * requested_by is NOT NULL because every prior FICA request was
     * staff-initiated; an applicant submitting online has no authenticated
     * user at all. Checked every real consumer of this column before
     * choosing (not a bare nullable — conductor's explicit instruction):
     *
     *   - FicaController's own permission checks
     *     (`$submission->requested_by === auth()->id() || isOwnerRole() ||
     *     hasPermission('manage_compliance')`, e.g. FicaController.php:946)
     *     degrade safely on null — it just never matches a real user, so
     *     only an owner/compliance-manager could act on it. Fine.
     *   - FicaSubmission::scopeVisibleTo()'s 'own' scope
     *     (`where('requested_by', $user->id)`) does NOT degrade safely —
     *     a null value never matches, so a plain agent scoped to 'own'
     *     would NEVER see this submission in their own compliance queue.
     *     That directly breaks Johan's own requirement: "agent can then
     *     push them to complete fica" — he can't push what he can't see.
     *     This is why null was rejected in favour of a resolvable user.
     *
     * Fallback chain: the agent who owns the application
     * (created_by_user_id) — but that column is ALSO nullable and, in the
     * live incident that surfaced this, WAS null (application 334). Falls
     * back to the agency's own admin (role='admin', scoped by agency_id) —
     * always resolvable per LastAdminException's own guarantee that no
     * agency can ever be left without one. If even that somehow returns
     * null, FicaSubmission::create() throws and the caller's try/catch
     * (submit()) degrades this to "FICA outstanding, logged" rather than
     * a 500 — never fatal to the applicant either way.
     */
    private function resolveFicaRequestedBy(RentalApplication $application): ?int
    {
        return $application->created_by_user_id
            ?? \App\Models\User::where('agency_id', $application->agency_id)->where('role', 'admin')->value('id');
    }

    public function pdf(Request $request, string $token)
    {
        $application = $this->findByToken($token);

        // Return gate, AT-392 round 4, 2026-09-13 — the PDF holds the exact
        // same sensitive fields (ID number, income, bank details) as the
        // gated view, and a direct/bookmarked link to it would otherwise
        // bypass the gate entirely.
        if (! $this->returnGatePassed($application, $request)) {
            return redirect()->route('rental-applications.public.show', $token);
        }

        $path = app(RentalApplicationPdfService::class)->generate($application);

        return response()->download($path, 'Rental Application.pdf')->deleteFileAfterSend(true);
    }

    /**
     * Johan, 2026-09-07 — full CRUD for the applicant's OWN documents, not
     * just create. Every action here re-derives the application from the
     * TOKEN first, then checks the document's source_type/source_id match
     * THIS application before touching it — a document id is a globally
     * auto-incrementing key on the shared `documents` table, shared across
     * every agency and every application, so the id alone proves nothing.
     * A document belonging to a DIFFERENT application (or found via a
     * different/no token at all) 404s exactly like it doesn't exist —
     * never a 403 that would confirm the id is valid but off-limits.
     */
    private function scopedDocument(RentalApplication $application, int $documentId): \App\Models\Document
    {
        $document = \App\Models\Document::where('id', $documentId)
            ->where('source_type', 'rental_application')
            ->where('source_id', $application->id)
            ->first();

        abort_unless($document, 404);

        return $document;
    }

    public function viewDocument(Request $request, string $token, int $document)
    {
        $application = $this->findByToken($token);

        if ($application->token_expires_at && $application->token_expires_at->isPast()) {
            abort(404);
        }

        // Return gate, AT-392 round 4, 2026-09-13 — this is the single
        // most sensitive route the applicant journey has: an uploaded ID
        // copy, payslip or bank statement, viewable directly. A bookmarked
        // or forwarded link to a specific document must never bypass the
        // gate that protects everything else.
        if (! $this->returnGatePassed($application, $request)) {
            return redirect()->route('rental-applications.public.show', $token);
        }

        $doc = $this->scopedDocument($application, $document);

        return $doc->downloadResponse();
    }

    /**
     * Johan, 2026-09-07 — "submitted docs are submitted. they can add, but
     * not replace or remove." Evidentiary rule: once an agent has received
     * the application, the applicant must not be able to quietly swap or
     * pull a document the agent has already seen. This is a correctness
     * rule, not a setting — no agency toggle, no threshold, checked the
     * same way for every agency. Shared by removeDocument() and
     * replaceDocument() so the two can never drift out of sync with each
     * other or with RentalApplication::isSubmitted().
     */
    private function assertDocumentsNotLocked(RentalApplication $application, string $token)
    {
        if (! $application->isSubmitted()) {
            return null;
        }

        return redirect()->route('rental-applications.public.show', $token)
            ->with('error', 'The documents you submitted with your application are locked and can\'t be changed. You can still add more below.');
    }

    /**
     * Archive only — Document already uses SoftDeletes, so delete() here is
     * never destructive (CLAUDE.md Non-negotiable #1: no hard deletes,
     * anywhere, no exceptions). The file itself is left on disk; only the
     * DB row (and therefore its visibility everywhere, including the agent
     * side) is archived.
     */
    public function removeDocument(Request $request, string $token, int $document)
    {
        $application = $this->findByToken($token);

        if ($application->token_expires_at && $application->token_expires_at->isPast()) {
            if ($request->wantsJson()) {
                return response()->json(['message' => 'This link has expired.'], 410);
            }

            return redirect()->route('rental-applications.public.show', $token)
                ->with('error', 'This link has expired.');
        }

        if ($closed = $this->assertDocumentUploadsOpen($application, $token, $request)) {
            return $closed;
        }

        $doc = $this->scopedDocument($application, $document);

        if ($locked = $this->assertDocumentsNotLocked($application, $token)) {
            if ($request->wantsJson()) {
                return response()->json(['message' => "The documents you submitted with your application are locked and can't be changed."], 423);
            }

            return $locked;
        }

        $doc->delete();

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Document removed.']);
        }

        return redirect()->route('rental-applications.public.show', $token)
            ->with('success', 'Document removed.');
    }

    /**
     * Replace = the old document is archived and a new one filed, together,
     * atomically — never a window where the old is gone and the new hasn't
     * landed yet, and never a hard delete of the old row.
     */
    public function replaceDocument(Request $request, string $token, int $document)
    {
        $application = $this->findByToken($token);

        if ($application->token_expires_at && $application->token_expires_at->isPast()) {
            if ($request->wantsJson()) {
                return response()->json(['message' => 'This link has expired.'], 410);
            }

            return redirect()->route('rental-applications.public.show', $token)
                ->with('error', 'This link has expired.');
        }

        if ($closed = $this->assertDocumentUploadsOpen($application, $token, $request)) {
            return $closed;
        }

        $oldDoc = $this->scopedDocument($application, $document);

        if ($locked = $this->assertDocumentsNotLocked($application, $token)) {
            if ($request->wantsJson()) {
                return response()->json(['message' => "The documents you submitted with your application are locked and can't be changed."], 423);
            }

            return $locked;
        }

        $validated = $request->validate([
            'replacement_file' => ['required', 'file', 'mimes:' . self::UPLOAD_MIMES, 'max:' . self::MAX_UPLOAD_SIZE_KB],
        ], $this->humanUploadValidationMessages());

        $newDoc = DB::transaction(function () use ($request, $application, $oldDoc) {
            $file = $request->file('replacement_file');
            $path = $file->store("rental-applications/{$application->id}/documents", 'local');

            $newDoc = \App\Models\Document::withoutAgencyStamping(fn () => \App\Models\Document::create([
                'original_name' => $file->getClientOriginalName(),
                'storage_path' => $path,
                'disk' => 'local',
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'document_type_id' => $oldDoc->document_type_id,
                'source_type' => 'rental_application',
                'source_id' => $application->id,
                'agency_id' => $application->agency_id,
                'branch_id' => $application->branch_id,
            ]));

            $newDoc->contacts()->syncWithoutDetaching([$application->contact_id]);
            if ($application->property_id) {
                $newDoc->properties()->syncWithoutDetaching([$application->property_id]);
            }

            $oldDoc->delete();

            return $newDoc;
        });

        if ($request->wantsJson()) {
            return response()->json([
                'document' => [
                    'id' => $newDoc->id,
                    'name' => $newDoc->original_name,
                    'view_url' => route('rental-applications.public.documents.view', [$token, $newDoc->id]),
                ],
                'replaced_id' => $oldDoc->id,
            ]);
        }

        return redirect()->route('rental-applications.public.show', $token)
            ->with('success', 'Document replaced.');
    }

    private function storeSignature(RentalApplication $application, string $kind, string $dataUrl, Request $request): void
    {
        // Signature pad payload is a data: URI (image/png;base64,...) — same
        // capture shape the e-sign signing view already uses.
        if (! preg_match('/^data:image\/png;base64,(.+)$/', $dataUrl, $m)) {
            return;
        }

        $binary = base64_decode($m[1]);
        $path = "rental-applications/{$application->id}/signatures/" . $kind . '-' . Str::random(8) . '.png';
        Storage::disk('local')->put($path, $binary);

        // Reopen/resubmit, 2026-09-08 — 'generation' is now part of the key.
        // $application->current_generation has ALREADY been bumped (if this
        // is a resubmit) by the time this runs, inside the same transaction
        // — so this always creates a NEW row for a new round, never
        // overwrites the previous round's signature.
        //
        // QA1 multi-tenancy sweep, 2026-09-12 — agency_id is now part of the
        // match/create attributes, and the whole call is wrapped in
        // withoutAgencyStamping() so BelongsToAgency's creating() hook can
        // never override this already-correct, already-validated value with
        // whatever the calling context's own agency resolves to (this route
        // is normally unauthenticated, but an owner-role account testing a
        // link while switched into a different agency is exactly the edge
        // case that hook exists to guard against elsewhere in this sweep).
        RentalApplicationSignature::withoutAgencyStamping(fn () => RentalApplicationSignature::updateOrCreate(
            ['rental_application_id' => $application->id, 'agency_id' => $application->agency_id, 'kind' => $kind, 'generation' => $application->current_generation],
            [
                'signature_path' => $path,
                'signed_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]
        ));
    }
}
