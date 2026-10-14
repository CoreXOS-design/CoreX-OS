<?php

namespace App\Services\Rentals;

use App\Mail\Rentals\LeaseNoticeAcceptedMail;
use App\Mail\Rentals\LeaseNoticeRejectedMail;
use App\Mail\Rentals\RentalOwnerNoticeToAgentMail;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseNotice;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/lease-notices.md - the life of a notice given by the tenant or the landlord:
 *   give (portal, or the agent on their behalf)  ->  received: the agents are told, nobody else is
 *   review -> accept (as given / with a corrected date) -> the lease's move-out date is set HERE and only here (recordNotice())
 *          -> reject (with a reason; the person who gave it is told)
 * Acceptance also TELLS the other party (mail as the agent + their portal): nothing reaches them before that. Every step is on the lease's log.
 */
class LeaseNoticeService
{
    public const EVENT_RECEIVED = 'lease_notice.received';
    public const ATTACHMENT_MAX_KB = 10240;
    public const ATTACHMENT_MIMES = 'pdf,jpg,jpeg,png,doc,docx';

    public function __construct(private readonly LeaseNoticeReviewService $review)
    {
    }

    // ── Give a notice ───────────────────────────────────────────────────────

    /**
     * @param array{notice_date:string,intended_end_date:string,reason?:?string} $data
     *
     * @throws ValidationException when the lease cannot take a notice
     */
    public function give(Lease $lease, string $givenBy, array $data, string $via, ?Contact $contact, ?User $agent, ?UploadedFile $file = null): LeaseNotice
    {
        if (! in_array($givenBy, [Lease::NOTICE_BY_TENANT, Lease::NOTICE_BY_LANDLORD], true)) {
            throw ValidationException::withMessages(['given_by' => 'Invalid notice source.']);
        }
        if ($lease->status !== Lease::STATUS_ACTIVE || $lease->trashed()) {
            throw ValidationException::withMessages(['lease' => 'Notice can only be given on a lease that is running.']);
        }
        if ($lease->hasActiveNotice()) {
            throw ValidationException::withMessages(['lease' => 'This lease already has an accepted notice. It has to be reversed before another can be recorded.']);
        }
        if (LeaseNotice::withoutGlobalScopes()->where('lease_id', $lease->id)->where('given_by', $givenBy)->where('status', LeaseNotice::STATUS_RECEIVED)->exists()) {
            throw ValidationException::withMessages(['lease' => ($givenBy === Lease::NOTICE_BY_LANDLORD ? 'A notice from the landlord' : 'A notice from the tenant') . ' is already waiting for review on this lease.']);
        }

        $notice = DB::transaction(function () use ($lease, $givenBy, $data, $via, $contact, $agent, $file) {
            $notice = LeaseNotice::withoutGlobalScopes()->create([
                'agency_id' => $lease->agency_id, 'branch_id' => $lease->branch_id, 'lease_id' => $lease->id,
                'given_by' => $givenBy, 'via' => $via, 'given_by_contact_id' => $contact?->id, 'recorded_by_user_id' => $agent?->id,
                'notice_date' => $data['notice_date'], 'intended_end_date' => $data['intended_end_date'],
                'reason' => $this->clean($data['reason'] ?? null), 'status' => LeaseNotice::STATUS_RECEIVED,
            ]);
            if ($file) {
                $this->attach($notice, $file);
            }
            $this->logEvent($lease, LeaseEvent::TYPE_NOTICE_RECEIVED,
                ($givenBy === Lease::NOTICE_BY_LANDLORD ? 'Landlord' : 'Tenant') . ' notice received' . ($via === LeaseNotice::VIA_AGENT ? ' (recorded by the agent on their behalf)' : ' (from the portal)')
                . ' - given ' . Carbon::parse($data['notice_date'])->format('j M Y') . ', ends ' . Carbon::parse($data['intended_end_date'])->format('j M Y') . ' - waiting for review',
                $agent, ['lease_notice_id' => $notice->id, 'given_by' => $givenBy, 'via' => $via]);

            return $notice;
        });

        $this->tellAgents($notice, $agent);

        return $notice;
    }

