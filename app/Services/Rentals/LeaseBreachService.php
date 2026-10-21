<?php

namespace App\Services\Rentals;

use App\Mail\Rentals\LeaseBreachNoticeMail;
use App\Mail\Rentals\LeaseBreachOtherPartyMail;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseBreach;
use App\Models\LeaseEvent;
use App\Models\LeaseSetting;
use App\Models\RentalNotice;
use App\Models\RentalNoticeDelivery;
use App\Models\RentalNoticeTemplate;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/rental-notices.md - breach notices (payment, other) and notices to vacate, from the agency's OWN uploaded templates.
 *
 * Issuing = a breach row + the filled template as a PDF filed on the lease + an email to the party in breach FROM THE AGENT'S OWN MAILBOX
 * (RentalMailDispatcher: SMTP + Sent-folder copy) + an email telling the OTHER party + both parties able to read it in their portal
 * (Documents) + the lease's log. The remedy window is the agency's setting; a notice to vacate is only possible for a tenant breach
 * that is still unremedied once that window has passed.
 */
class LeaseBreachService
{
    /** The breach's own alert type (registered by migration) - a shared type would be dropped by the per-person cooldown. */
    public const EVENT_REMEDY_DATE_REACHED = 'lease_breach.remedy_date_reached';

    public function __construct(
        private readonly RentalNoticeService $notices,
        private readonly LeaseNoticeReviewService $review,
        private readonly RentalMailDispatcher $mail,
    ) {
    }

    // ── Templates ───────────────────────────────────────────────────────────

    /** The agency's active templates that can be used for this kind of notice. @return Collection<int,RentalNoticeTemplate> */
    public function templatesFor(int $agencyId, string $purpose): Collection
    {
        $types = match ($purpose) {
            'payment' => [RentalNoticeTemplate::TYPE_BREACH_PAYMENT],
            // the one-off screen offers every breach template the agency has: payment ones ask for an amount, ad hoc ones for clause + reason
            'breach' => [RentalNoticeTemplate::TYPE_BREACH_PAYMENT, RentalNoticeTemplate::TYPE_BREACH_OTHER, RentalNoticeTemplate::TYPE_BREACH],
            'other' => [RentalNoticeTemplate::TYPE_BREACH_OTHER, RentalNoticeTemplate::TYPE_BREACH],
            default => [RentalNoticeTemplate::TYPE_NOTICE_TO_VACATE],
        };

        return RentalNoticeTemplate::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->where('is_active', true)
            ->whereIn('notice_type', $types)->orderBy('name')->get();
    }

    public function remedyDays(int $agencyId): int
    {
        return LeaseSetting::breachRemedyBusinessDaysFor($agencyId);
    }

    /** 'business' | 'calendar' - the agency's choice of how the remedy window counts (rental-notices.md §5). */
    public function remedyBasis(int $agencyId): string
    {
        return LeaseSetting::breachRemedyDayBasisFor($agencyId);
    }

    /** The days a notice made from this template gives: the template's own number, else the agency's default setting. */
    public function remedyDaysFor(RentalNoticeTemplate $template, int $agencyId): int
    {
        return $template->remedy_days && (int) $template->remedy_days > 0 ? (int) $template->remedy_days : $this->remedyDays($agencyId);
    }

    /** "7 calendar days" / "20 business days" for the screens that explain the window before a breach exists. */
    public function remedyWindowText(int $agencyId, ?int $days = null): string
    {
        return ($days ?? $this->remedyDays($agencyId)) . ' ' . $this->remedyBasis($agencyId) . ' days';
    }

    /** The date a new notice to vacate starts with: the day it is sent plus the agency's "Days for notice to vacate". */
    public function defaultVacateDate(int $agencyId): Carbon
    {
        return Carbon::today()->addDays(LeaseSetting::vacateNoticeDaysFor($agencyId));
    }

    /** The last day to remedy: $days after $from, counted in business or calendar days. */
    public function remedyDeadline(Carbon $from, int $days, string $basis): Carbon
    {
        return $basis === 'calendar' ? $from->copy()->startOfDay()->addDays($days) : $this->review->addBusinessDays($from, $days);
    }

    /** The figures a notice to vacate prints for its date and time ("30 November 2026", "12h00"). @return array<string,string> */
    public function vacateFigures(string $date, ?string $time, int $agencyId): array
    {
        $time = $time && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : LeaseSetting::vacateTimeFor($agencyId);

        return ['vacate_date' => Carbon::parse($date)->format('j F Y'), 'vacate_time' => LeaseBreach::vacateTimeText($time)];
    }

