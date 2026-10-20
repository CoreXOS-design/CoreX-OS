<?php

namespace App\Services\FeatureRollout;

use App\Events\FeatureRollout\FeatureRolloutChanged;
use App\Models\Agency;
use App\Models\FeatureRollout\AgencyFeatureRollout;
use App\Models\FeatureRollout\AgencyRolloutItem;
use App\Models\FeatureRollout\AgencyRolloutWave;
use App\Models\FeatureRollout\RolloutFeatureUser;
use App\Models\User;
use App\Notifications\FeatureRollout\RolloutDayBeforeNotification;
use App\Notifications\FeatureRollout\RolloutEarlyRequestNotification;
use App\Services\Features\AgencyFeatureService;
use App\Support\Tours\TourRegistry;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * AT-478 Part 5 — what is new and what is coming, for one person (spec §12.4, §12.5, §12.6 Ellie, §12.9):
 *
 *  forUser()          the "New and coming" page and Ellie's whats_new_for_me: groups still to come (with
 *                     dates, and whether this person already asked for each feature early) and every group
 *                     that switched on, listing only what this person can open, with its guided tour.
 *  requestEarly()     "Ask for this early" — once per person per feature; tells the agency's rollout admins.
 *  earlyRequests()    who asked for what early (the admin's panel).
 *  notifyDayBefore()  the scheduler: tell the rollout admins, the release day before, what switches on next.
 *
 * Always the person's OWN agency (effectiveAgencyId) — there is no agency or plan id in any of it.
 */
class FeatureRolloutUpdates
{
    private const ON = [AgencyRolloutItem::STATE_RELEASED, AgencyRolloutItem::STATE_RELEASED_EARLY];

    public function __construct(
        private readonly FeatureRolloutService $rollout,
        private readonly FeatureRolloutAccess $access,
        private readonly AgencyFeatureService $features,
        private readonly FeatureTourCoverage $tours,
        private readonly RolloutCalendar $calendar,
    ) {
    }

    public function planFor(User $user): ?AgencyFeatureRollout
    {
        $plan = $this->rollout->forAgency((int) $user->effectiveAgencyId());

        return $plan && $plan->status !== AgencyFeatureRollout::STATUS_CANCELLED ? $plan : null;
    }

    /**
     * @return array{plan: AgencyFeatureRollout,
     *   coming: list<array{wave: AgencyRolloutWave, when: ?Carbon, features: list<array{item_id:int, key:string, label:string, explain:string, requested_at:?Carbon}>}>,
     *   on: list<array{wave: AgencyRolloutWave, features: list<array{key:string, label:string, explain:string, state:string, tour:?array}>}>}|null
     */
    public function forUser(User $user): ?array
    {
        $plan = $this->planFor($user);
        if (! $plan) {
            return null;
        }
        $agency = Agency::withoutGlobalScopes()->find($plan->agency_id);
        $catalogue = $this->features->catalogue();
        $requested = RolloutFeatureUser::queryWithoutAgencyScope()->where('agency_id', $plan->agency_id)
            ->where('user_id', $user->id)->whereNotNull('early_requested_at')->pluck('early_requested_at', 'feature_key');
        $tourMap = null;
        $coming = [];
        $on = [];

        $managesRollout = $user->isOwnerRole() || $user->hasPermission('manage_feature_rollout');
        foreach ($this->rollout->wavesOf($plan) as $wave) {
            $items = $this->rollout->itemsOf($wave);
            if ($wave->released_at === null) {
                // §18 — "Admin decides" is not announced to anyone but the admins until they give it out.
                if ($wave->isAdminDecides() && ! $managesRollout) {
                    continue;
                }
                $f = [];
                foreach ($items->where('state', AgencyRolloutItem::STATE_HELD) as $item) {
                    $f[] = [
                        'item_id' => (int) $item->id, 'key' => $item->feature_key,
                        'label' => (string) ($catalogue[$item->feature_key]['label'] ?? $item->feature_key),
                        'explain' => (string) ($catalogue[$item->feature_key]['explain'] ?? ''),
                        'requested_at' => isset($requested[$item->feature_key]) ? Carbon::parse($requested[$item->feature_key]) : null,
                    ];
                }
                if ($f !== []) {
                    $coming[] = ['wave' => $wave, 'when' => $plan->status === AgencyFeatureRollout::STATUS_ACTIVE ? $wave->scheduled_for : null, 'features' => $f];
                }
                continue;
            }

            $f = [];
            foreach ($items as $item) {
                $live = in_array($item->state, self::ON, true);
                $waiting = in_array($item->state, [AgencyRolloutItem::STATE_AWAITING_ADMIN, AgencyRolloutItem::STATE_AWAITING_SETUP], true);
                if ((! $live && ! $waiting) || ($waiting && $wave->isAdminDecides() && ! $managesRollout)) {
                    continue;
                }
                if ($live && ! $this->access->userCanAccess($user, $item->feature_key, $agency)) {
                    continue; // only what this person can open
                }
                $tourMap ??= $this->tours->toursByFeature();
                $f[] = [
                    'key' => $item->feature_key,
                    'label' => (string) ($catalogue[$item->feature_key]['label'] ?? $item->feature_key),
                    'explain' => (string) ($catalogue[$item->feature_key]['explain'] ?? ''),
                    'state' => $live ? 'on' : 'waiting',
                    'tour' => $live ? $this->tourFor($user, $tourMap[$item->feature_key] ?? []) : null,
                ];
            }
            if ($f !== []) {
                $on[] = ['wave' => $wave, 'features' => $f];
            }
        }

        usort($coming, fn ($a, $b) => [$a['when']?->timestamp ?? PHP_INT_MAX, $a['wave']->lane, $a['wave']->position] <=> [$b['when']?->timestamp ?? PHP_INT_MAX, $b['wave']->lane, $b['wave']->position]);
        usort($on, fn ($a, $b) => $b['wave']->released_at <=> $a['wave']->released_at);

        return ['plan' => $plan, 'coming' => $coming, 'on' => $on];
    }

    /** @param list<array{key:string,title:string}> $tours */
    private function tourFor(User $user, array $tours): ?array
    {
        foreach ($tours as $t) {
            $tour = TourRegistry::find($t['key']);
            if ($tour && TourRegistry::canOpen($tour, $user) && ($target = TourRegistry::launchTarget($tour))) {
                return ['title' => $t['title'], 'url' => $target['url']];
            }
        }

        return null;
    }

    // ── Asking for a feature early ─────────────────────────────────────────────

    /**
     * Ask for one held feature early. Refused (a plain sentence) unless it is held in a group still to come
     * in this person's own agency's plan. Asking twice changes nothing and tells nobody twice.
     */
    public function requestEarly(User $user, int $itemId): string
    {
        $plan = $this->planFor($user);
        $item = $plan ? AgencyRolloutItem::queryWithoutAgencyScope()->where('rollout_id', $plan->id)->find($itemId) : null;
        $wave = $item ? AgencyRolloutWave::queryWithoutAgencyScope()->find($item->wave_id) : null;
        if (! $item || $item->state !== AgencyRolloutItem::STATE_HELD || ($wave?->isAdminDecides() && ! $user->hasPermission('manage_feature_rollout'))) {
            throw new \DomainException('That feature is not waiting to switch on.');
        }
        $label = (string) ($this->features->catalogue()[$item->feature_key]['label'] ?? $item->feature_key);
        $already = RolloutFeatureUser::queryWithoutAgencyScope()->where('agency_id', $plan->agency_id)->where('user_id', $user->id)
            ->where('feature_key', $item->feature_key)->whereNotNull('early_requested_at')->exists();
        if ($already) {
            return "You already asked for {$label} early — your admin has been told.";
        }

        app(FeatureRolloutUsage::class)->mark((int) $plan->agency_id, (int) $user->id, $item->feature_key, 'early_requested_at');
        $date = $plan->status === AgencyFeatureRollout::STATUS_ACTIVE ? $wave?->scheduled_for?->format('l j F') : null;
        foreach ($this->rolloutAdmins((int) $plan->agency_id)->reject(fn ($a) => $a->id === $user->id) as $admin) {
            $admin->notify(new RolloutEarlyRequestNotification($user->name ?: $user->email, $item->feature_key, $label, $date));
        }
        event(new FeatureRolloutChanged((int) $plan->agency_id, 'early_requested', ['feature' => $item->feature_key], (int) $user->id));

        return "Your admin has been told you would like {$label} early.";
    }

    /** @return array<string, list<string>> feature key => names of the people who asked for it early */
    public function earlyRequests(AgencyFeatureRollout $plan): array
    {
        $rows = RolloutFeatureUser::queryWithoutAgencyScope()->where('agency_id', $plan->agency_id)->whereNotNull('early_requested_at')->get(['feature_key', 'user_id']);
        $names = User::withoutGlobalScopes()->whereIn('id', $rows->pluck('user_id'))->get()->mapWithKeys(fn ($u) => [$u->id => $u->name ?: $u->email]);

        return $rows->groupBy('feature_key')->map(fn ($r) => $r->map(fn ($x) => $names[$x->user_id] ?? 'Someone')->values()->all())->all();
    }

    /** @return Collection<int,User> the agency's active people who manage its rollout (never the System Owner) */
    private function rolloutAdmins(int $agencyId): Collection
    {
        return User::withoutGlobalScopes()->where('agency_id', $agencyId)->where('is_active', true)->get()
            ->filter(fn (User $u) => ! $u->isOwnerRole() && $u->hasPermission('manage_feature_rollout'))->values();
    }

    // ── The day-before notice (§12.4) ──────────────────────────────────────────

    /**
     * For every running plan: groups switching on next release day, not yet reviewed and not yet notified —
     * tell the rollout admins once, from the release time on the day before.
     *
     * @return int groups notified
     */
    public function notifyDayBefore(?Carbon $now = null): int
    {
        $now = ($now ?? Carbon::now())->copy()->setTimezone($this->calendar->timezone());
        $count = 0;
        $plans = AgencyFeatureRollout::queryWithoutAgencyScope()->where('status', AgencyFeatureRollout::STATUS_ACTIVE)->get();
        $catalogue = $this->features->catalogue();

        foreach ($plans as $plan) {
            if ($now->lt($this->calendar->releaseMoment($now, $plan))) {
                continue;
            }
            $due = $this->rollout->wavesOf($plan)->filter(fn ($w) => $w->released_at === null && $w->scheduled_for !== null
                && $w->access_reviewed_at === null && $w->admin_notified_at === null && ! $w->isAdminDecides()
                && $now->toDateString() >= $this->calendar->previousReleaseDay($w->scheduled_for, $plan)->toDateString());
            $due = $due->filter(fn ($w) => $this->rollout->itemsOf($w)->where('state', AgencyRolloutItem::STATE_HELD)->isNotEmpty())->values();
            if ($due->isEmpty()) {
                continue;
            }

            $keys = $due->flatMap(fn ($w) => $this->rollout->itemsOf($w)->where('state', AgencyRolloutItem::STATE_HELD)->pluck('feature_key'))->unique()->values();
            $notice = new RolloutDayBeforeNotification(
                $due->pluck('title')->implode(' + '),
                $due->min('scheduled_for')->format('l j F'),
                $keys->map(fn ($k) => (string) ($catalogue[$k]['label'] ?? $k))->all(),
            );
            foreach ($this->rolloutAdmins((int) $plan->agency_id) as $admin) {
                $admin->notify($notice);
            }
            AgencyRolloutWave::queryWithoutAgencyScope()->whereIn('id', $due->pluck('id'))->update(['admin_notified_at' => now()]);
            $count += $due->count();
        }

        return $count;
    }
}