    /** The agent corrects a notice that is still waiting for review (dates, reason). Logged: who, from, to. */
    public function update(LeaseNotice $notice, array $data, User $agent): LeaseNotice
    {
        $this->mustBeReceived($notice);
        $changes = [];
        foreach (['notice_date', 'intended_end_date'] as $key) {
            if (isset($data[$key]) && Carbon::parse($data[$key])->toDateString() !== $notice->{$key}->toDateString()) {
                $changes[] = ucfirst(str_replace('_', ' ', $key)) . ' ' . $notice->{$key}->format('j M Y') . ' -> ' . Carbon::parse($data[$key])->format('j M Y');
            }
        }
        if (array_key_exists('reason', $data) && $this->clean($data['reason']) !== $this->clean($notice->reason)) {
            $changes[] = 'Reason changed';
        }
        if ($changes === []) {
            return $notice;
        }
        $notice->update([
            'notice_date' => $data['notice_date'] ?? $notice->notice_date,
            'intended_end_date' => $data['intended_end_date'] ?? $notice->intended_end_date,
            'reason' => array_key_exists('reason', $data) ? $this->clean($data['reason']) : $notice->reason,
        ]);
        $this->logEvent($notice->lease, LeaseEvent::TYPE_NOTICE_EDITED, 'Notice details corrected before review - ' . implode('; ', $changes), $agent, ['lease_notice_id' => $notice->id]);

        return $notice->fresh();
    }

    // ── Decide ──────────────────────────────────────────────────────────────

    /**
     * Accept: the lease's move-out date is set now (LeaseRenewalService::recordNotice - the existing, reversible mechanism).
     *
     * @param array{end_date:string,note?:?string,outcome:string,show_available_from?:?bool} $input
     *
     * @throws ValidationException a missing note (corrected date, or accepting over a warning), a lease that already has a notice
     */
    public function accept(LeaseNotice $notice, User $agent, array $input): LeaseNotice
    {
        $this->mustBeReceived($notice);
        $lease = $notice->lease;
        if ($lease->hasActiveNotice()) {
            throw ValidationException::withMessages(['lease' => 'This lease already has an accepted notice. Reverse it first.']);
        }

        $end = Carbon::parse($input['end_date'])->startOfDay();
        $corrected = $end->toDateString() !== $notice->intended_end_date->toDateString();
        $flags = $this->review->flags($lease, $notice->given_by, $notice->notice_date, $end);
        $note = $this->clean($input['note'] ?? null);

        if ($corrected && $note === null) {
            throw ValidationException::withMessages(['note' => 'You changed the date - say why (the other party and the lease record will see it).']);
        }
        if (! $corrected && $this->review->hasWarning($flags) && $note === null) {
            throw ValidationException::withMessages(['note' => 'This notice has warnings. Say why it is fine as given - you are confirming it is lawful and in line with the lease.']);
        }

        $showAvailable = ($input['outcome'] ?? null) === Lease::NOTICE_OUTCOME_READVERTISE ? ($input['show_available_from'] ?? null) : null;

        DB::transaction(function () use ($notice, $lease, $agent, $input, $end, $corrected, $flags, $note, $showAvailable) {
            app(LeaseRenewalService::class)->recordNotice(
                $lease, $notice->given_by, $end->toDateString(), $this->noteForLease($notice, $note), $agent, $input['outcome'], $showAvailable, $notice->notice_date->toDateString(),
            );
            $notice->update([
                'status' => LeaseNotice::STATUS_ACCEPTED, 'accepted_end_date' => $end->toDateString(),
                'decision_kind' => $corrected ? LeaseNotice::DECISION_CORRECTED : LeaseNotice::DECISION_AS_GIVEN, 'decision_note' => $note,
                'decided_by_user_id' => $agent->id, 'decided_at' => now(), 'property_outcome' => $input['outcome'], 'show_available_from_on_portals' => $showAvailable,
                'flags' => $flags,
            ]);
            $this->logEvent($lease, LeaseEvent::TYPE_NOTICE_DECIDED,
                'Notice accepted' . ($corrected ? ' with a corrected date (given ' . $notice->intended_end_date->format('j M Y') . ', accepted ' . $end->format('j M Y') . ')' : ' as given') . ' - move-out ' . $end->format('j M Y')
                . ($note ? ' - ' . $note : '') . ($this->review->hasWarning($flags) ? ' - warnings: ' . implode('; ', array_column(array_filter($flags, fn ($f) => $f['severity'] === 'warn'), 'title')) : ''),
                $agent, ['lease_notice_id' => $notice->id, 'decision' => $corrected ? 'corrected' : 'as_given', 'warnings' => array_column($flags, 'code')]);
        });

        $notice = $notice->fresh();
        $this->tellOtherParty($notice, $agent);

        return $notice->fresh();
    }

