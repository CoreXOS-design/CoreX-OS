<?php

declare(strict_types=1);

namespace App\Listeners\Activity;

use App\Events\Contact\ContactCreated;
use App\Events\Deal\DealClosed;
use App\Events\Deal\DealCreated;
use App\Events\Deal\DealStageAdvanced;
use App\Events\Deal\DealStatusChanged;
use App\Events\Compliance\RcrSubmissionSubmitted;
use App\Events\PresentationGenerated;
use App\Events\Presentation\PresentationOutcomeRecorded;
use App\Events\Prospecting\TrackedPropertyPromotedToStock;
use App\Events\SellerOutreach\PitchSent;
use App\Models\Compliance\Rcr\RcrSubmission;
use App\Models\Presentation;
use App\Models\PresentationOutcome;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Activity\InstantPointService;
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
        $this->safeCredit('deal.created', $this->user($event->actorUserId), $event->deal);
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
        $this->safeCredit('deal.registered', $this->user($event->actorUserId), $event->deal);
    }

    public function handleDealStatusChanged(DealStatusChanged $event): void
    {
        // REVERSAL: accepted_status flipping FROM 'R' to anything else
        // means the deal was un-registered. Revoke ONLY deal.registered.
        // deal.created / deal.stage_advanced stay credited — those
        // represent earlier work the agent did, not the registration.
        if ((string) $event->fromStatus === 'R' && (string) $event->toStatus !== 'R') {
            $this->safeRevoke('deal.registered', $event->deal, 'deal_unregistered');
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
    // HELPERS
    // ───────────────────────────────────────────────────────────────────

    private function user(?int $id): ?User
    {
        if ($id === null) {
            return null;
        }
        return User::find($id);
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
