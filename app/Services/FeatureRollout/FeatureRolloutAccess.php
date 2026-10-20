<?php

namespace App\Services\FeatureRollout;

use App\Events\FeatureRollout\FeatureRolloutChanged;
use App\Models\Agency;
use App\Models\FeatureRollout\AgencyFeatureRollout;
use App\Models\FeatureRollout\AgencyRolloutItem;
use App\Models\FeatureRollout\AgencyRolloutWave;
use App\Models\FeatureRollout\RolloutAnnouncementView;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use App\Services\PermissionService;
use App\Services\Permissions\RoleDefaultsResolver;
use App\Support\Tours\TourRegistry;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AT-478 Part 3 — the two gradual-rollout pop-ups (spec agency-feature-rollout.md §2.5–2.6, §3.5a, §5.3–5.4).
 *
 *  - The ADMIN ACCESS REVIEW: for a wave that switches on next release day (or went live without a
 *    review), the agency admin decides per feature which roles get it. Released-but-unreviewed waves
 *    come first and hold back the admin's own release pop-up.
 *  - The RELEASE POP-UP: once per user per released wave, listing only the features that user can
 *    open, each with its guided tour where one exists.
 *
 * Role access is written ONLY for the features and roles the review rendered (§3.1, the §6.1 rule
 * applied to roles). The Role Manager's own saver cannot be used: it rebuilds a role's WHOLE grant
 * list and freezes the permissions of switched-off features — and a wave that has not switched on
 * yet is exactly that. The write rules are the Role Manager's: a revoke is a soft delete (the
 * tombstone corex:sync-permissions --merge-defaults respects), a grant restores the tombstoned row.
 */
class FeatureRolloutAccess
{
    /** A wave that went live longer ago than this no longer pops up for a user who never saw it. */
    private const RELEASE_POPUP_DAYS = 30;

    /** Items that still mean something to review or announce (a skipped feature does not). */
    private const LIVE_STATES = [
        AgencyRolloutItem::STATE_HELD, AgencyRolloutItem::STATE_RELEASED,
        AgencyRolloutItem::STATE_RELEASED_EARLY, AgencyRolloutItem::STATE_AWAITING_ADMIN,
        AgencyRolloutItem::STATE_AWAITING_SETUP,
    ];

    public function __construct(
        private readonly FeatureRolloutService $rollout,
        private readonly AgencyFeatureService $features,
        private readonly RolloutCalendar $calendar,
        private readonly FeatureTourCoverage $tours,
    ) {
    }

    // ── What a feature's access is made of ─────────────────────────────────────

    /** @return list<string> the permissions a feature owns (what the review grants / revokes) */
    public function ownedKeys(string $feature): array
    {
        return $this->features->permissionKeysFor($feature);
    }

    /**
     * The owned permissions that mean "can open it": the feature's own nav permission(s) where it
     * owns them, otherwise every owned permission.
     *
     * @return list<string>
     */
    public function entryKeys(string $feature): array
    {
        $owned = $this->ownedKeys($feature);
        $nav = array_values(array_intersect((array) ($this->features->catalogue()[$feature]['nav_permission'] ?? []), $owned));

        return $nav !== [] ? $nav : $owned;
    }

