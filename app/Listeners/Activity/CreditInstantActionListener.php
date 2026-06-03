<?php

declare(strict_types=1);

namespace App\Listeners\Activity;

use App\Events\Contact\ContactCreated;
use App\Events\Deal\DealClosed;
use App\Events\Deal\DealCreated;
use App\Events\Deal\DealStageAdvanced;
use App\Events\Deal\DealStatusChanged;
use App\Events\Compliance\RcrSubmissionSubmitted;
use App\Events\Fica\FicaApproved;
use App\Events\Fica\FicaRejected;
use App\Events\Fica\FicaSubmitted;
use App\Events\Marketing\MarketingPostPublished;
use App\Events\PresentationGenerated;
use App\Events\Presentation\PresentationOutcomeRecorded;
use App\Events\Property\PropertyCaptured;
use App\Events\Property\PropertyCompliancePassed;
use App\Events\Property\PropertyPublished;
use App\Events\Prospecting\ClaimCreated;
use App\Events\Prospecting\ClaimReleased;
use App\Events\Prospecting\TrackedPropertyPromotedToStock;
use App\Events\SellerOutreach\PitchSent;
use App\Models\Compliance\Rcr\RcrSubmission;
use App\Models\Presentation;
use App\Models\PresentationOutcome;
use App\Models\Property;
use App\Models\PropertyMarketingPost;
use App\Models\ProspectingClaim;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Activity\InstantPointService;
use App\Services\Activity\ParticipantResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SPINE-2 — listener that translates Phase-1 high-value existing-dispatch
 * domain events into InstantPointService credits / revokes.
 *
 * GOVERNING PRINCIPLE (frozen in SPINE-1, re-asserted here per Johan):
 *   SCORE THE ACTION, NOT THE OUTCOME. credit() fires the moment the
 *   action happens. Outcomes (won/lost/approved/rejected/declined) do
 *   NOT gate or revoke. Only GENUINE REVERSALS revoke — un-register a
 *   registered deal, soft-delete the deal. A lost / declined / rejected
 *   action: agent KEEPS the points.
 *
 * Mapping (event → slug):
 *
 *   ContactCreated                     → contact.captured
 *   DealCreated                        → deal.created
 *   DealStageAdvanced                  → deal.stage_advanced (every fwd hop)
 *   DealClosed (outcome=won)           → deal.registered
 *   DealClosed (outcome=lost/abandoned) → NO CREDIT (rule: action, not outcome)
 *   DealStatusChanged (R → not-R)      → REVOKE deal.registered  (un-registered)
 *   PresentationGenerated              → presentation.generated (idempotent
 *                                         per (presentation, day) via
 *                                         InstantPointService's
 *                                         updateOrCreate key)
 *   PresentationOutcomeRecorded (won*) → presentation.won
 *   PresentationOutcomeRecorded (lost*)→ NO CREDIT here (presentation.lost
 *                                         is seeded but credited at a
 *                                         later phase if Johan opts in;
 *                                         this commit follows the prompt's
 *                                         explicit Phase-1 list)
 *   PitchSent                          → outreach.pitch_sent
 *   TrackedPropertyPromotedToStock     → tracked_property.promoted_to_stock
 *   RcrSubmissionSubmitted             → rcr.submitted
 *
 * Deal soft-delete reversal lives in DealObserver::deleted (not here) so
 * the Eloquent `deleted` model event drives it without a custom domain
 * event having to be invented. The observer calls
 * InstantPointService::revoke for each deal.* slug.
 *
 * NON-NEGOTIABLE SAFETY:
 *   Every handler is wrapped through {@see safeCredit()} / {@see safeRevoke()}
 *   which catch + log + swallow EVERY exception. The agent's underlying
 *   save (deal create, contact create, pitch send) MUST complete even if
 *   the points layer crashes. Defence-in-depth: InstantPointService
 *   already wraps internally per SPINE-1; this listener-level catch is the
 *   second layer.
 */
final class CreditInstantActionListener
{
    public function __construct(
        private readonly InstantPointService $svc,
        private readonly ParticipantResolver $resolver,
    ) {}

    // ───────────────────────────────────────────────────────────────────
    // CREDITS
    // ───────────────────────────────────────────────────────────────────

    public function handleContactCreated(ContactCreated $event): void
    {
        $this->safeCredit('contact.captured', $this->user($event->actorUserId), $event->contact);
    }

    public function handleDealCreated(DealCreated $event): void
    {
        // SPINE-2.5 — multi-actor: creator + listing-side agents +
        // selling-side agents. Each participant scores independently
        // through safeCredit, so one participant's failure (NULL user,
        // missing agency, mapping miss) never blocks the others.
        $this->creditParticipants(
            $this->resolver->resolveForDealCreated($event->deal, $event->actorUserId),
            $event->deal,
        );
    }