    // ── Issue ───────────────────────────────────────────────────────────────

    /**
     * One payment breach (against the tenant) for an outstanding amount.
     *
     * @throws ValidationException
     */
    public function issuePayment(Lease $lease, float $amount, RentalNoticeTemplate $template, User $agent, ?string $batchRef = null, array $figures = [], ?string $clause = null): LeaseBreach
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount outstanding (more than R 0).']);
        }
        if (LeaseBreach::withoutGlobalScopes()->where('lease_id', $lease->id)->where('kind', LeaseBreach::KIND_PAYMENT)->where('status', LeaseBreach::STATUS_ISSUED)->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['lease' => 'A payment breach notice is already out for this lease and has not been remedied.']);
        }

        return $this->issue($lease, LeaseBreach::KIND_PAYMENT, Lease::NOTICE_BY_TENANT, $amount, trim((string) $clause) !== '' ? trim((string) $clause) : null, null, $template, $agent, $batchRef, $figures);
    }

    /**
     * One "other" breach: the party in breach, the lease clause quoted and the reason.
     *
     * @throws ValidationException
     */
    public function issueOther(Lease $lease, string $party, string $clause, string $reason, RentalNoticeTemplate $template, User $agent, array $figures = []): LeaseBreach
    {
        if (! in_array($party, [Lease::NOTICE_BY_TENANT, Lease::NOTICE_BY_LANDLORD], true)) {
            throw ValidationException::withMessages(['breach_party' => 'Choose who is in breach.']);
        }
        if (trim($clause) === '' || trim($reason) === '') {
            throw ValidationException::withMessages(['clause' => 'Quote the lease clause and say what the breach is.']);
        }

        return $this->issue($lease, LeaseBreach::KIND_OTHER, $party, null, trim($clause), trim($reason), $template, $agent, null, $figures);
    }

    /** The filled notice text for a one-off breach, before anything is saved (names any placeholder still empty). @return array{html:string,missing:list<string>} */
    public function previewOther(Lease $lease, string $party, string $clause, string $reason, RentalNoticeTemplate $template, User $agent, array $figures = []): array
    {
        return $this->previewBreach($lease, LeaseBreach::KIND_OTHER, $party, null, $clause, $reason, $template, $agent, $figures);
    }

    /** The filled text of a payment or ad hoc breach notice, before anything is saved. @return array{html:string,missing:list<string>} */
    public function previewBreach(Lease $lease, string $kind, string $party, ?float $amount, string $clause, string $reason, RentalNoticeTemplate $template, User $agent, array $figures = []): array
    {
        $days = $this->remedyDaysFor($template, (int) $lease->agency_id);
        $basis = $this->remedyBasis((int) $lease->agency_id);
        $transient = new LeaseBreach([
            'kind' => $kind, 'breach_party' => $party, 'amount' => $amount, 'clause' => $clause, 'reason' => $reason, 'issued_on' => Carbon::today(),
            'remedy_business_days' => $days, 'remedy_day_basis' => $basis, 'remedy_deadline' => $this->remedyDeadline(Carbon::today(), $days, $basis),
        ]);
        $html = $this->notices->preview($template, $figures, $lease, $transient, $agent);

        return ['html' => $html, 'missing' => $this->notices->unfilled($html)];
    }

    /**
     * Many payment breaches at once: $amounts is lease id => outstanding amount; a row with no amount is ignored. A lease that cannot take
     * one is skipped and named, the rest go.
     *
     * @param Collection<int,Lease> $leases the leases the user may see, keyed by id
     * @return array{issued:list<LeaseBreach>,skipped:list<array{lease:Lease,reason:string}>,batch:string}
     */
    public function issueBulkPayment(Collection $leases, array $amounts, RentalNoticeTemplate $template, User $agent, array $figures = []): array
    {
        $batch = 'B' . now()->format('ymdHis') . Str::upper(Str::random(3));
        $issued = [];
        $skipped = [];
        foreach ($leases as $lease) {
            $raw = $amounts[$lease->id] ?? null;
            $amount = $this->parseAmount($raw);
            if ($amount === null) {
                continue; // no amount = no breach
            }
            try {
                $issued[] = $this->issuePayment($lease, $amount, $template, $agent, $batch, $figures);
            } catch (ValidationException $e) {
                $skipped[] = ['lease' => $lease, 'reason' => (string) collect($e->errors())->flatten()->first()];
            }
        }

        return ['issued' => $issued, 'skipped' => $skipped, 'batch' => $batch];
    }

    /** "R 1 234,50" / "1234.5" -> float; null for blank or not a number. */
    public function parseAmount(mixed $raw): ?float
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        $s = preg_replace('/[^0-9,.\-]/', '', str_replace(' ', '', $s)) ?? '';
        if (substr_count($s, ',') === 1 && substr_count($s, '.') === 0) {
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }

        return is_numeric($s) ? (float) $s : null;
    }

    // ── Remedy / vacate ─────────────────────────────────────────────────────

    /** The breach has been put right (on $on, today when not given). It can no longer be escalated. */
    public function remedy(LeaseBreach $breach, User $agent, ?string $note, ?string $on = null): LeaseBreach
    {
        if (! $breach->isIssued()) {
            throw ValidationException::withMessages(['breach' => 'This breach is not open.']);
        }
        $when = now();
        if ($on !== null && trim($on) !== '') {
            try {
                $when = Carbon::parse($on)->startOfDay();
            } catch (\Throwable) {
                throw ValidationException::withMessages(['remedied_on' => 'That is not a date.']);
            }
            if ($when->gt(Carbon::today())) {
                throw ValidationException::withMessages(['remedied_on' => 'The date it was put right cannot be in the future.']);
            }
            if ($when->lt($breach->issued_on->copy()->startOfDay())) {
                throw ValidationException::withMessages(['remedied_on' => 'It cannot have been put right before the breach notice was issued.']);
            }
        }
        $breach->update(['status' => LeaseBreach::STATUS_REMEDIED, 'remedied_at' => $when, 'remedied_by_user_id' => $agent->id, 'remedied_note' => trim((string) $note) ?: null]);
        $this->logEvent($breach->lease, LeaseEvent::TYPE_BREACH_REMEDIED, 'Breach remedied on ' . $when->format('j M Y') . ($note ? ' - ' . trim($note) : ''), $agent, ['lease_breach_id' => $breach->id]);

        return $breach->fresh();
    }

    // ── Follow-up when the remedy-by date is reached (rental-notices.md §7b) ─────────────────────

    /** The open breaches on this lease whose remedy-by date has been reached: "was it fixed?" is still unanswered. @return Collection<int,LeaseBreach> */
    public function awaitingFollowUp(Lease $lease): Collection
    {
        return LeaseBreach::withoutGlobalScopes()->where('lease_id', $lease->id)->where('status', LeaseBreach::STATUS_ISSUED)->whereNull('deleted_at')
            ->whereDate('remedy_deadline', '<=', Carbon::today()->toDateString())->orderBy('remedy_deadline')->orderBy('id')->get();
    }

    /**
     * Breaches whose remedy-by date has been reached and whose agents have not been told yet (running leases only).
     *
     * @return Collection<int,LeaseBreach>
     */
    public function dueForAlert(?int $agencyId = null, ?int $leaseId = null): Collection
    {
        $q = LeaseBreach::withoutGlobalScopes()->where('status', LeaseBreach::STATUS_ISSUED)->whereNull('deleted_at')->whereNull('remedy_alert_sent_at')
            ->whereDate('remedy_deadline', '<=', Carbon::today()->toDateString())
            ->whereIn('lease_id', Lease::withoutGlobalScopes()->where('status', Lease::STATUS_ACTIVE)->whereNull('deleted_at')->select('id'));
        if ($agencyId) {
            $q->where('agency_id', $agencyId);
        }
        if ($leaseId) {
            $q->where('lease_id', $leaseId);
        }

        return $q->orderBy('id')->get();
    }

    /**
     * Tell the lease's agents once - an in-app alert (and an email, per each person's own settings) under the breach's OWN alert type.
     *
     * @return int how many agents were reached
     */
    public function sendRemedyAlert(LeaseBreach $breach): int
    {
        $lease = Lease::withoutGlobalScopes()->find($breach->lease_id);
        $property = $lease?->property()->withoutGlobalScopes()->first();
        if (! $lease || ! $property) {
            return 0;
        }
        $title = \App\Support\RentalAlertTitle::withProperty('Breach remedy date reached - was it fixed?', $property);
        $body = 'The remedy-by date on the breach notice of ' . $breach->issued_on->format('j M Y') . ' (' . $breach->remedy_deadline->format('j M Y') . ') has been reached and the breach is not marked remedied. '
            . 'Open the lease and say whether it was fixed, or send a notice to vacate.';
        $url = route('corex.leases.show', $lease->id);
        $reached = 0;
        foreach (app(LeaseAgentService::class)->recipientsForEvent($lease, $property, (int) $lease->agency_id, null) as $agent) {
            try {
                if (app(NotificationDispatcher::class)->fire($agent, self::EVENT_REMEDY_DATE_REACHED, $breach, [
                    'title' => $title, 'body' => $body, 'action_url' => $url, 'severity' => 'warning', 'threshold_hit_at' => now(),
                ])) {
                    $reached++;
                }
            } catch (\Throwable $e) {
                Log::warning('Breach remedy alert failed', ['breach' => $breach->id, 'agent' => $agent->id, 'error' => $e->getMessage()]);
            }
        }
        $breach->forceFill(['remedy_alert_sent_at' => now()])->save(); // once - even when nobody could be reached, the NEXT bar still asks

        return $reached;
    }

    /**
     * The notice to vacate for a tenant breach that was not remedied in time.
     *
     * @throws ValidationException when it is not allowed yet / any more
     */
    public function issueVacate(LeaseBreach $breach, RentalNoticeTemplate $template, User $agent, array $figures = [], ?string $vacateDate = null, ?string $vacateTime = null): LeaseBreach
    {
        if ($breach->breach_party !== Lease::NOTICE_BY_TENANT) {
            throw ValidationException::withMessages(['breach' => 'A notice to vacate is only for a tenant who is in breach.']);
        }
        if (! $breach->isIssued()) {
            throw ValidationException::withMessages(['breach' => 'A notice to vacate needs an open breach notice that has not been remedied.']);
        }
        if (! $breach->remedyWindowPassed()) {
            throw ValidationException::withMessages(['breach' => 'The tenant has the whole of ' . $breach->remedy_deadline?->format('j F Y') . ' to put this right. A notice to vacate is available from ' . $breach->vacateAvailableFrom()?->format('j F Y') . '.']);
        }
        if ($vacateDate === null || trim($vacateDate) === '') {
            throw ValidationException::withMessages(['vacate_date' => 'Choose the date the tenant must be out by.']);
        }
        try {
            $vacateOn = Carbon::parse($vacateDate)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['vacate_date' => 'That is not a date.']);
        }
        if ($vacateOn->lt(Carbon::today())) {
            throw ValidationException::withMessages(['vacate_date' => 'The date the tenant must be out by cannot be in the past.']);
        }
        $time = $vacateTime && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $vacateTime) ? $vacateTime : LeaseSetting::vacateTimeFor((int) $breach->agency_id);
        $lease = $breach->lease;
        // ONE value: the date and time stored on the breach are the very ones the notice prints - a typed override of either key is dropped
        // (audit 2026-10-20 finding 7), whoever calls this.
        $figures = $this->vacateFigures($vacateOn->toDateString(), $time, (int) $breach->agency_id) + array_diff_key($figures, ['vacate_date' => 1, 'vacate_time' => 1]);

        [$notice, $pdf, $filename] = DB::transaction(function () use ($breach, $template, $agent, $figures, $lease, $vacateOn, $time) {
            // the date and time are filed on the breach first so the notice prints them
            $breach->forceFill(['vacate_date' => $vacateOn->toDateString(), 'vacate_time' => $time])->save();
            $result = $this->notices->generate($lease, $template, $figures, true, true, $agent, $breach);
            $breach->update(['status' => LeaseBreach::STATUS_ESCALATED, 'vacate_issued_at' => now(), 'vacate_issued_by_user_id' => $agent->id, 'vacate_rental_notice_id' => $result[0]->id]);

            return $result;
        });

        $told = $this->tell($breach->fresh(), $notice, $pdf, $filename, $agent, 'vacate');
        $this->logEvent($lease, LeaseEvent::TYPE_VACATE_ISSUED, 'Notice to vacate issued to the tenant after the breach notice of ' . $breach->issued_on->format('j M Y') . ' was not remedied - ' . $told, $agent, ['lease_breach_id' => $breach->id, 'rental_notice_id' => $notice->id]);

        return $breach->fresh();
    }

    /**
     * AT-495 - email a breach notice / notice to vacate again to the party in breach (addresses read afresh). The other party is not
     * told a second time. Every attempt is written down per person and on the lease log, the same as the first send.
     *
     * @return Collection<int, RentalNoticeDelivery> this attempt's rows
     */
    public function resendNotice(RentalNotice $notice, User $agent, string $pdf, string $filename): Collection
    {
        $breach = LeaseBreach::withoutGlobalScopes()->withTrashed()->with('lease')->findOrFail($notice->lease_breach_id);
        $lastId = (int) RentalNoticeDelivery::query()->where('rental_notice_id', $notice->id)->max('id');

        $told = $this->tell($breach, $notice, $pdf, $filename, $agent, 'breach', true);
        $this->logEvent($breach->lease, LeaseEvent::TYPE_BREACH_EDITED, RentalNoticeTemplate::labelFor($notice->notice_type) . ' resent - ' . $told, $agent,
            ['lease_breach_id' => $breach->id, 'rental_notice_id' => $notice->id, 'resend' => true]);

        return RentalNoticeDelivery::query()->where('rental_notice_id', $notice->id)->where('id', '>', $lastId)->get();
    }

    public function archive(LeaseBreach $breach, User $agent): void
    {
        $breach->delete();
        $this->logEvent($breach->lease, LeaseEvent::TYPE_BREACH_EDITED, 'Breach record archived', $agent, ['lease_breach_id' => $breach->id]);
    }

    public function restore(LeaseBreach $breach, User $agent): void
    {
        $breach->restore();
        $this->logEvent($breach->lease, LeaseEvent::TYPE_BREACH_EDITED, 'Breach record restored', $agent, ['lease_breach_id' => $breach->id]);
    }

    // ── internals ───────────────────────────────────────────────────────────

    private function issue(Lease $lease, string $kind, string $party, ?float $amount, ?string $clause, ?string $reason, RentalNoticeTemplate $template, User $agent, ?string $batch, array $figures): LeaseBreach
    {
        if ($lease->status !== Lease::STATUS_ACTIVE || $lease->trashed()) {
            throw ValidationException::withMessages(['lease' => 'A breach notice can only be issued on a lease that is running.']);
        }
        if ((int) $template->agency_id !== (int) $lease->agency_id || ! $template->is_active) {
            throw ValidationException::withMessages(['rental_notice_template_id' => 'Choose one of your agency\'s notice templates.']);
        }

        $days = $this->remedyDaysFor($template, (int) $lease->agency_id);
        $basis = $this->remedyBasis((int) $lease->agency_id);
        $today = Carbon::today();

        [$breach, $notice, $pdf, $filename] = DB::transaction(function () use ($lease, $kind, $party, $amount, $clause, $reason, $template, $agent, $batch, $figures, $days, $basis, $today) {
            $breach = LeaseBreach::withoutGlobalScopes()->create([
                'agency_id' => $lease->agency_id, 'branch_id' => $lease->branch_id, 'lease_id' => $lease->id, 'kind' => $kind, 'breach_party' => $party,
                'amount' => $amount, 'clause' => $clause, 'reason' => $reason, 'status' => LeaseBreach::STATUS_ISSUED, 'issued_on' => $today->toDateString(),
                'remedy_business_days' => $days, 'remedy_day_basis' => $basis, 'remedy_deadline' => $this->remedyDeadline($today, $days, $basis)->toDateString(),
                'issued_by_user_id' => $agent->id, 'batch_ref' => $batch,
            ]);
            // the document: both parties may read it in their portal
            [$notice, $pdf, $filename] = $this->notices->generate($lease, $template, $figures, true, true, $agent, $breach);
            $breach->update(['rental_notice_id' => $notice->id]);

            return [$breach, $notice, $pdf, $filename];
        });

        $breach = $breach->fresh();
        $told = $this->tell($breach, $notice, $pdf, $filename, $agent, 'breach');
        $this->logEvent($lease, LeaseEvent::TYPE_BREACH_ISSUED,
            ($kind === LeaseBreach::KIND_PAYMENT ? 'Payment breach notice issued to the tenant for ' . RentalNoticeService::money((float) $amount) : 'Breach notice issued to the ' . $party . ' - ' . Str::limit((string) $reason, 120))
            . ' - remedy by ' . $breach->remedy_deadline->format('j M Y') . ' - ' . $told,
            $agent, ['lease_breach_id' => $breach->id, 'rental_notice_id' => $notice->id, 'kind' => $kind, 'party' => $party, 'batch' => $batch]);

        return $breach;
    }

    /**
     * Email the party in breach (the notice attached) and tell the other party, both as the issuing agent through their own mailbox.
     * A failed email is logged and reported in the log line; it never undoes the notice (the document is filed and in both portals).
     *
     * @return string "who was told" for the lease log
     */
    private function tell(LeaseBreach $breach, RentalNotice $notice, string $pdf, string $filename, User $agent, string $event, bool $noticePartyOnly = false): string
    {
        $lease = $breach->lease;
        $inBreach = $breach->breach_party === Lease::NOTICE_BY_LANDLORD ? $lease->landlordContacts() : $lease->tenantContacts();
        $other = $breach->breach_party === Lease::NOTICE_BY_LANDLORD ? $lease->tenantContacts() : $lease->landlordContacts();
        $parts = [];

        $passes = $noticePartyOnly ? [[$inBreach, 'notice']] : [[$inBreach, 'notice'], [$other, 'told']];
        foreach ($passes as [$contacts, $role]) {
            $sent = [];
            $noEmail = [];
            $failed = [];
            $linked = 0;
            foreach ($contacts as $contact) {
                /** @var Contact $contact */
                $linked++;
                $name = trim((string) $contact->full_name) ?: ucfirst($breach->breach_party);
                $email = trim((string) $contact->email);
                $status = RentalNoticeDelivery::SENT;
                $error = null;
                // Bug class fixed 21 Oct 2026 (same as RentalNoticeService::send, AT-495): a contact with no email address, or an email that
                // failed, is NOT sent and is written down as such - the lease log never says "sent to" someone who was not emailed.
                if ($email === '') {
                    $status = RentalNoticeDelivery::NOT_SENT_NO_EMAIL;
                    $noEmail[] = $name;
                } elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $status = RentalNoticeDelivery::FAILED;
                    $error = 'That is not a valid email address.';
                    $failed[] = $name;
                } else {
                    try {
                        $this->mail->send($email, $role === 'notice'
                            ? new LeaseBreachNoticeMail($breach, $notice, $contact, $agent, $pdf, $filename)
                            : new LeaseBreachOtherPartyMail($breach, $contact, $agent, $event));
                        $sent[] = $name;
                    } catch (\Throwable $e) {
                        $status = RentalNoticeDelivery::FAILED;
                        $error = Str::limit($e->getMessage(), 500);
                        $failed[] = $name;
                        Log::warning('Breach notice: email failed', ['breach' => $breach->id, 'to' => $email, 'error' => $e->getMessage()]);
                    }
                }
                // the notice itself (the party in breach) gets a delivery row, so the Notices sent list shows the same truth
                if ($role === 'notice') {
                    RentalNoticeDelivery::create([
                        'agency_id' => $notice->agency_id, 'rental_notice_id' => $notice->id, 'contact_id' => $contact->id, 'recipient_role' => $breach->breach_party,
                        'recipient_name' => $name, 'recipient_email' => $email !== '' ? $email : null,
                        'status' => $status, 'error' => $error, 'attempted_at' => now(), 'attempted_by_user_id' => $agent->id,
                    ]);
                }
            }

            $text = ($role === 'notice' ? 'sent to ' : 'other party told: ');
            if ($linked === 0) {
                $text .= 'no one linked';
            } else {
                $text .= $sent ? implode(', ', array_unique($sent)) : 'no one';
                if ($noEmail) {
                    $text .= ' - NOT sent to ' . implode(', ', array_unique($noEmail)) . ' (no email address on file)';
                }
                if ($failed) {
                    $text .= ' - NOT sent to ' . implode(', ', array_unique($failed)) . ' (the email failed)';
                }
            }
            $parts[] = $text;
        }
        $this->notices->refreshDeliveryStatus($notice);

        return implode('; ', $parts);
    }

    private function logEvent(Lease $lease, string $type, string $description, ?User $user, array $metadata = []): void
    {
        LeaseEvent::create([
            'lease_id' => $lease->id, 'event_type' => $type, 'description' => $description, 'actor_user_id' => $user?->id,
            'metadata' => $metadata ?: null, 'occurred_at' => now(), 'created_at' => now(),
        ]);
    }
}
