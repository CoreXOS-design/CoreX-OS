<?php

namespace App\Services\RoadToSold;

use App\Models\ProspectingClaim;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RoadToSold\RtsSetting;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Prospecting\ProspectingClaimService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Road to Sold v4, station 2: the claim hold (.ai/specs/road-to-sold.md 23.5). It REPLACES the 48-hour rule for a claim that is on the belt.
 *
 *   - a claim on the belt has hold_until = today + claim_hold_days WORKING days of the agency calendar (Monday-Friday, not a public holiday of
 *     the agency's country, not a day the agent is paused);
 *   - any work on the property restarts the clock (a station move, an outcome, an un-park): "held while it is being worked";
 *   - the agent is told claim_warn_days working days before the end; one extension of claim_extension_days (a manager can grant more);
 *   - at the end the claim is released (reason hold_ended) and the prospect goes back to the pool - unless the property is parked or off
 *     the belt (not on a clock), is past the mandate (won), or has an evaluation to come (the appointment date carries it).
 *
 * A claim with no hold_until is a claim that is not on the belt (Road to Sold off for the agency, or no property file yet): it keeps the old rules.
 * Every figure is an agency setting (SettingsService::claimHoldDays() ...); nothing here is one agency's.
 */
class ClaimHoldService
{
    public const RELEASE_HOLD_ENDED = 'hold_ended';

    public function __construct(private readonly WorkdayService $workdays)
    {
    }

    public function available(): bool
    {
        static $has = null;

        return $has ??= RtsSetting::hasClaimHoldColumns() && Schema::hasColumn('prospecting_claims', 'hold_until');
    }

    private function settings(int $agencyId): SettingsService
    {
        return SettingsService::forAgency($agencyId);
    }

    private function agentOf(ProspectingClaim $c): ?User
    {
        return User::query()->withoutGlobalScopes()->find($c->user_id);
    }

    /** The date $days working days after $from (default today) on this agency's calendar, skipping the agent's paused days. */
    public function addWorkdays(int $agencyId, ?User $agent, int $days, ?Carbon $from = null): Carbon
    {
        return $this->workdays->addWorkdays($from ?? now(), $days, $this->settings($agencyId)->holidayCountry(), $agent);
    }

    /** Put the claim on the clock from today. */
    public function start(ProspectingClaim $c, ?RtsProspect $p = null): void
    {
        if (! $this->available()) {
            return;
        }
        $c->forceFill([
            'rts_prospect_id' => $p?->id ?? $c->rts_prospect_id,
            'hold_until'      => $this->addWorkdays((int) $c->agency_id, $this->agentOf($c), $this->settings((int) $c->agency_id)->claimHoldDays())->toDateString(),
            'warned_at'       => null,
        ])->saveQuietly();
    }

    /**
     * Work was done on the prospect: every live claim of it that is on the clock counts from today again. A claim that was extended keeps the
     * end the extension gave it unless working it pushes the end further out (the hold never moves backwards).
     */
    public function restartForProspect(RtsProspect $p): void
    {
        if (! $this->available()) {
            return;
        }
        $claims = ProspectingClaim::withoutGlobalScope(AgencyScope::class)->where('agency_id', $p->agency_id)->where('rts_prospect_id', $p->id)
            ->where('is_active', true)->whereNull('released_at')->whereNotNull('hold_until')->get();
        foreach ($claims as $c) {
            $new = $this->addWorkdays((int) $c->agency_id, $this->agentOf($c), $this->settings((int) $c->agency_id)->claimHoldDays());
            if ($new->gt(Carbon::parse($c->hold_until))) {
                $c->forceFill(['hold_until' => $new->toDateString(), 'warned_at' => null])->saveQuietly();
            }
        }
    }

    /** Working days left on the hold (0 on the last day, negative once it is past); null when the claim is not on the clock. */
    public function daysLeft(ProspectingClaim $c): ?int
    {
        if (! $this->available() || $c->hold_until === null) {
            return null;
        }
        $today = now()->startOfDay();
        $until = Carbon::parse($c->hold_until)->startOfDay();
        $country = $this->settings((int) $c->agency_id)->holidayCountry();
        $agent = $this->agentOf($c);
        if ($until->gte($today)) {
            return $this->workdays->workdaysBetween($today, $until, $country, $agent);
        }

        return -$this->workdays->workdaysBetween($until, $today, $country, $agent);
    }

    /** True once the hold is at its end and nothing keeps the claim alive. */
    public function hasEnded(ProspectingClaim $c): bool
    {
        if (! $this->available() || ! $c->is_active || $c->released_at !== null || $c->hold_until === null) {
            return false;
        }
        if (Carbon::parse($c->hold_until)->startOfDay()->gte(now()->startOfDay())) {
            return false;
        }
        $p = $c->rts_prospect_id ? RtsProspect::withoutGlobalScopes()->find($c->rts_prospect_id) : null;
        if ($p === null || $p->closed_at !== null || $p->released_at !== null) {
            return true;   // the belt record is gone; the claim should not outlive it
        }
        if ($p->parked_at !== null) {
            return false;  // parked / off-ramped: not on a clock until the revisit date brings it back
        }
        $order = ProspectStages::order((string) $p->stage);
        if ($order >= ProspectStages::order(ProspectStages::MANDATE_SIGNED)) {
            return false;  // won: on its way to stock, not a prospect to chase
        }
        if ($order >= ProspectStages::order(ProspectStages::APPOINTMENT_SET) && $p->next_action_at !== null && $p->next_action_at->copy()->startOfDay()->gte(now()->startOfDay())) {
            return false;  // an evaluation is still to come: its date carries the claim
        }

        return true;
    }

    /** Has the agent an extension left? A manager is never held to the count. */
    public function mayExtend(ProspectingClaim $c, bool $asManager = false): bool
    {
        return $asManager || (int) $c->hold_extensions < $this->settings((int) $c->agency_id)->claimExtensionsAllowed();
    }

    /**
     * Extend the hold by claim_extension_days working days. An agent gets claim_extensions_allowed extensions; a manager (who holds
     * road_to_sold.release_prospects) may grant more, and the history line says so.
     *
     * @return array{ok:bool,message:string}
     */
    public function extend(ProspectingClaim $c, User $actor, bool $asManager = false, ?string $note = null): array
    {
        if (! $this->available() || $c->hold_until === null || ! $c->is_active || $c->released_at !== null) {
            return ['ok' => false, 'message' => 'This property is not on a claim hold.'];
        }
        if (! $this->mayExtend($c, $asManager)) {
            return ['ok' => false, 'message' => 'This claim has already been extended. Ask your manager if it needs longer.'];
        }
        $base = Carbon::parse($c->hold_until)->startOfDay()->max(now()->startOfDay());
        $new = $this->addWorkdays((int) $c->agency_id, $this->agentOf($c), $this->settings((int) $c->agency_id)->claimExtensionDays(), $base);
        $c->forceFill(['hold_until' => $new->toDateString(), 'hold_extended_at' => now(), 'hold_extensions' => (int) $c->hold_extensions + 1, 'warned_at' => null])->saveQuietly();
        $p = $c->rts_prospect_id ? RtsProspect::withoutGlobalScopes()->find($c->rts_prospect_id) : null;
        if ($p) {
            app(ProspectService::class)->logHold($p, 'Claim hold extended to ' . $new->format('j M Y') . ($asManager ? ' (manager)' : '') . ($note ? ': ' . mb_substr($note, 0, 120) : ''), $actor, ['hold_until' => $new->toDateString()]);
        }

        $this->raise('ClaimHoldExtended', fn () => new \App\Events\RoadToSold\ClaimHoldExtended((int) $c->id, (int) $c->agency_id, (int) $c->user_id, $p?->id, $new->toDateString(), (int) $actor->id));

        return ['ok' => true, 'message' => 'Hold extended to ' . $new->format('j M Y') . '.'];
    }

    /**
     * End the hold: the claim is released (reason hold_ended) and so is the prospect, which goes back to the pool (23.5.3). The claim
     * release is what drives the prospect release (the ClaimReleased listener); the explicit release below is the same call made safe
     * when no listener ran.
     */
    public function endHold(ProspectingClaim $c, string $source = 'job'): bool
    {
        if (! $c->is_active || $c->released_at !== null) {
            return false;
        }
        try {
            app(ProspectingClaimService::class)->releaseClaim((int) $c->id, (int) $c->user_id, 'Claim hold ended (' . $source . '): not worked for the hold period', self::RELEASE_HOLD_ENDED);
            $p = $c->rts_prospect_id ? RtsProspect::withoutGlobalScopes()->find($c->rts_prospect_id) : null;
            if ($p && $p->released_at === null && $p->closed_at === null) {
                app(ProspectService::class)->release($p, null, 'Claim hold ended');
            }
        } catch (\Throwable $e) {
            Log::warning('Claim hold could not be ended', ['claim_id' => $c->id, 'message' => $e->getMessage()]);

            return false;
        }
        $this->raise('ClaimHoldEnded', fn () => new \App\Events\RoadToSold\ClaimHoldEnded((int) $c->id, (int) $c->agency_id, (int) $c->user_id, $c->rts_prospect_id ? (int) $c->rts_prospect_id : null, $c->hold_until?->toDateString(), null));

        return true;
    }

    private function raise(string $name, \Closure $make): void
    {
        \App\Support\Events\SafeRaise::dispatch($name, $make);
    }

    /**
     * A claim somebody is trying to take over: release it when it is at its end, by the rule that applies to it (the hold, or the old
     * 48 hours for a claim that is not on the belt). True when it was released.
     */
    public function releaseIfExpired(ProspectingClaim $c): bool
    {
        if ($c->hold_until !== null) {
            return $this->hasEnded($c) && $this->endHold($c, 'claimed by another agent');
        }
        if (! $c->isExpired()) {
            return false;
        }
        $c->update(['is_active' => false, 'released_at' => now()]);

        return true;
    }
}