    public function handleDealStageAdvanced(DealStageAdvanced $event): void
    {
        // Each forward main-status hop (P→G→R) credits its own row. Same
        // (user, day, deal) subject pair gets ONE row per
        // activity_definition_id (slug) — so re-firing the same transition
        // is idempotent, but P→G + G→R produce two distinct rows because
        // they happen on different days in practice.
        $this->safeCredit('deal.stage_advanced', $this->user($event->actorUserId), $event->deal);
    }

    public function handleDealClosed(DealClosed $event): void
    {
        // SCORE THE ACTION, NOT THE OUTCOME — lost / abandoned deals do
        // NOT credit deal.registered. The agent already earned points for
        // deal.created and deal.stage_advanced; those stay. Only outcome=won
        // (= deal registered) hands out the registration credit.
        if ($event->outcome !== 'won') {
            return;
        }
        // SPINE-2.5 — multi-actor: creator + each listing-side agent +
        // each selling-side agent each get their own registration credit
        // under per-role slugs. Per-side weights are agency-configurable
        // via the role-slug mapping rows.
        $this->creditParticipants(
            $this->resolver->resolveForDealRegistered($event->deal, $event->actorUserId),
            $event->deal,
        );
    }

    public function handleDealStatusChanged(DealStatusChanged $event): void
    {
        // REVERSAL: accepted_status flipping FROM 'R' to anything else
        // means the deal was un-registered. Revoke ALL three registration
        // slugs (creator + both sides) — per Johan: "un-register revokes
        // deal.registered for credited sides; capture stays." Each
        // safeRevoke is independent — one slug failing never stops the
        // others. deal.created / deal.listing_side / deal.selling_side
        // stay credited (those are the capture moment, not the win).
        if ((string) $event->fromStatus === 'R' && (string) $event->toStatus !== 'R') {
            $this->safeRevoke('deal.registered',               $event->deal, 'deal_unregistered');
            $this->safeRevoke('deal.registered.listing_side',  $event->deal, 'deal_unregistered');
            $this->safeRevoke('deal.registered.selling_side',  $event->deal, 'deal_unregistered');
        }
    }

    public function handlePresentationGenerated(PresentationGenerated $event): void
    {
        // Idempotency: InstantPointService keys on (user, day, def,
        // subject) — same Presentation regenerated on the same day
        // updates the existing row, not a new one. Re-generation across
        // days would credit again, which is acceptable per the audit.
        $actor = User::find($event->presentation->created_by_user_id);
        $this->safeCredit('presentation.generated', $actor, $event->presentation);
    }

    public function handlePresentationOutcomeRecorded(PresentationOutcomeRecorded $event): void
    {
        // V1: credit presentation.won when the outcome is in the WON_OUTCOMES
        // set (won_mandate or won_sale). presentation.lost is seeded but not
        // credited from this listener — the prompt's Phase-1 list explicitly
        // names presentation.won only.
        if (!in_array($event->outcome, PresentationOutcome::WON_OUTCOMES, true)) {
            return;
        }
        $subject = Presentation::find($event->presentationId);
        $this->safeCredit('presentation.won', $this->user($event->actorUserIdValue), $subject);
    }

    public function handlePitchSent(PitchSent $event): void
    {
        $this->safeCredit('outreach.pitch_sent', $this->user($event->actorUserId), $event->send);
    }

    public function handleTrackedPropertyPromotedToStock(TrackedPropertyPromotedToStock $event): void
    {
        $subject = TrackedProperty::withoutGlobalScopes()->find($event->trackedPropertyId);
        $this->safeCredit('tracked_property.promoted_to_stock', $this->user($event->actorUserId), $subject);
    }

    public function handleRcrSubmissionSubmitted(RcrSubmissionSubmitted $event): void
    {
        $subject = RcrSubmission::find($event->submissionId);
        $this->safeCredit('rcr.submitted', $this->user($event->actorUserIdValue), $subject);
    }

    // ───────────────────────────────────────────────────────────────────
    // SPINE-3 — compliance / FICA
    //
    // Johan's V1 rule: action-not-outcome. fica.submitted credits the
    // agent for sending the package; fica.reviewed credits the
    // compliance officer for doing the review work, regardless of
    // whether they approved OR rejected. Same handler subscribes to
    // BOTH FicaApproved and FicaRejected so the credit fires either
    // way. No revoke on rejection — the review work was real.
    // ───────────────────────────────────────────────────────────────────

    public function handleFicaSubmitted(FicaSubmitted $event): void
    {
        $this->safeCredit('fica.submitted', $this->user($event->actorUserId), $event->package);
    }

    public function handleFicaApprovedReview(FicaApproved $event): void
    {
        $this->safeCredit('fica.reviewed', $this->user($event->approvedByUserId), $event->package);
    }

    public function handleFicaRejectedReview(FicaRejected $event): void
    {
        // Same slug as approved — the action that scores is "a CO did
        // the review", regardless of decision. The agent does NOT lose
        // their fica.submitted points either; only revoke happens on
        // genuine reversal.
        $this->safeCredit('fica.reviewed', $this->user($event->actorUserId), $event->package);
    }