    /** The roles an agency admin may set — the Role Manager's own list (no owner, no assistant). */
    public function editableRoles(int $agencyId): Collection
    {
        return Role::where('agency_id', $agencyId)
            ->where('is_owner', false)
            ->where('name', '!=', 'assistant')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'label']);
    }

    /**
     * Current access grid for features: feature => role name => bool. A feature that owns no
     * permissions maps to null ("everyone who can open its area").
     *
     * @param  list<string> $featureKeys
     * @return array<string, array<string,bool>|null>
     */
    public function grid(int $agencyId, array $featureKeys): array
    {
        $roles = $this->editableRoles($agencyId)->pluck('name')->all();
        $held = RolePermission::where('agency_id', $agencyId)
            ->whereIn('role', $roles)
            ->get(['role', 'permission_key'])
            ->groupBy('role')
            ->map(fn ($rows) => array_flip($rows->pluck('permission_key')->all()));

        $out = [];
        foreach ($featureKeys as $feature) {
            $entry = $this->entryKeys($feature);
            if ($entry === []) {
                $out[$feature] = null;
                continue;
            }
            foreach ($roles as $role) {
                $out[$feature][$role] = array_intersect_key(array_flip($entry), $held[$role] ?? []) !== [];
            }
        }

        return $out;
    }

    /**
     * Give or take away one role's access to one feature. Only that feature's own permissions and
     * only that role are touched. Returns true when something changed.
     */
    public function setRoleAccess(int $agencyId, string $role, string $feature, bool $on): bool
    {
        $owned = $this->ownedKeys($feature);
        if ($owned === []) {
            return false;
        }
        $current = ($this->grid($agencyId, [$feature])[$feature] ?? [])[$role] ?? false;
        if ($current === $on) {
            return false;
        }

        DB::transaction(function () use ($agencyId, $role, $feature, $on, $owned) {
            if (! $on) {
                RolePermission::where('agency_id', $agencyId)->where('role', $role)
                    ->whereIn('permission_key', $owned)
                    ->delete(); // soft — the tombstone records a deliberate revoke
                return;
            }

            $grant = $this->grantSetFor($agencyId, $role, $feature, $owned);
            $now = now();
            $rows = array_map(fn (string $key) => [
                'role' => $role, 'permission_key' => $key, 'scope' => null, 'agency_id' => $agencyId,
                'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null,
            ], $grant);
            // Same upsert as the Role Manager: a tombstoned row is restored (keeping its old scope),
            // never duplicated against the (role, permission_key, agency_id) unique index.
            RolePermission::upsert($rows, ['role', 'permission_key', 'agency_id'], ['updated_at', 'deleted_at']);
        });

        PermissionService::clearCache();

        return true;
    }

    /**
     * What a role gets when access is switched on: what it had before it was taken away (its
     * tombstones), else its standard role defaults for this feature, else just the way in.
     *
     * @param  list<string> $owned
     * @return list<string>
     */
    private function grantSetFor(int $agencyId, string $role, string $feature, array $owned): array
    {
        $previous = RolePermission::onlyTrashed()->where('agency_id', $agencyId)->where('role', $role)
            ->whereIn('permission_key', $owned)->pluck('permission_key')->all();
        if ($previous !== []) {
            return array_values(array_unique(array_merge($previous, $this->entryKeys($feature))));
        }

        $def = config('corex-permissions.role_defaults.' . $role);
        $all = array_column((array) config('corex-permissions.permissions', []), 'key');
        $defaults = $def === null ? [] : array_values(array_intersect(RoleDefaultsResolver::keysForDef($def, $all), $owned));

        return $defaults !== [] ? array_values(array_unique(array_merge($defaults, $this->entryKeys($feature)))) : $this->entryKeys($feature);
    }

    /** Can this user open this feature (it must also be switched on for their agency)? */
    public function userCanAccess(User $user, string $feature, ?Agency $agency = null): bool
    {
        if (! $this->features->enabled($feature, $agency)) {
            return false;
        }
        if ($user->isOwnerRole()) {
            return true;
        }
        $nav = (array) ($this->features->catalogue()[$feature]['nav_permission'] ?? []);
        $keys = $nav !== [] ? $nav : $this->ownedKeys($feature);

        return $keys === [] || PermissionService::userHasAnyPermission($user, $keys);
    }

    // ── The admin access review ─────────────────────────────────────────────────

    /**
     * Waves waiting for an access review, split by why: 'released' (went live without one — shown
     * first) and 'upcoming' (switch on next release day). Only waves with something to decide.
     *
     * @return array{released: Collection<int,AgencyRolloutWave>, upcoming: Collection<int,AgencyRolloutWave>}
     */
    public function pendingReviews(AgencyFeatureRollout $plan): array
    {
        $empty = ['released' => collect(), 'upcoming' => collect()];
        if ($plan->status === AgencyFeatureRollout::STATUS_CANCELLED) {
            return $empty;
        }

        $waves = $this->rollout->wavesOf($plan)->whereNull('access_reviewed_at');
        $today = $this->calendar->today();

        $released = $waves->filter(fn ($w) => $w->released_at !== null);
        $upcoming = $plan->status !== AgencyFeatureRollout::STATUS_ACTIVE ? collect() : $waves->filter(
            fn ($w) => $w->released_at === null && $w->scheduled_for !== null
                && $today->gte($this->calendar->previousReleaseDay($w->scheduled_for, $plan))
        );

        $decidable = fn (AgencyRolloutWave $w) => collect($this->grid((int) $plan->agency_id, $this->liveFeatures($w)))
            ->filter()->isNotEmpty();

        return ['released' => $released->filter($decidable)->values(), 'upcoming' => $upcoming->filter($decidable)->values()];
    }

    /** @return list<string> the wave's features that are still part of the plan */
    public function liveFeatures(AgencyRolloutWave $wave): array
    {
        return $this->rollout->itemsOf($wave)->whereIn('state', self::LIVE_STATES)->pluck('feature_key')->values()->all();
    }

    /**
     * @param list<int>                                            $waveIds
     * @param list<array{feature:string, role:string, on:bool}>    $changes what Save access changed (history, §12.10)
     */
    public function markReviewed(AgencyFeatureRollout $plan, array $waveIds, ?int $userId, string $decision = 'keep', array $changes = []): void
    {
        $titles = AgencyRolloutWave::queryWithoutAgencyScope()->where('rollout_id', $plan->id)->whereIn('id', $waveIds)->pluck('title')->all();
        AgencyRolloutWave::queryWithoutAgencyScope()
            ->where('rollout_id', $plan->id)->whereIn('id', $waveIds)->whereNull('access_reviewed_at')
            ->update(['access_reviewed_at' => now(), 'access_reviewed_by' => $userId]);
        event(new FeatureRolloutChanged((int) $plan->agency_id, 'access_reviewed', [
            'groups' => $titles, 'decision' => $decision,
            'changes' => array_map(fn ($c) => $c['feature'] . '|' . $c['role'] . '|' . ($c['on'] ? 'on' : 'off'), $changes),
        ], $userId));
    }

    // ── The release pop-up ──────────────────────────────────────────────────────

    /**
     * Released waves this user has not seen, each with the features they can open.
     * Waves with nothing for them are left out — a user without access sees nothing.
     *
     * @return list<array{wave: AgencyRolloutWave, features: list<array{key:string,label:string,explain:string,tour:?array}>}>
     */
    public function announcementsFor(User $user, AgencyFeatureRollout $plan): array
    {
        $since = Carbon::now()->subDays(self::RELEASE_POPUP_DAYS);
        $seen = RolloutAnnouncementView::queryWithoutAgencyScope()
            ->where('user_id', $user->id)->where('kind', RolloutAnnouncementView::KIND_RELEASE)
            ->pluck('wave_id')->flip();

        $waves = $this->rollout->wavesOf($plan)
            ->filter(fn ($w) => $w->released_at !== null && $w->released_at->gte($since)
                && ($user->created_at === null || $w->released_at->gte($user->created_at))
                && ! isset($seen[$w->id]))
            ->sortBy(fn ($w) => $w->released_at->timestamp);

        $catalogue = $this->features->catalogue();
        $agency = Agency::withoutGlobalScopes()->find($plan->agency_id);
        $tours = null;
        $out = [];
        foreach ($waves as $wave) {
            $features = [];
            $items = $this->rollout->itemsOf($wave)
                ->whereIn('state', [AgencyRolloutItem::STATE_RELEASED, AgencyRolloutItem::STATE_RELEASED_EARLY]);
            foreach ($items as $item) {
                $key = $item->feature_key;
                if (! $this->userCanAccess($user, $key, $agency)) {
                    continue;
                }
                $tours ??= $this->tours->toursByFeature();
                $features[] = [
                    'key'     => $key,
                    'label'   => (string) ($catalogue[$key]['label'] ?? $key),
                    'explain' => (string) ($catalogue[$key]['explain'] ?? ''),
                    'tour'    => $this->tourFor($user, $tours[$key] ?? []),
                ];
            }
            if ($features !== []) {
                $out[] = ['wave' => $wave, 'features' => $features];
            }
        }

        return $out;
    }

    /**
     * The first of a feature's tours this user can open, with where it launches.
     *
     * @param  list<array{key:string,title:string}> $tours
     * @return array{title:string,url:string,note:?string}|null
     */
    private function tourFor(User $user, array $tours): ?array
    {
        foreach ($tours as $t) {
            $tour = TourRegistry::find($t['key']);
            if (! $tour || ! TourRegistry::canOpen($tour, $user)) {
                continue;
            }
            $target = TourRegistry::launchTarget($tour);
            if ($target) {
                return ['title' => $t['title'], 'url' => $target['url'], 'note' => $target['note']];
            }
        }

        return null;
    }

    /** @param list<int> $waveIds */
    public function markSeen(User $user, AgencyFeatureRollout $plan, array $waveIds): void
    {
        $valid = AgencyRolloutWave::queryWithoutAgencyScope()
            ->where('rollout_id', $plan->id)->whereIn('id', $waveIds)->whereNotNull('released_at')->pluck('id');

        RolloutAnnouncementView::withoutAgencyStamping(function () use ($valid, $user, $plan) {
            foreach ($valid as $waveId) {
                RolloutAnnouncementView::queryWithoutAgencyScope()->withTrashed()->updateOrCreate(
                    ['wave_id' => $waveId, 'user_id' => $user->id, 'kind' => RolloutAnnouncementView::KIND_RELEASE],
                    ['agency_id' => $plan->agency_id, 'seen_at' => now(), 'deleted_at' => null],
                );
            }
        });
    }

    // ── Which pop-up a page shows ───────────────────────────────────────────────

    /**
     * At most ONE rollout pop-up per page, in the spec's order: released-unreviewed review, then the
     * upcoming review, then the release announcement. Waves the admin put off with "Later" this
     * session are skipped until their next sign-in.
     *
     * @param  list<int> $later
     * @return array{kind:string, plan:AgencyFeatureRollout, waves:Collection, grid?:array, roles?:Collection, announcements?:array}|null
     */
    public function popupFor(User $user, array $later = []): ?array
    {
        $agencyId = $user->effectiveAgencyId();
        $plan = $agencyId ? $this->rollout->forAgency((int) $agencyId) : null;
        if (! $plan) {
            return null;
        }

        if (! $user->isOwnerRole() && $user->hasPermission('manage_feature_rollout')) {
            $pending = $this->pendingReviews($plan);
            foreach (['released', 'upcoming'] as $kind) {
                $waves = $pending[$kind]->reject(fn ($w) => in_array($w->id, $later, true))->values();
                if ($waves->isNotEmpty()) {
                    $features = $waves->flatMap(fn ($w) => $this->liveFeatures($w))->unique()->values()->all();

                    return [
                        'kind'  => 'review_' . $kind,
                        'plan'  => $plan,
                        'waves' => $waves,
                        'grid'  => $this->grid((int) $plan->agency_id, $features),
                        'roles' => $this->editableRoles((int) $plan->agency_id),
                    ];
                }
            }
            // §3.5a: a released wave still waiting for this admin's review holds back their release pop-up.
            if ($pending['released']->isNotEmpty()) {
                return null;
            }
        }

        $announcements = $this->announcementsFor($user, $plan);

        return $announcements === [] ? null : [
            'kind' => 'release', 'plan' => $plan,
            'waves' => collect($announcements)->pluck('wave'), 'announcements' => $announcements,
        ];
    }
}
