<?php

namespace App\Services\Rentals;

use App\Events\Rentals\LeaseAgreementFailed;
use App\Events\Rentals\LeaseAgreementSigned;
use App\Mail\Rentals\LeaseAgreementStatusMail;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\User;
use App\Notifications\SignatureActivityNotification;
use App\Services\CommandCenter\NotificationDispatcher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/leases.md §15.5 / §15.15 (Build L3b) — keeps a lease's `signing_status` in step with its e-sign envelope,
 * and finishes the lease when the agreement is signed and approved.
 *
 * There are two callers and ONE implementation: the `UpdateLeaseSigningState` listener (the e-sign engine announces
 * sent / finalised / declined / cancelled / expired) and the safety net (`Lease::reconcileSigning()`, the nightly
 * `leases:reconcile-signing`) which re-reads the envelope when an announcement was missed. Both go through
 * apply(), which is idempotent: it only ever moves a lease whose agreement is still live, so the same event twice,
 * or an event and a re-check, change nothing the second time.
 *
 * What it never does: touch the e-sign engine, overwrite a lease value from the document (that is Build L3c's
 * confirm step), or let a lease-side fault disturb a signing that has already legally completed.
 */
class LeaseSigningStateService
{
    public function __construct(
        private readonly LeaseAgreementCheck $check,
        private readonly LeaseAgreementHarvest $harvest,
        private readonly RentalMailDispatcher $mail,
    ) {}

    /**
     * An engine announcement about an envelope: update every lease whose agreement it is. Normally one.
     *
     * `$justSent` is the "a signing request just went out" announcement. The engine emits it from inside the step
     * that invites the next signer, BEFORE it updates the envelope's own status, so the envelope can still read as
     * the previous step (even "pending agent approval"); a request that has just gone out means out for signing,
     * whatever the envelope says in that instant. The next re-check reads the settled status.
     *
     * @return int how many leases were looked at
     */
    public function applyEnvelope(SignatureTemplate $envelope, ?string $reason = null, ?int $actorUserId = null, bool $justSent = false): int
    {
        $leases = Lease::withoutGlobalScopes()->where('signature_template_id', $envelope->id)->get();

        foreach ($leases as $lease) {
            $this->apply($lease, $envelope, $reason, $actorUserId, $justSent);
        }

        return $leases->count();
    }

