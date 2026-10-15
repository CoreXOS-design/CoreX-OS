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
use App\Models\RentalNoticeTemplate;
use App\Models\User;
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

    /** "7 calendar days" / "20 business days" for the screens that explain the window before a breach exists. */
    public function remedyWindowText(int $agencyId): string
    {
        return $this->remedyDays($agencyId) . ' ' . $this->remedyBasis($agencyId) . ' days';
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
    public function issuePayment(Lease $lease, float $amount, RentalNoticeTemplate $template, User $agent, ?string $batchRef = null, array $figures = []): LeaseBreach
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount outstanding (more than R 0).']);
        }
        if (LeaseBreach::withoutGlobalScopes()->where('lease_id', $lease->id)->where('kind', LeaseBreach::KIND_PAYMENT)->where('status', LeaseBreach::STATUS_ISSUED)->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['lease' => 'A payment breach notice is already out for this lease and has not been remedied.']);
        }

        return $this->issue($lease, LeaseBreach::KIND_PAYMENT, Lease::NOTICE_BY_TENANT, $amount, null, null, $template, $agent, $batchRef, $figures);
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
        $days = $this->remedyDays((int) $lease->agency_id);
        $basis = $this->remedyBasis((int) $lease->agency_id);
        $transient = new LeaseBreach([
            'kind' => LeaseBreach::KIND_OTHER, 'breach_party' => $party, 'clause' => $clause, 'reason' => $reason, 'issued_on' => Carbon::today(),
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

    /** The breach has been put right. It can no longer be escalated. */
    public function remedy(LeaseBreach $breach, User $agent, ?string $note): LeaseBreach
    {
        if (! $breach->isIssued()) {
            throw ValidationException::withMessages(['breach' => 'This breach is not open.']);
        }
        $breach->update(['status' => LeaseBreach::STATUS_REMEDIED, 'remedied_at' => now(), 'remedied_by_user_id' => $agent->id, 'remedied_note' => trim((string) $note) ?: null]);
        $this->logEvent($breach->lease, LeaseEvent::TYPE_BREACH_REMEDIED, 'Breach remedied' . ($note ? ' - ' . trim($note) : ''), $agent, ['lease_breach_id' => $breach->id]);

        return $breach->fresh();
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
            throw ValidationException::withMessages(['breach' => 'The tenant still has until ' . $breach->remedy_deadline?->format('j F Y') . ' to put this right. A notice to vacate is available after that date.']);
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

        [$notice, $pdf, $filename] = DB::transaction(function () use ($breach, $template, $agent, $figures, $lease, $vacateOn, $time) {
            // the date and time are filed on the breach first so the notice prints them (typed figures still win)
            $breach->forceFill(['vacate_date' => $vacateOn->toDateString(), 'vacate_time' => $time])->save();
            $result = $this->notices->generate($lease, $template, $figures, true, true, $agent, $breach);
            $breach->update(['status' => LeaseBreach::STATUS_ESCALATED, 'vacate_issued_at' => now(), 'vacate_issued_by_user_id' => $agent->id, 'vacate_rental_notice_id' => $result[0]->id]);

            return $result;
        });

        $told = $this->tell($breach->fresh(), $notice, $pdf, $filename, $agent, 'vacate');
        $this->logEvent($lease, LeaseEvent::TYPE_VACATE_ISSUED, 'Notice to vacate issued to the tenant after the breach notice of ' . $breach->issued_on->format('j M Y') . ' was not remedied - ' . $told, $agent, ['lease_breach_id' => $breach->id, 'rental_notice_id' => $notice->id]);

        return $breach->fresh();
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

        $days = $this->remedyDays((int) $lease->agency_id);
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
    private function tell(LeaseBreach $breach, RentalNotice $notice, string $pdf, string $filename, User $agent, string $event): string
    {
        $lease = $breach->lease;
        $inBreach = $breach->breach_party === Lease::NOTICE_BY_LANDLORD ? $lease->landlordContacts() : $lease->tenantContacts();
        $other = $breach->breach_party === Lease::NOTICE_BY_LANDLORD ? $lease->tenantContacts() : $lease->landlordContacts();
        $parts = [];

        foreach ([[$inBreach, 'notice'], [$other, 'told']] as [$contacts, $role]) {
            $names = [];
            foreach ($contacts as $contact) {
                /** @var Contact $contact */
                $names[] = trim((string) $contact->full_name) ?: ucfirst($breach->breach_party);
                if (! $contact->email) {
                    continue;
                }
                try {
                    $this->mail->send($contact->email, $role === 'notice'
                        ? new LeaseBreachNoticeMail($breach, $notice, $contact, $agent, $pdf, $filename)
                        : new LeaseBreachOtherPartyMail($breach, $contact, $agent, $event));
                } catch (\Throwable $e) {
                    Log::warning('Breach notice: email failed', ['breach' => $breach->id, 'to' => $contact->email, 'error' => $e->getMessage()]);
                }
            }
            $parts[] = ($role === 'notice' ? 'sent to ' : 'other party told: ') . ($names ? implode(', ', array_unique($names)) : 'no one linked');
        }

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
