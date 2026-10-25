<?php

namespace App\Services\FeatureRollout;

use App\Events\FeatureRollout\FeatureRolloutChanged;
use App\Http\Controllers\CoreX\RoleManagerController;
use App\Models\Agency;
use App\Models\CoreXPermission;
use App\Models\FeatureRollout\AgencyFeatureRollout;
use App\Models\FeatureRollout\AgencyRolloutItem;
use App\Models\FeatureRollout\AgencyRolloutWave;
use App\Models\FeatureRollout\RolloutAnnouncementView;
use App\Models\FeatureRollout\RolloutFeatureUser;
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
use Illuminate\Support\Str;

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

    /**
     * The permissions a feature owns for the rollout (what the review grants / revokes): its registry
     * permissions plus its own way in (`nav_permission`). Many features own no permission module but do
     * have a way-in permission (Billing, Finance Engine, Leases …) — without it they could not be given or
     * taken per role, and "Admin decides" let them through to everyone (spec §19.1).
     *
     * @return list<string>
     */
    public function ownedKeys(string $feature): array
    {
        static $catalogued = null;
        $catalogued ??= array_flip(array_column((array) config('corex-permissions.permissions', []), 'key'));
        $nav = array_filter((array) ($this->features->catalogue()[$feature]['nav_permission'] ?? []), fn ($k) => isset($catalogued[$k]));

        return array_values(array_unique(array_merge($this->features->permissionKeysFor($feature), $nav)));
    }

    /** False for a feature with no access control at all — it cannot be kept to some roles (spec §19.1). */
    public function canBeLimited(string $feature): bool
    {
        return $this->ownedKeys($feature) !== [];
    }

    /**
     * Other features that share this feature's way in: giving or taking one changes the other (spec §19.1.3).
     *
     * @param  list<string> $among only these features are compared
     * @return list<string>
     */
    public function sharesWayInWith(string $feature, array $among): array
    {
        $mine = $this->entryKeys($feature);
        if ($mine === []) {
            return [];
        }

        return array_values(array_filter($among, fn ($other) => $other !== $feature && array_intersect($mine, $this->entryKeys($other)) !== []));
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

    // ── The Role Manager's settings, for the review pop-up (spec §21) ─────────────

    /** The three actions the Role Manager lists first, in its order; every other action follows. */
    private const STANDARD_ACTIONS = ['create', 'edit', 'archive'];

    /** Modules whose Data Scope the Role Manager shows as one On / Off toggle (role-manager.blade.php). */
    private const TOGGLE_SCOPE_MODULES = ['properties', 'contacts'];

    /**
     * Everything the review pop-up needs to show a feature the way the Role Manager does: for each feature the
     * modules it owns with their access permissions, Data Scope and actions, plus what every editable role has now.
     *
     * @param  list<string> $featureKeys
     * @return array{
     *   roles: list<array{name:string,label:string}>,
     *   features: array<string, null|array{entry:list<string>, modules:list<array<string,mixed>>}>,
     *   granted: array<string, array<string,bool>>,
     *   scope: array<string, array<string,string>>,
     *   isDefault: array<string, array<string,bool>>,
     *   defaults: array<string,string>
     * }
     */
    public function settingsFor(int $agencyId, array $featureKeys): array
    {
        $roles = $this->editableRoles($agencyId);
        $names = $roles->pluck('name')->all();

        $owned = [];
        foreach ($featureKeys as $feature) {
            $owned[$feature] = $this->ownedKeys($feature);
        }
        $allKeys = array_values(array_unique(array_merge([], ...array_values($owned))));

        $perms = CoreXPermission::whereIn('key', $allKeys)->orderBy('section')->orderBy('sort_order')->orderBy('label')->get()->keyBy('key');

        $held = [];
        $stored = [];
        foreach (RolePermission::where('agency_id', $agencyId)->whereIn('role', $names)->whereIn('permission_key', $allKeys)->get(['role', 'permission_key', 'scope']) as $row) {
            $held[$row->permission_key][$row->role] = true;
            if ($row->scope) {
                $stored[$row->permission_key][$row->role] = $row->scope;
            }
        }

        $features = [];
        $granted = [];
        $scope = [];
        $isDefault = [];
        $defaults = [];
        foreach ($owned as $feature => $keys) {
            $modules = [];
            foreach ($perms as $key => $perm) {
                if (! in_array($key, $keys, true)) {
                    continue;
                }
                $module = $perm->module ?? $perm->section;
                $modules[$module] ??= ['key' => $module, 'access' => [], 'actions' => []];
                if ($perm->type === 'action') {
                    $modules[$module]['actions'][Str::afterLast($key, '.')] = ['key' => $key, 'label' => (string) $perm->label];
                } else {
                    $modules[$module]['access'][] = ['key' => $key, 'label' => (string) $perm->label, 'default' => $this->accessDefaultLine($key)];
                }
            }
            if ($modules === []) {
                $features[$feature] = null;
                continue;
            }

            $out = [];
            foreach ($modules as $module => $m) {
                $view = $m['actions']['view']['key'] ?? null;
                $standard = [];
                foreach (self::STANDARD_ACTIONS as $action) {
                    if (isset($m['actions'][$action])) {
                        $standard[] = ['action' => $action] + $m['actions'][$action];
                    }
                }
                $other = [];
                foreach (array_diff(array_keys($m['actions']), array_merge(['view'], self::STANDARD_ACTIONS)) as $action) {
                    $other[] = ['action' => $action] + $m['actions'][$action];
                }
                $out[] = [
                    'key'      => $module,
                    'label'    => RoleManagerController::MODULE_LABELS[$module] ?? Str::title(str_replace('_', ' ', $module)),
                    'view'     => $view,
                    'mode'     => $view === null ? 'none' : (in_array($module, RoleManagerController::SHARED_MODULES, true) ? 'shared'
                        : (in_array($module, self::TOGGLE_SCOPE_MODULES, true) ? 'toggle' : 'scope')),
                    'access'   => $m['access'],
                    'standard' => $standard,
                    'other'    => $other,
                ];

                foreach (array_merge(array_column($m['access'], 'key'), array_column($m['actions'], 'key')) as $key) {
                    foreach ($names as $role) {
                        $granted[$key][$role] = isset($held[$key][$role]);
                    }
                }
                if ($view !== null) {
                    $d = PermissionService::ungrantedScopeDefault(Str::beforeLast($view, '.view'));
                    $defaults[$view] = $this->defaultLine($d);
                    foreach ($names as $role) {
                        $scope[$view][$role] = $stored[$view][$role] ?? ($d['scope'] ?? 'none');
                        $isDefault[$view][$role] = ! isset($stored[$view][$role]);
                    }
                }
            }
            $features[$feature] = ['entry' => $this->entryKeys($feature), 'modules' => $out];
        }

        return [
            'roles'     => $roles->map(fn ($r) => ['name' => $r->name, 'label' => $r->label ?: $r->name])->values()->all(),
            'features'  => $features,
            'granted'   => $granted,
            'scope'     => $scope,
            'isDefault' => $isDefault,
            'defaults'  => $defaults,
        ];
    }

    /** The Role Manager's amber line for a .view key with no scope picker (AT-401). */
    private function accessDefaultLine(string $key): ?string
    {
        if (! str_ends_with($key, '.view')) {
            return null;
        }
        $d = PermissionService::ungrantedScopeDefault(Str::beforeLast($key, '.view'));

        return $d['read'] ? 'Default breadth when no scope is stored: ' . $this->scopeWord($d['scope']) . ' — ' . $d['note'] . ($d['varies'] ? '. Varies by screen.' : '.') : null;
    }

    /** What the system applies while nothing is stored for a role (the Role Manager's "Default" line). */
    private function defaultLine(array $d): string
    {
        if (! $d['read']) {
            return 'Default — ' . $d['note'] . '.';
        }

        return 'Default (nothing stored for this role) — the system applies ' . $this->scopeWord($d['scope']) . ': ' . $d['note'] . ($d['varies'] ? '. Varies by screen.' : '.');
    }

    private function scopeWord(?string $scope): string
    {
        return ['own' => 'Own', 'branch' => 'Branch', 'all' => 'All'][$scope ?? ''] ?? 'None';
    }

    /**
     * Write what the admin changed in the review: single permissions (`$settings`: role => key => on) and Data Scope
     * (`$scopes`: role => view key => none|own|branch|all). Only permission keys the reviewed features own and only
     * the agency's editable roles are honoured. Rules are the Role Manager's: Data Scope None removes view, create, edit
     * and archive; ticking create / edit / archive needs a view row (Own when none); a revoke is a soft delete and a grant
     * restores the tombstoned row, keeping its old scope unless one was chosen.
     *
     * @param  list<string> $featureKeys
     * @return list<array{feature:string,role:string,state:string}> state = on | off | changed (per feature and role)
     */
    public function applySettings(int $agencyId, array $featureKeys, array $settings, array $scopes): array
    {
        $roles = $this->editableRoles($agencyId)->pluck('name')->all();
        $allowed = array_flip(array_values(array_unique(array_merge([], ...array_map(fn ($f) => $this->ownedKeys($f), $featureKeys)))));
        $changes = [];

        foreach (array_unique(array_merge(array_keys($settings), array_keys($scopes))) as $role) {
            if (! in_array($role, $roles, true)) {
                continue;
            }
            $changes = array_merge($changes, $this->applyForRole(
                $agencyId, (string) $role, $featureKeys, $allowed,
                array_intersect_key((array) ($settings[$role] ?? []), $allowed),
                array_intersect_key((array) ($scopes[$role] ?? []), $allowed),
            ));
        }
        if ($changes !== []) {
            PermissionService::clearCache();
        }

        return $changes;
    }

    /**
     * @param  list<string>          $featureKeys
     * @param  array<string,int>     $allowed key => index
     * @param  array<string,mixed>   $wantKeys  key => on
     * @param  array<string,mixed>   $wantScopes view key => scope
     * @return list<array{feature:string,role:string,state:string}>
     */
    private function applyForRole(int $agencyId, string $role, array $featureKeys, array $allowed, array $wantKeys, array $wantScopes): array
    {
        $held = RolePermission::where('agency_id', $agencyId)->where('role', $role)->pluck('scope', 'permission_key')->all();
        $desired = $held;
        $setScope = [];

        foreach ($wantScopes as $view => $value) {
            if (! str_ends_with((string) $view, '.view') || ! in_array($value, ['none', 'own', 'branch', 'all'], true)) {
                continue;
            }
            if ($value === 'none') {
                $prefix = Str::beforeLast((string) $view, '.view');
                foreach (array_merge(['view'], self::STANDARD_ACTIONS) as $action) {
                    if (isset($allowed[$prefix . '.' . $action])) {
                        unset($desired[$prefix . '.' . $action]);
                    }
                }
            } else {
                $desired[$view] = $value;
                $setScope[$view] = $value;
            }
        }

        foreach ($wantKeys as $key => $on) {
            if (! filter_var($on, FILTER_VALIDATE_BOOLEAN)) {
                unset($desired[$key]);
                continue;
            }
            if (! array_key_exists($key, $desired)) {
                $desired[$key] = null;
            }
            $action = Str::afterLast((string) $key, '.');
            $view = Str::beforeLast((string) $key, '.') . '.view';
            if (in_array($action, self::STANDARD_ACTIONS, true) && isset($allowed[$view]) && ! array_key_exists($view, $desired)) {
                $desired[$view] = 'own';
                $setScope[$view] = 'own';
            }
        }

        // Branch isolation (Role Manager): editing across branches implies viewing across branches.
        if (array_key_exists('branches.edit_all', $desired) && isset($allowed['branches.view_all']) && ! array_key_exists('branches.view_all', $desired)) {
            $desired['branches.view_all'] = null;
        }

        $revoke = array_values(array_filter(array_keys(array_diff_key($held, $desired)), fn ($k) => isset($allowed[$k])));
        $grant = array_values(array_filter(array_keys(array_diff_key($desired, $held)), fn ($k) => isset($allowed[$k])));
        $rescope = array_values(array_filter(array_keys($setScope), fn ($k) => array_key_exists($k, $held) && $held[$k] !== $setScope[$k]));
        if ($revoke === [] && $grant === [] && $rescope === []) {
            return [];
        }

        DB::transaction(function () use ($agencyId, $role, $revoke, $grant, $rescope, $setScope) {
            if ($revoke !== []) {
                RolePermission::where('agency_id', $agencyId)->where('role', $role)->whereIn('permission_key', $revoke)->delete(); // soft
            }
            $now = now();
            $row = fn (string $key, ?string $scope) => [
                'role' => $role, 'permission_key' => $key, 'scope' => $scope, 'agency_id' => $agencyId,
                'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null,
            ];
            $withScope = array_map(fn ($k) => $row($k, $setScope[$k]), array_values(array_unique(array_merge(array_intersect($grant, array_keys($setScope)), $rescope))));
            $plain = array_map(fn ($k) => $row($k, null), array_values(array_diff($grant, array_keys($setScope))));
            // Same upserts as the Role Manager: a tombstoned row is restored, never duplicated against the unique index.
            if ($withScope !== []) {
                RolePermission::upsert($withScope, ['role', 'permission_key', 'agency_id'], ['scope', 'updated_at', 'deleted_at']);
            }
            if ($plain !== []) {
                RolePermission::upsert($plain, ['role', 'permission_key', 'agency_id'], ['updated_at', 'deleted_at']);
            }
        });

        $touched = array_flip(array_merge($revoke, $grant, $rescope));
        $out = [];
        foreach ($featureKeys as $feature) {
            $owned = $this->ownedKeys($feature);
            if (array_intersect_key($touched, array_flip($owned)) === []) {
                continue;
            }
            $entry = array_flip($this->entryKeys($feature));
            $before = array_intersect_key($held, $entry) !== [];
            $after = array_intersect_key($desired, $entry) !== [];
            $out[] = ['feature' => $feature, 'role' => $role, 'state' => $before === $after ? 'changed' : ($after ? 'on' : 'off')];
        }

        return $out;
    }

    // ── "Admin decides" (§18) ───────────────────────────────────────────────────

    /**
     * The agency's admin roles: the editable roles that may manage the rollout (the people who get the access
     * pop-up). An agency with no such grant yet falls back to its role named "admin".
     *
     * @return list<string>
     */
    public function adminRoles(int $agencyId): array
    {
        $roles = $this->editableRoles($agencyId)->pluck('name')->all();
        $admins = RolePermission::where('agency_id', $agencyId)->whereIn('role', $roles)
            ->where('permission_key', 'manage_feature_rollout')->pluck('role')->unique()->values()->all();

        return $admins !== [] ? $admins : array_values(array_intersect($roles, ['admin']));
    }

    /**
     * Limit features to the agency's admin roles: every other role's access is taken away (soft — a later
     * grant restores exactly what that role had), and the admin roles are given it.
     *
     * @param list<string> $keys
     */
    public function keepToAdmins(int $agencyId, array $keys): void
    {
        $admins = $this->adminRoles($agencyId);
        foreach ($this->editableRoles($agencyId)->pluck('name') as $role) {
            foreach ($keys as $key) {
                $this->setRoleAccess($agencyId, (string) $role, (string) $key, in_array($role, $admins, true));
            }
        }
    }

    /**
     * The plan's "Admin decides" features that are on, for the Feature rollout page's "who else sees these".
     *
     * @return list<string>
     */
    public function adminDecidesFeatures(AgencyFeatureRollout $plan): array
    {
        return $this->rollout->wavesOf($plan)
            ->filter(fn (AgencyRolloutWave $w) => $w->isAdminDecides() && $w->released_at !== null)
            ->flatMap(fn (AgencyRolloutWave $w) => $this->rollout->itemsOf($w)
                ->whereIn('state', [AgencyRolloutItem::STATE_RELEASED, AgencyRolloutItem::STATE_RELEASED_EARLY])
                ->pluck('feature_key'))
            ->unique()->values()->all();
    }

    /**
     * Will this person get a feature that is still held back (spec §19.2.3)? Their role holds its way in
     * now — a held feature is switched off, so the usual permission check (which hides an OFF feature's
     * permissions) cannot answer this. A feature with no access control reaches everyone.
     */
    public function roleWillGet(User $user, string $feature): bool
    {
        if ($user->isOwnerRole()) {
            return true;
        }
        $entry = $this->entryKeys($feature);
        if ($entry === []) {
            return true;
        }

        return RolePermission::where('agency_id', (int) $user->effectiveAgencyId())
            ->where('role', (string) $user->role)->whereIn('permission_key', $entry)->exists();
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
        // "Admin decides" (§18) has no day-before review: it switches on for the admins only, and they decide after.
        $upcoming = $plan->status !== AgencyFeatureRollout::STATUS_ACTIVE ? collect() : $waves->filter(
            fn ($w) => $w->released_at === null && $w->scheduled_for !== null && ! $w->isAdminDecides()
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
            'changes' => array_map(fn ($c) => $c['feature'] . '|' . $c['role'] . '|' . ($c['state'] ?? ($c['on'] ? 'on' : 'off')), $changes),
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

        // §18 — an "Admin decides" feature is announced to each person once, whenever they are given it (the
        // admins when it switches on, anyone else when an admin later gives their role access), not per group.
        $announced = RolloutFeatureUser::queryWithoutAgencyScope()->where('agency_id', $plan->agency_id)
            ->where('user_id', $user->id)->whereNotNull('announced_at')->pluck('feature_key')->flip();

        $waves = $this->rollout->wavesOf($plan)
            ->filter(fn ($w) => $w->released_at !== null && ($w->isAdminDecides() || ($w->released_at->gte($since)
                && ($user->created_at === null || $w->released_at->gte($user->created_at))
                && ! isset($seen[$w->id]))))
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
                if ($wave->isAdminDecides() && isset($announced[$key])) {
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

        $agency = Agency::withoutGlobalScopes()->find($plan->agency_id);
        $adminWaves = AgencyRolloutWave::queryWithoutAgencyScope()->whereIn('id', $valid)->where('lane', AgencyRolloutWave::LANE_ADMIN)->get();
        foreach ($adminWaves as $wave) {
            foreach ($this->rollout->itemsOf($wave)->whereIn('state', [AgencyRolloutItem::STATE_RELEASED, AgencyRolloutItem::STATE_RELEASED_EARLY]) as $item) {
                if ($this->userCanAccess($user, $item->feature_key, $agency)) {
                    app(FeatureRolloutUsage::class)->mark((int) $plan->agency_id, (int) $user->id, $item->feature_key, 'announced_at');
                }
            }
        }

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
            // §18 — "Admin decides" groups that switched on ask "who else should see these?" (review_admin).
            $byKind = [
                'released' => $pending['released']->reject(fn ($w) => $w->isAdminDecides()),
                'upcoming' => $pending['upcoming'],
                'admin'    => $pending['released']->filter(fn ($w) => $w->isAdminDecides()),
            ];
            foreach ($byKind as $kind => $kindWaves) {
                $waves = $kindWaves->reject(fn ($w) => in_array($w->id, $later, true))->values();
                if ($waves->isNotEmpty()) {
                    $features = $waves->flatMap(fn ($w) => $this->liveFeatures($w))->unique()->values()->all();

                    return [
                        'kind'  => 'review_' . $kind,
                        'plan'  => $plan,
                        'waves' => $waves,
                        'grid'  => $this->grid((int) $plan->agency_id, $features),
                        'settings' => $this->settingsFor((int) $plan->agency_id, $features),
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