    /**
     * The safety net for one lease: re-read its envelope and apply whatever it now says. Never throws.
     *
     * @return bool whether the lease changed
     */
    public function reconcile(Lease $lease): bool
    {
        try {
            if (! in_array($lease->signing_status, Lease::SIGNING_IN_FLIGHT, true) || ! $lease->signature_template_id) {
                return false;
            }

            $envelope = SignatureTemplate::withoutGlobalScopes()->find($lease->signature_template_id);
            if (! $envelope) {
                return false;
            }

            $before = [$lease->signing_status, $lease->status];
            $this->apply($lease, $envelope);
            $lease->refresh();
            $this->noteEditsMade($lease);

            return $before !== [$lease->signing_status, $lease->status];
        } catch (\Throwable $e) {
            Log::warning('Lease signing: re-check failed', ['lease_id' => $lease->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * §15.8.4 #3 — while the agreement is out, whoever changed a lease-relevant value in it, by whichever route, the
     * end state differs from the lease. The first time that is seen (and again after each further edit — the printed
     * values' fingerprint is the memory) one tenancy-log line says what changed. Information only: it blocks nothing,
     * and the lease record is untouched until the agent confirms at approval. Never throws.
     */
    private function noteEditsMade(Lease $lease): void
    {
        try {
            if (! in_array($lease->signing_status, [Lease::SIGNING_OUT_FOR_SIGNING, Lease::SIGNING_AWAITING_AGENT_REVIEW], true)) {
                return;
            }

            $verdict = $this->check->verdict($lease);
            if (! $verdict['applicable'] || ! $verdict['has_differences']) {
                return;
            }

            $last = $lease->events()->where('event_type', LeaseEvent::TYPE_AGREEMENT_EDITED)->latest('id')->first();
            if ($last && ($last->metadata['fingerprint'] ?? null) === $verdict['fingerprint']) {
                return;
            }

            $summary = collect($verdict['differences'])->take(3)
                ->map(fn ($d) => "{$d['label']} " . ($d['lease'] ?? 'not on record') . ' → ' . ($d['agreement'] ?? '—'))
                ->implode('; ');
            $more = count($verdict['differences']) > 3 ? ' (and ' . (count($verdict['differences']) - 3) . ' more)' : '';

            $this->event($lease, LeaseEvent::TYPE_AGREEMENT_EDITED, mb_substr("The agreement was changed in e-sign: {$summary}{$more}", 0, 500), null, [
                'fingerprint' => $verdict['fingerprint'],
                'differences' => collect($verdict['differences'])->map(fn ($d) => ['key' => $d['key'], 'lease' => $d['lease'], 'agreement' => $d['agreement']])->all(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Lease signing: could not compare the agreement with the lease', ['lease_id' => $lease->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Apply the envelope's current state to the lease. Only a lease whose agreement IS this envelope and is still live
     * moves; everything else is a no-op, which is what makes it idempotent.
     */
    public function apply(Lease $lease, SignatureTemplate $envelope, ?string $reason = null, ?int $actorUserId = null, bool $justSent = false): bool
    {
        if ((int) $lease->signature_template_id !== (int) $envelope->id
            || ! in_array($lease->signing_status, Lease::SIGNING_IN_FLIGHT, true)) {
            return false;
        }

        $envelope = $envelope->fresh() ?? $envelope;
        $target = Lease::signingStatusFor($envelope);
        if ($justSent && in_array($target, [Lease::SIGNING_OUT_FOR_SIGNING, Lease::SIGNING_AWAITING_AGENT_REVIEW], true)) {
            $target = Lease::SIGNING_OUT_FOR_SIGNING;
        }

        return match ($target) {
            Lease::SIGNING_SIGNED => $this->finalise($lease, $envelope, $actorUserId),
            Lease::SIGNING_DECLINED, Lease::SIGNING_VOIDED, Lease::SIGNING_EXPIRED => $this->fail($lease, $envelope, $target, $reason, $actorUserId),
            default => $this->follow($lease, $target),
        };
    }

    // ── Still in flight: out for signing ⇄ waiting for the agent's approval ──────────────────

    private function follow(Lease $lease, string $target): bool
    {
        if ($lease->signing_status === $target) {
            return false;
        }

        $lease->update(['signing_status' => $target]);

        return true;
    }

    // ── Signed and approved ──────────────────────────────────────────────────────────────────

    /**
     * §15.5 "On signed" — one transaction, idempotent on `signed_at`: the lease records when it was signed and who
     * approved it, takes the signed document as its source, the agreement's printed values are harvested for the next
     * renewal, and the lease is activated (a renewal through its own activation, which expires the previous term and
     * records the escalation). The signed copy is already filed by e-sign's own cascade; the lease finds it through
     * Lease::signedDocument().
     */
    private function finalise(Lease $lease, SignatureTemplate $envelope, ?int $actorUserId): bool
    {
        $actor = $this->actorFor($envelope, $actorUserId);

        $outcome = DB::transaction(function () use ($lease, $envelope, $actor) {
            /** @var Lease|null $locked */
            $locked = Lease::withoutGlobalScopes()->whereKey($lease->id)->lockForUpdate()->first();
            if (! $locked || $locked->signed_at !== null || ! in_array($locked->signing_status, Lease::SIGNING_IN_FLIGHT, true)) {
                return null;
            }

            $signedAt = $envelope->completed_at ?? now();
            $documentId = $locked->agreement_document_id ?: $envelope->document_id;

            $locked->forceFill([
                'signing_status' => Lease::SIGNING_SIGNED,
                'signed_at' => $signedAt,
                'accepted_at' => $signedAt,
                'accepted_by_user_id' => $actor?->id,
                'source' => Lease::SOURCE_ESIGN_DOCUMENT,
                'source_document_id' => $documentId,
                'agreement_document_id' => $documentId,
                'signing_failure_note' => null,
            ])->save();

            $this->event($locked, LeaseEvent::TYPE_AGREEMENT_SIGNED, 'Lease agreement signed by everyone', $actor, [
                'signature_template_id' => $envelope->id, 'document_id' => $documentId,
            ]);
            $this->event($locked, LeaseEvent::TYPE_AGREEMENT_ACCEPTED, 'Lease agreement approved' . ($actor ? ' by ' . $actor->name : ''), $actor, [
                'signature_template_id' => $envelope->id,
            ]);

            $activated = false;
            $note = null;

            // §15.8.4 #2 — the net under every approval path: re-read the agreement and compare it with the lease
            // BEFORE anything is harvested from it (the harvest writes the document's values into the lease's
            // agreement details, which would make every difference vanish). A check that cannot run is treated as
            // "needs confirming" — the lease may not go live on an agreement nobody has checked.
            try {
                $verdict = $this->check->verdict($locked);
                $needsConfirmation = $verdict['needs_confirmation'];
                $differences = $verdict['differences'];
            } catch (\Throwable $e) {
                Log::error('Lease signing: the agreement could not be compared with the lease', ['lease_id' => $locked->id, 'error' => $e->getMessage()]);
                $needsConfirmation = true;
                $differences = [];
            }

            if (! $needsConfirmation) {
                // What was printed in the agreement (including anything corrected in Fill & review) reaches the lease's
                // agreement details, so the next renewal pre-fills from what was actually signed. Never blocks acceptance.
                $this->harvestFrom($locked, $documentId);
            }

            if ($needsConfirmation) {
                // §15.5 — an approval that skipped the confirm step (wet-ink signing, an unattended completion, an
                // engine path that never touches the route): signed, but the lease may not go live while it disagrees
                // with its own agreement. The agent confirms on the lease screen, then the lease activates.
                $this->event($locked, LeaseEvent::TYPE_AGREEMENT_NEEDS_CONFIRMATION, 'Signed — confirm the lease details before it goes active', $actor, [
                    'differences' => collect($differences)->map(fn ($d) => ['key' => $d['key'], 'lease' => $d['lease'], 'agreement' => $d['agreement']])->all(),
                ]);
                $note = 'Signed — confirm the lease details.';
            } else {
                [$locked, $activated, $note] = $this->goLive($locked, $actor, $signedAt);
            }

            return ['lease' => $locked->fresh(), 'activated' => $activated, 'note' => $note];
        });

        if ($outcome === null) {
            return false;
        }

        $this->announceSigned($outcome['lease'], $envelope, $actor, $outcome['activated'], $outcome['note']);

        return true;
    }

    /**
     * Signed and in agreement with its lease: the lease goes active (a renewal through its own activation, which expires
     * the previous term and records the escalation). If another lease is still active on the property the lease stays
     * a draft, visibly, until that one is ended or renewed — signed is never lost (§15.5).
     *
     * @return array{0: Lease, 1: bool, 2: ?string} the lease, whether it is now active, and the reason it is not
     */
    private function goLive(Lease $locked, ?User $actor, \DateTimeInterface $signedAt): array
    {
        try {
            $locked = $this->activate($locked, $actor);
            $this->event($locked, LeaseEvent::TYPE_LEASE_ACTIVATED_BY_SIGNING, 'Lease active — signed on ' . $signedAt->format('d M Y'), $actor, [
                'start_date' => optional($locked->start_date)->toDateString(), 'end_date' => optional($locked->end_date)->toDateString(),
            ]);

            return [$locked, true, null];
        } catch (ValidationException $e) {
            $note = collect($e->errors())->flatten()->first() ?: 'Another lease is still active on this property.';
            $this->event($locked, LeaseEvent::TYPE_SIGNED_NOT_ACTIVATED, 'Signed, not yet active — ' . $note, $actor, ['reason' => $note]);
            Log::warning('Lease signing: signed but not activated', ['lease_id' => $locked->id, 'reason' => $note]);

            return [$locked, false, $note];
        }
    }

    /**
     * §15.9 "Same screen after completion" — a lease that was signed with a difference nobody had confirmed has just had
     * its details confirmed by the agent. The agent's confirmation IS the final approval of what the lease now says
     * (R5), so the approval is stamped now, and the lease goes active exactly as an ordinary signing would have.
     *
     * @return array{activated: bool, note: ?string}|null null when the lease is not a signed draft waiting for this
     */
    public function activateConfirmed(Lease $lease, User $actor): ?array
    {
        $outcome = DB::transaction(function () use ($lease, $actor) {
            /** @var Lease|null $locked */
            $locked = Lease::withoutGlobalScopes()->whereKey($lease->id)->lockForUpdate()->first();
            if (! $locked || $locked->signing_status !== Lease::SIGNING_SIGNED || $locked->status !== Lease::STATUS_DRAFT) {
                return null;
            }

            $locked->forceFill(['accepted_at' => now(), 'accepted_by_user_id' => $actor->id])->save();
            $this->event($locked, LeaseEvent::TYPE_AGREEMENT_ACCEPTED, "Lease details confirmed and approved by {$actor->name}", $actor, []);

            [$locked, $activated, $note] = $this->goLive($locked, $actor, $locked->signed_at ?? now());

            return ['lease' => $locked->fresh(), 'activated' => $activated, 'note' => $note];
        });

        if ($outcome === null) {
            return null;
        }

        $envelope = $outcome['lease']->signature_template_id ? SignatureTemplate::withoutGlobalScopes()->find($outcome['lease']->signature_template_id) : null;
        if ($envelope) {
            $this->announceSigned($outcome['lease'], $envelope, $actor, $outcome['activated'], $outcome['note']);
        }

        return ['activated' => $outcome['activated'], 'note' => $outcome['note']];
    }

    private function activate(Lease $lease, ?User $actor): Lease
    {
        // A renewal always records its escalation and the "renewal activated" line - also when the completion was
        // noticed by the safety net (no acting user): the actor is optional there, the bookkeeping is not.
        if ($lease->previous_lease_id) {
            return app(LeaseRenewalService::class)->activateRenewalTerm($lease, $actor);
        }

        return app(LeaseActivationService::class)->activate($lease);
    }

    private function harvestFrom(Lease $lease, ?int $documentId): void
    {
        try {
            $document = $documentId ? Document::withoutGlobalScopes()->find($documentId) : null;
            if ($document) {
                $this->harvest->fromDocument($lease, $document);
            }
        } catch (\Throwable $e) {
            Log::warning('Lease signing: could not read the signed agreement back into the lease', ['lease_id' => $lease->id, 'error' => $e->getMessage()]);
        }
    }

    // ── Declined, cancelled, expired ─────────────────────────────────────────────────────────

    /**
     * The lease stays a draft with the right status; the agent is told (declined / expired) with the reason, and the
     * Lease Hub card offers "Prepare again". A cancel the agent made themselves needs no telling.
     */
    private function fail(Lease $lease, SignatureTemplate $envelope, string $outcome, ?string $reason, ?int $actorUserId): bool
    {
        $reason = $this->reasonFor($envelope, $outcome, $reason);
        $actor = $outcome === Lease::SIGNING_VOIDED ? ($actorUserId ? User::withoutGlobalScopes()->find($actorUserId) : null) : null;

        $changed = DB::transaction(function () use ($lease, $envelope, $outcome, $reason, $actor) {
            $locked = Lease::withoutGlobalScopes()->whereKey($lease->id)->lockForUpdate()->first();
            if (! $locked || ! in_array($locked->signing_status, Lease::SIGNING_IN_FLIGHT, true)) {
                return null;
            }

            $locked->update(['signing_status' => $outcome, 'signing_failure_note' => mb_substr($reason, 0, 500)]);

            [$type, $text] = match ($outcome) {
                Lease::SIGNING_DECLINED => [LeaseEvent::TYPE_AGREEMENT_DECLINED, 'Lease agreement declined — ' . $reason],
                Lease::SIGNING_VOIDED => [LeaseEvent::TYPE_AGREEMENT_VOIDED, 'Lease agreement cancelled — ' . $reason],
                default => [LeaseEvent::TYPE_AGREEMENT_EXPIRED, 'Lease agreement expired — ' . $reason],
            };
            $this->event($locked, $type, $text, $actor, ['reason' => $reason, 'signature_template_id' => $envelope->id]);

            return $locked->fresh();
        });

        if ($changed === null) {
            return false;
        }

        $this->dispatchDomainEvent(fn () => new LeaseAgreementFailed($changed, $outcome, $reason, $actor?->id));

        if ($outcome !== Lease::SIGNING_VOIDED) {
            $this->tellAgent($changed, $envelope, $outcome === Lease::SIGNING_DECLINED ? LeaseAgreementStatusMail::OUTCOME_DECLINED : LeaseAgreementStatusMail::OUTCOME_EXPIRED, $reason);
        }

        return true;
    }

    private function reasonFor(SignatureTemplate $envelope, string $outcome, ?string $given): string
    {
        $given = trim((string) $given);
        if ($given !== '') {
            return $given;
        }

        if ($outcome === Lease::SIGNING_VOIDED) {
            return trim((string) $envelope->cancellation_reason) ?: 'cancelled in e-sign';
        }

        if ($outcome === Lease::SIGNING_EXPIRED) {
            return 'the signing links ran out before everyone signed';
        }

        $declined = $envelope->requests()->where('status', 'declined')->latest('id')->first();
        $why = trim((string) $envelope->rejection_reason);
        if ($why !== '') {
            return $why;
        }

        return $declined ? trim($declined->signer_name . ' declined to sign') : 'declined';
    }

    // ── Telling people, and the lease's own events ───────────────────────────────────────────

    private function announceSigned(Lease $lease, SignatureTemplate $envelope, ?User $actor, bool $activated, ?string $note): void
    {
        $this->dispatchDomainEvent(fn () => new LeaseAgreementSigned($lease, $envelope->id, $actor?->id));

        // rental-portal-access.md §16 — signed: its tenant(s) and landlord(s) get portal access (agency setting, default ON).
        // Best effort — a fault here never undoes the signing.
        try {
            app(RentalPortalAccessService::class)->provisionForSignedLease($lease);
        } catch (\Throwable $e) {
            Log::warning('Portal access on signing failed', ['lease_id' => $lease->id, 'error' => $e->getMessage()]);
        }

        $this->tellAgent(
            $lease,
            $envelope,
            $activated ? LeaseAgreementStatusMail::OUTCOME_SIGNED : LeaseAgreementStatusMail::OUTCOME_SIGNED_NOT_ACTIVE,
            $note,
            $actor,
        );
    }

    /** In-app notification and one mail to the agent who sent the agreement. Best effort: a fault here never undoes the lease. */
    private function tellAgent(Lease $lease, SignatureTemplate $envelope, string $outcome, ?string $detail, ?User $agent = null): void
    {
        try {
            $agent ??= $envelope->created_by ? User::withoutGlobalScopes()->find($envelope->created_by) : null;
            if (! $agent) {
                return;
            }

            $address = $lease->property?->buildDisplayAddress() ?: ('Property #' . $lease->property_id);
            $message = match ($outcome) {
                LeaseAgreementStatusMail::OUTCOME_SIGNED => "The lease agreement for {$address} is signed and the lease is now active",
                LeaseAgreementStatusMail::OUTCOME_SIGNED_NOT_ACTIVE => "The lease agreement for {$address} is signed — the lease is not active yet",
                LeaseAgreementStatusMail::OUTCOME_DECLINED => "The lease agreement for {$address} was declined" . ($detail ? " — {$detail}" : ''),
                default => "The lease agreement for {$address} expired before everyone signed",
            };

            app(NotificationDispatcher::class)->send(
                $agent, 'lease.agreement_signing_outcome', $envelope,
                new SignatureActivityNotification(
                    type: 'lease_agreement_' . $outcome,
                    message: $message,
                    url: route('corex.leases.show', $lease->id),
                    documentId: $envelope->document_id,
                    metadata: ['lease_id' => $lease->id],
                ),
                ['threshold_hit_at' => now()],
            );

            if ($agent->email) {
                $this->mail->send($agent->email, (new LeaseAgreementStatusMail($lease, $outcome, $detail))->fromAgent($agent));
            }
        } catch (\Throwable $e) {
            Log::warning('Lease signing: could not tell the agent', ['lease_id' => $lease->id, 'outcome' => $outcome, 'error' => $e->getMessage()]);
        }
    }

    private function dispatchDomainEvent(\Closure $make): void
    {
        try {
            event($make());
        } catch (\Throwable $e) {
            Log::warning('Lease signing: a domain event listener failed', ['error' => $e->getMessage()]);
        }
    }

    /** The agent whose approval completed the document: the signed-in user when there is one, else whoever sent it. */
    private function actorFor(SignatureTemplate $envelope, ?int $actorUserId): ?User
    {
        $id = $actorUserId ?: (Auth::check() ? Auth::id() : null) ?: $envelope->created_by;

        return $id ? User::withoutGlobalScopes()->find($id) : null;
    }

    /** @param array<string,mixed> $metadata */
    private function event(Lease $lease, string $type, string $description, ?User $actor, array $metadata = []): void
    {
        LeaseEvent::create([
            'lease_id' => $lease->id,
            'event_type' => $type,
            'description' => $description,
            'actor_user_id' => $actor?->id,
            'metadata' => $metadata,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }
}