    /** Reject with a reason. The person who gave it is told (portal status + an email); the other party is told nothing. */
    public function reject(LeaseNotice $notice, User $agent, string $reason): LeaseNotice
    {
        $this->mustBeReceived($notice);
        $reason = $this->clean($reason);
        if ($reason === null) {
            throw ValidationException::withMessages(['reason' => 'Say why the notice is not accepted - it is sent to the person who gave it.']);
        }

        $flags = $this->review->flags($notice->lease, $notice->given_by, $notice->notice_date, $notice->intended_end_date);
        $notice->update([
            'status' => LeaseNotice::STATUS_REJECTED, 'decision_kind' => LeaseNotice::DECISION_REJECTED, 'decision_note' => $reason,
            'decided_by_user_id' => $agent->id, 'decided_at' => now(), 'flags' => $flags,
        ]);
        $this->logEvent($notice->lease, LeaseEvent::TYPE_NOTICE_DECIDED, 'Notice rejected - ' . $reason, $agent, ['lease_notice_id' => $notice->id, 'decision' => 'rejected']);

        $this->tellGiverOfRejection($notice);

        return $notice->fresh();
    }

    /** The lease's accepted notice was reversed (Lease actions -> Reverse notice): the case is withdrawn, never erased. */
    public function withdrawAcceptedFor(Lease $lease, User $agent): void
    {
        LeaseNotice::withoutGlobalScopes()->where('lease_id', $lease->id)->where('status', LeaseNotice::STATUS_ACCEPTED)->whereNull('deleted_at')->get()
            ->each(function (LeaseNotice $notice) use ($lease, $agent) {
                $notice->update(['status' => LeaseNotice::STATUS_WITHDRAWN]);
                $this->logEvent($lease, LeaseEvent::TYPE_NOTICE_DECIDED, 'Accepted notice withdrawn (the notice was reversed on the lease)', $agent, ['lease_notice_id' => $notice->id, 'decision' => 'withdrawn']);
            });
    }

    public function archive(LeaseNotice $notice, User $agent): void
    {
        $notice->delete();
        $this->logEvent($notice->lease, LeaseEvent::TYPE_NOTICE_EDITED, 'Notice case archived', $agent, ['lease_notice_id' => $notice->id]);
    }

    public function restore(LeaseNotice $notice, User $agent): void
    {
        $notice->restore();
        $this->logEvent($notice->lease, LeaseEvent::TYPE_NOTICE_EDITED, 'Notice case restored', $agent, ['lease_notice_id' => $notice->id]);
    }

    // ── Attachment ──────────────────────────────────────────────────────────

