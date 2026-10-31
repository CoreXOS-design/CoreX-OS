<?php

namespace App\Services\FeatureRollout;

use App\Models\Agency;
use App\Models\FeatureRollout\AgencyFeatureRollout;
use App\Models\FeatureRollout\AgencyRolloutItem;
use App\Models\FeatureRollout\AgencyRolloutWave;
use App\Models\FeatureRollout\RolloutFeatureUser;
use App\Models\User;
use App\Models\UserTourProgress;
use App\Notifications\FeatureRollout\RolloutTourNudgeNotification;
use App\Services\Features\AgencyFeatureService;
use App\Support\Tours\TourRegistry;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AT-478 Part 5 — is what the rollout switched on actually being used? (spec §12.6–12.8)
 *
 *  recordVisit()   the first time each person opens a feature the rollout switched on (RecordRolloutFeatureUse)
 *  stats()         per feature: who has access, who opened it, who took its guided tour
 *  lowUse()        "hardly anyone has opened the last group" — a suggestion to pause, never automatic
 *  nudgeTours()    once, a few days after a feature switched on, remind people who have not taken its tour
 *  overview()      every agency on gradual rollout for the CoreX team, with what needs attention
 *
 * Thresholds: config/feature-rollout.php `follow_up`.
 */
class FeatureRolloutUsage
{
    private const ON = [AgencyRolloutItem::STATE_RELEASED, AgencyRolloutItem::STATE_RELEASED_EARLY];

    public function __construct(
        private readonly FeatureRolloutService $rollout,
        private readonly FeatureRolloutAccess $access,
        private readonly AgencyFeatureService $features,
        private readonly FeatureTourCoverage $tours,
    ) {
    }

    private function cfg(string $key): mixed
    {
        return config('feature-rollout.follow_up.' . $key);
    }

    // ── Recording first visits ─────────────────────────────────────────────────

    public static function forget(int $agencyId): void
    {
        Cache::forget('feature-rollout:tracked:' . $agencyId);
    }

    /**
     * The features this agency's rollout switched on, with the routes that belong to each (the registry's
     * route_names globs and route_prefixes). Cached: it is read on every page of an agency mid-rollout,
     * and cleared whenever the rollout switches something on or off. [] for an agency with no plan.
     *
     * @return array<string, array{globs: list<string>, prefixes: list<string>}>
     */
    public function tracked(int $agencyId): array
    {
        return Cache::remember('feature-rollout:tracked:' . $agencyId, 600, function () use ($agencyId) {
            $plan = $this->rollout->forAgency($agencyId);
            if (! $plan || $plan->status === AgencyFeatureRollout::STATUS_CANCELLED) {
                return [];
            }
            $catalogue = $this->features->catalogue();
            $out = [];
            foreach (AgencyRolloutItem::queryWithoutAgencyScope()->where('rollout_id', $plan->id)->whereIn('state', self::ON)->pluck('feature_key') as $key) {
                $out[$key] = [
                    'globs'    => array_values((array) ($catalogue[$key]['route_names'] ?? [])),
                    'prefixes' => array_values(array_filter(array_map(fn ($p) => trim((string) $p, '/'), (array) ($catalogue[$key]['route_prefixes'] ?? [])))),
                ];
            }

            return $out;
        });
    }