    // ───────────────────────────────────────────────────────────────────
    // SPINE-3 — MIC claim (with pitch-now nuance)
    //
    // mic.claim_taken credits the claiming agent for EVERY new
    // ProspectingClaim row, including those created via the pitch-now
    // upgrade path (ProspectingClaimService::consumeLockAsPermanentClaim).
    //
    // mic.claim_taken is REVOKED only when a claim is deliberately
    // released — the ClaimReleased event fires from
    // ProspectingClaimObserver::updated solely on a NULL→set transition
    // of released_at, which is structurally only ever set by
    // ProspectingClaimService::releaseClaim() (the manual release path).
    // The pitch-now flow releases the pitch LOCK (a different row in
    // prospecting_pitch_locks), NOT the claim, so a pitch-now-derived
    // claim can never trigger this revoke path. Johan's "never auto-
    // release pitch-now work" rule is therefore enforced structurally,
    // not by a runtime gate.
    // ───────────────────────────────────────────────────────────────────

    public function handleClaimCreated(ClaimCreated $event): void
    {
        $this->safeCredit('mic.claim_taken', $this->user($event->claim->user_id), $event->claim);
    }

    public function handleClaimReleased(ClaimReleased $event): void
    {
        // Revoke the mic.claim_taken credit tied to THIS claim subject.
        // InstantPointService::revoke looks up the row by
        // (slug, subject_type, subject_id) — finds the credit minted at
        // ClaimCreated time and flips it to revoked. Reason carried for
        // audit.
        $reason = 'manual_release' . ($event->reason ? (': ' . $event->reason) : '');
        $this->safeRevoke('mic.claim_taken', $event->claim, $reason);
    }

    // ───────────────────────────────────────────────────────────────────
    // SPINE-3 — property lifecycle
    //
    // All three credit the listing agent ($property->agent_id) at the
    // moment of the canonical action. Null actor (system-imported
    // property) → InstantPointService skips silently.
    // ───────────────────────────────────────────────────────────────────

    public function handlePropertyCaptured(PropertyCaptured $event): void
    {
        $this->safeCredit('property.captured', $this->user($event->actorUserId), $event->property);
    }

    public function handlePropertyPublished(PropertyPublished $event): void
    {
        $this->safeCredit('property.published', $this->user($event->actorUserId), $event->property);
    }

    public function handlePropertyCompliancePassed(PropertyCompliancePassed $event): void
    {
        $this->safeCredit('property.compliance_passed', $this->user($event->actorUserId), $event->property);
    }

    // ───────────────────────────────────────────────────────────────────
    // SPINE-3 — marketing
    //
    // Credit fires only on the FIRST status→'published' transition (the
    // observer enforces this). A draft post that's never published, or
    // one whose publish call fails (status stays 'failed'/'draft'),
    // never credits.
    // ───────────────────────────────────────────────────────────────────

    public function handleMarketingPostPublished(MarketingPostPublished $event): void
    {
        $actor = $event->post->user_id !== null ? $this->user((int) $event->post->user_id) : null;
        $this->safeCredit('marketing.published', $actor, $event->post);
    }

    // ───────────────────────────────────────────────────────────────────
    // HELPERS
    // ───────────────────────────────────────────────────────────────────

    private function user(?int $id): ?User
    {
        if ($id === null) {
            return null;
        }
        return User::find($id);
    }

    /**
     * SPINE-2.5 — loops a participant set from ParticipantResolver and
     * calls safeCredit() per (user, slug) pair. Each call is wrapped
     * independently by safeCredit so a failure on one participant does
     * NOT skip the rest. NULL user_id in a pair short-circuits inside
     * safeCredit -> InstantPointService::credit (guard 1).
     *
     * @param  list<array{user_id:int,slug:string}>  $pairs
     */
    private function creditParticipants(array $pairs, ?Model $subject): void
    {
        foreach ($pairs as $pair) {
            $this->safeCredit($pair['slug'], $this->user($pair['user_id']), $subject);
        }
    }

    private function safeCredit(string $slug, ?User $agent, ?Model $subject): void
    {
        try {
            $this->svc->credit($slug, $agent, $subject);
        } catch (Throwable $e) {
            Log::warning('SPINE-2 credit failed (swallowed)', [
                'slug'         => $slug,
                'agent_id'     => $agent?->id,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id'   => $subject?->getKey(),
                'message'      => $e->getMessage(),
            ]);
        }
    }

    private function safeRevoke(string $slug, Model $subject, string $reason): void
    {
        try {
            $this->svc->revoke($slug, $subject, $reason);
        } catch (Throwable $e) {
            Log::warning('SPINE-2 revoke failed (swallowed)', [
                'slug'         => $slug,
                'subject_type' => $subject->getMorphClass(),
                'subject_id'   => $subject->getKey(),
                'reason'       => $reason,
                'message'      => $e->getMessage(),
            ]);
        }
    }
}