    public function attach(LeaseNotice $notice, UploadedFile $file): void
    {
        $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $file->getClientOriginalName()) ?: 'attachment';
        $path = "lease-notices/{$notice->lease_id}/" . uniqid() . '-' . $name;
        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));
        $notice->update(['attachment_path' => $path, 'attachment_name' => $name, 'attachment_mime' => $file->getClientMimeType(), 'attachment_size' => $file->getSize()]);
    }

    // ── Telling people ──────────────────────────────────────────────────────

    /** Both lease agents (the agent who recorded it excluded): the normal in-app alert AND a direct email each. */
    private function tellAgents(LeaseNotice $notice, ?User $actor): void
    {
        try {
            $lease = $notice->lease;
            $property = $lease->property()->withoutGlobalScopes()->first();
            if (! $property) {
                return;
            }
            $address = $property->buildDisplayAddress() ?: ('Property #' . $property->id);
            $who = $notice->given_by === Lease::NOTICE_BY_LANDLORD ? 'The landlord' : 'The tenant';
            $title = 'Notice received - needs review - ' . $address;
            $body = $who . ' gave notice on ' . $notice->notice_date->format('j M Y') . ' to end the lease on ' . $notice->intended_end_date->format('j M Y')
                . ($notice->reason ? ' (' . mb_substr($notice->reason, 0, 200) . ')' : '') . '. Review it before anything goes to the other party.';
            $url = route('corex.lease-notices.show', $notice->id);
            $agents = app(LeaseAgentService::class)->recipientsForEvent($lease, $property, (int) $lease->agency_id, $actor);
            foreach ($agents as $agent) {
                try {
                    app(NotificationDispatcher::class)->fire($agent, self::EVENT_RECEIVED, $notice, [
                        'title' => $title, 'body' => $body, 'action_url' => $url, 'severity' => 'warning', 'threshold_hit_at' => now(),
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('Lease notice: in-app alert failed', ['agent' => $agent->id, 'error' => $e->getMessage()]);
                }
            }
            foreach ($agents->filter(fn ($a) => $a->email)->unique('email') as $agent) {
                Mail::to($agent->email)->send(new RentalOwnerNoticeToAgentMail($agent->name, $title, $body, $url));
            }
        } catch (\Throwable $e) {
            Log::warning('Lease notice: could not tell the agents', ['notice' => $notice->id, 'error' => $e->getMessage()]);
        }
    }

    /** Accepted: the other party hears about it (an email from the accepting agent; they also see it in their portal's Notices tab). */
    private function tellOtherParty(LeaseNotice $notice, User $agent): void
    {
        try {
            $lease = $notice->lease;
            $recipients = $notice->otherParty() === Lease::NOTICE_BY_TENANT ? $lease->tenantContacts() : $lease->landlordContacts();
            $told = [];
            foreach ($recipients as $contact) {
                if ($contact->email) {
                    app(RentalMailDispatcher::class)->send($contact->email, new LeaseNoticeAcceptedMail($notice, $contact, $agent));
                }
                $told[] = trim((string) $contact->full_name) ?: ucfirst($notice->otherParty());
            }
            $notice->forceFill(['other_party_told_at' => now()])->save();
            $this->logEvent($lease, LeaseEvent::TYPE_NOTICE_SENT, 'The ' . $notice->otherParty() . ' was told the notice was accepted - ' . ($told ? implode(', ', array_unique($told)) : 'no one linked'), $agent, ['lease_notice_id' => $notice->id]);
        } catch (\Throwable $e) {
            Log::warning('Lease notice: could not tell the other party', ['notice' => $notice->id, 'error' => $e->getMessage()]);
        }
    }

    private function tellGiverOfRejection(LeaseNotice $notice): void
    {
        if ($notice->via !== LeaseNotice::VIA_PORTAL || ! $notice->givenByContact?->email) {
            return; // the agent who recorded it on their behalf already knows
        }
        try {
            Mail::to($notice->givenByContact->email)->send(new LeaseNoticeRejectedMail($notice));
        } catch (\Throwable $e) {
            Log::warning('Lease notice: could not email the rejection', ['notice' => $notice->id, 'error' => $e->getMessage()]);
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function mustBeReceived(LeaseNotice $notice): void
    {
        if (! $notice->isReceived()) {
            throw ValidationException::withMessages(['notice' => 'This notice has already been dealt with.']);
        }
    }

    /** What the lease's own notice_note carries: the reason given, then the agent's note. */
    private function noteForLease(LeaseNotice $notice, ?string $note): ?string
    {
        $parts = array_filter([$notice->reason ? 'Reason given: ' . $notice->reason : null, $note ? 'Agent: ' . $note : null]);

        return $parts === [] ? null : mb_substr(implode(' | ', $parts), 0, 500);
    }

    private function clean(?string $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    private function logEvent(Lease $lease, string $type, string $description, ?User $user, array $metadata = []): void
    {
        LeaseEvent::create([
            'lease_id' => $lease->id, 'event_type' => $type, 'description' => $description, 'actor_user_id' => $user?->id,
            'metadata' => $metadata ?: null, 'occurred_at' => now(), 'created_at' => now(),
        ]);
    }
}