    /** Record the first time this person opened a feature their agency's rollout switched on. */
    public function recordVisit(User $user, ?string $routeName, string $path): void
    {
        $agencyId = $user->effectiveAgencyId();
        if (! $agencyId || $user->isOwnerRole()) {
            return;
        }
        $tracked = $this->tracked((int) $agencyId);
        if ($tracked === []) {
            return;
        }
        $path = trim($path, '/');
        foreach ($tracked as $key => $m) {
            $hit = ($routeName && $m['globs'] !== [] && Str::is($m['globs'], $routeName))
                || collect($m['prefixes'])->contains(fn ($p) => collect([$p, 'corex/' . $p])
                    ->contains(fn ($q) => $path === $q || str_starts_with($path, $q . '/')));
            if (! $hit) {
                continue;
            }
            $memo = 'feature_rollout.opened.' . $agencyId;
            $seen = (array) session($memo, []);
            if (isset($seen[$key])) {
                continue;
            }
            DB::table('rollout_feature_users')->insertOrIgnore([
                'agency_id' => $agencyId, 'user_id' => $user->id, 'feature_key' => $key,
                'first_opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('rollout_feature_users')
                ->where('agency_id', $agencyId)->where('user_id', $user->id)->where('feature_key', $key)
                ->whereNull('first_opened_at')
                ->update(['first_opened_at' => now(), 'updated_at' => now()]);
            session([$memo => $seen + [$key => true]]);
        }
    }

    // ── Who has access, who opened it, who took the tour ───────────────────────

    /**
     * @param  list<string> $featureKeys
     * @return array<string, array{access: Collection<int,User>, opened: list<int>, toured: list<int>|null}>  toured null = no tour
     */
    public function stats(Agency $agency, array $featureKeys): array
    {
        if ($featureKeys === []) {
            return [];
        }
        $users = User::withoutGlobalScopes()->where('agency_id', $agency->id)->where('is_active', true)->orderBy('name')->get();
        $opened = RolloutFeatureUser::queryWithoutAgencyScope()->where('agency_id', $agency->id)
            ->whereIn('feature_key', $featureKeys)->whereNotNull('first_opened_at')
            ->get(['feature_key', 'user_id'])->groupBy('feature_key')->map(fn ($r) => $r->pluck('user_id')->all());
        $tourMap = $this->tours->toursByFeature();
        $tourKeys = collect($featureKeys)->flatMap(fn ($k) => array_column($tourMap[$k] ?? [], 'key'))->unique()->values()->all();
        $completed = $tourKeys === [] ? collect() : UserTourProgress::whereIn('user_id', $users->pluck('id'))
            ->whereIn('tour_key', $tourKeys)->whereNotNull('completed_at')->get(['user_id', 'tour_key'])
            ->groupBy('tour_key')->map(fn ($r) => $r->pluck('user_id')->all());

        $out = [];
        foreach ($featureKeys as $key) {
            $access = $users->filter(fn (User $u) => $this->access->userCanAccess($u, $key, $agency))->values();
            $keysForFeature = array_column($tourMap[$key] ?? [], 'key');
            $out[$key] = [
                'access' => $access,
                'opened' => array_values(array_intersect($opened[$key] ?? [], $access->pluck('id')->all())),
                'toured' => $keysForFeature === [] ? null : array_values(array_intersect(
                    collect($keysForFeature)->flatMap(fn ($t) => $completed[$t] ?? [])->unique()->all(),
                    $access->pluck('id')->all()
                )),
            ];
        }

        return $out;
    }

    // ── Low use → suggest a pause (§12.8) ──────────────────────────────────────

    /**
     * When hardly anyone has opened the most recent group that switched on, a suggestion to hold the next
     * one. Suggestion only — nothing is ever paused automatically.
     *
     * @return array{group:string, opened:int, people:int}|null
     */
    public function lowUse(AgencyFeatureRollout $plan): ?array
    {
        if ($plan->status !== AgencyFeatureRollout::STATUS_ACTIVE) {
            return null;
        }
        // "Admin decides" (§18) is for the admins only, so few people opening it says nothing about the pace.
        $last = $this->rollout->wavesOf($plan)->whereNotNull('released_at')->reject(fn ($w) => $w->isAdminDecides())->sortByDesc('released_at')->first();
        if (! $last || $last->released_at->gt(Carbon::now()->subDays((int) $this->cfg('low_use_after_days')))) {
            return null;
        }
        $keys = $this->rollout->itemsOf($last)->whereIn('state', self::ON)->pluck('feature_key')->all();
        $agency = Agency::withoutGlobalScopes()->find($plan->agency_id);
        if ($keys === [] || ! $agency) {
            return null;
        }
        $people = [];
        $opened = [];
        foreach ($this->stats($agency, $keys) as $s) {
            foreach ($s['access'] as $u) {
                $people[$u->id] = true;
            }
            foreach ($s['opened'] as $id) {
                $opened[$id] = true;
            }
        }
        $n = count($people);
        if ($n < (int) $this->cfg('low_use_min_people') || count($opened) / $n >= (float) $this->cfg('low_use_max_share')) {
            return null;
        }

        return ['group' => $last->title, 'opened' => count($opened), 'people' => $n];
    }

    // ── Tour reminders (§12.6) ─────────────────────────────────────────────────

    /** @return int reminders sent */
    public function nudgeTours(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $after = $now->copy()->subDays((int) $this->cfg('tour_nudge_after_days'));
        $window = $now->copy()->subDays((int) $this->cfg('tour_nudge_window_days'));
        $items = AgencyRolloutItem::queryWithoutAgencyScope()->whereIn('state', self::ON)
            ->whereBetween('released_at', [$window, $after])->get(['agency_id', 'rollout_id', 'feature_key']);
        $tourMap = $this->tours->toursByFeature();
        $sent = 0;

        foreach ($items->groupBy('agency_id') as $agencyId => $agencyItems) {
            $plan = $this->rollout->forAgency((int) $agencyId);
            $agency = Agency::withoutGlobalScopes()->find($agencyId);
            if (! $plan || ! $agency || $plan->status === AgencyFeatureRollout::STATUS_CANCELLED) {
                continue;
            }
            $keys = $agencyItems->pluck('feature_key')->filter(fn ($k) => ! empty($tourMap[$k]))->unique()->values()->all();
            $nudged = RolloutFeatureUser::queryWithoutAgencyScope()->where('agency_id', $agencyId)->whereIn('feature_key', $keys)
                ->whereNotNull('tour_nudged_at')->get(['feature_key', 'user_id'])->groupBy('feature_key')->map(fn ($r) => array_flip($r->pluck('user_id')->all()));

            foreach ($this->stats($agency, $keys) as $key => $s) {
                $toured = array_flip($s['toured'] ?? []);
                foreach ($s['access'] as $user) {
                    if (isset($toured[$user->id]) || isset($nudged[$key][$user->id])) {
                        continue;
                    }
                    $url = $this->tourUrl($user, $tourMap[$key]);
                    if ($url === null) {
                        continue;
                    }
                    $user->notify(new RolloutTourNudgeNotification($key, (string) ($this->features->catalogue()[$key]['label'] ?? $key), $url));
                    $this->mark((int) $agencyId, (int) $user->id, $key, 'tour_nudged_at');
                    $sent++;
                }
            }
        }

        return $sent;
    }

    /** @param list<array{key:string,title:string}> $tours */
    private function tourUrl(User $user, array $tours): ?string
    {
        foreach ($tours as $t) {
            $tour = TourRegistry::find($t['key']);
            if ($tour && TourRegistry::canOpen($tour, $user) && ($target = TourRegistry::launchTarget($tour))) {
                return $target['url'];
            }
        }

        return null;
    }

    /** Stamp one of the per-person columns, creating the row if needed. */
    public function mark(int $agencyId, int $userId, string $featureKey, string $column): void
    {
        DB::table('rollout_feature_users')->insertOrIgnore([
            'agency_id' => $agencyId, 'user_id' => $userId, 'feature_key' => $featureKey,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('rollout_feature_users')->where('agency_id', $agencyId)->where('user_id', $userId)
            ->where('feature_key', $featureKey)->whereNull($column)
            ->update([$column => now(), 'updated_at' => now()]);
    }

    // ── The CoreX team's overview (§12.7) ──────────────────────────────────────

    /**
     * Every agency on gradual rollout, with what needs attention: paused a long time, waiting on setup,
     * or hardly using the last group.
     *
     * @return Collection<int, array{agency: Agency, plan: AgencyFeatureRollout, on:int, groups:int, next:?Carbon, flags: list<string>}>
     */
    public function overview(): Collection
    {
        $plans = AgencyFeatureRollout::queryWithoutAgencyScope()->where('status', '!=', AgencyFeatureRollout::STATUS_CANCELLED)->get();
        $agencies = Agency::withoutGlobalScopes()->whereIn('id', $plans->pluck('agency_id'))->get()->keyBy('id');
        $waves = AgencyRolloutWave::queryWithoutAgencyScope()->whereIn('rollout_id', $plans->pluck('id'))->get()->groupBy('rollout_id');
        $waitingSetup = AgencyRolloutItem::queryWithoutAgencyScope()->whereIn('rollout_id', $plans->pluck('id'))
            ->where('state', AgencyRolloutItem::STATE_AWAITING_SETUP)->get(['rollout_id', 'feature_key'])->groupBy('rollout_id');

        return $plans->filter(fn ($p) => $agencies->has($p->agency_id))->map(function (AgencyFeatureRollout $plan) use ($agencies, $waves, $waitingSetup) {
            $mine = $waves->get($plan->id, collect());
            $flags = [];
            if ($plan->status === AgencyFeatureRollout::STATUS_PAUSED && $plan->paused_at
                && $plan->paused_at->lte(Carbon::now()->subDays((int) $this->cfg('paused_flag_days')))) {
                $flags[] = 'Paused for ' . (int) $plan->paused_at->diffInDays(Carbon::now()) . ' days';
            }
            if ($waitingSetup->has($plan->id)) {
                $flags[] = 'Waiting on setup: ' . $waitingSetup[$plan->id]->map(fn ($i) => $this->features->catalogue()[$i->feature_key]['label'] ?? $i->feature_key)->implode(', ');
            }
            if ($low = $this->lowUse($plan)) {
                $flags[] = "Only {$low['opened']} of {$low['people']} people opened \"{$low['group']}\"";
            }

            return [
                'agency' => $agencies[$plan->agency_id],
                'plan'   => $plan,
                'on'     => $mine->whereNotNull('released_at')->count(),
                'groups' => $mine->count(),
                'next'   => $mine->whereNull('released_at')->whereNotNull('scheduled_for')->sortBy('scheduled_for')->first()?->scheduled_for,
                'flags'  => $flags,
            ];
        })->values();
    }
}
