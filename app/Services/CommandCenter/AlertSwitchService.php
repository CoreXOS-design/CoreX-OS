<?php

namespace App\Services\CommandCenter;

use App\Models\Branch;
use App\Models\CommandCenter\AgencyDashboardSetting;
use App\Models\CommandCenter\UserDashboardSetting;
use App\Models\User;
use App\Models\UserAlertDecision;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AT-451 — "alerts all switched off" is allowed (off stays off, the dispatcher keeps dropping) but is never
 * unnoticed. This service owns: whether a user is in that state, the warning bar's rules, the two ways a decision is
 * made (accept it / turn in-app alerts back on) and the append-only record of it, the agency-wide scoped list of
 * affected users, and the live-popup poll interval.
 *
 * "All off" is the same test the dispatcher applies: none of in-app / email / push is on after the agency-lock
 * rules (NotificationPreferenceService::masterSwitchState).
 */
class AlertSwitchService
{
    public const DEFAULT_POLL_SECONDS = 30;
    public const MIN_POLL_SECONDS = 15;
    public const MAX_POLL_SECONDS = 600;

    public function __construct(private NotificationPreferenceService $prefs) {}

    // ── The live-popup poll interval (agency setting) ───────────────────────────────────────────

    /** An interval as saved or read: whole seconds, never below 15, never above 10 minutes, blank = the default 30. */
    public static function clampPollSeconds(mixed $value): int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return self::DEFAULT_POLL_SECONDS;
        }

        return max(self::MIN_POLL_SECONDS, min(self::MAX_POLL_SECONDS, (int) $value));
    }

    public function pollSecondsFor(?int $agencyId): int
    {
        if (! $agencyId) {
            return self::DEFAULT_POLL_SECONDS;
        }

        try {
            $value = AgencyDashboardSetting::withoutGlobalScopes()->where('agency_id', $agencyId)->value('alert_poll_seconds');
        } catch (\Throwable $e) {
            return self::DEFAULT_POLL_SECONDS;
        }

        return self::clampPollSeconds($value);
    }

    // ── State ──────────────────────────────────────────────────────────────────────────────────

    /** @param array{in_app:bool,email:bool,push:bool} $state */
    public static function isAllOff(array $state): bool
    {
        return ! $state['in_app'] && ! $state['email'] && ! $state['push'];
    }

    /**
     * The warning bar for a logged-in user, or null when there is nothing to warn about: not all-off, or the user
     * (or an admin for them) has accepted exactly this state. Any later change to the notification settings that
     * still leaves everything off brings the bar back. Never throws — a failing bar must not break a page.
     *
     * @return array{locked:bool}|null
     */
    public function barStateFor(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        try {
            $state = $this->prefs->masterSwitchState($user);
            if (! self::isAllOff($state)) {
                return null;
            }
            if ($this->isAccepted($this->latestDecision($user->id), $state['stamp'])) {
                return null;
            }

            return ['locked' => $state['locked']];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function latestDecision(int $userId): ?UserAlertDecision
    {
        return UserAlertDecision::withoutGlobalScopes()->where('user_id', $userId)->orderByDesc('id')->first();
    }

    private function isAccepted(?UserAlertDecision $latest, ?string $currentStamp): bool
    {
        return $latest !== null
            && $latest->decision === UserAlertDecision::ACCEPTED_OFF
            && ($latest->settings_stamp?->format('Y-m-d H:i:s')) === $currentStamp;
    }

    // ── Decisions (each one is recorded, append-only) ───────────────────────────────────────────

    /**
     * "Keep them off" / "Accept - no alerts for this user". $by is the person clicking (the user themselves from the
     * bar, an administrator from the agency list).
     *
     * @throws \DomainException when the user does not actually have every alert off
     */
    public function acceptOff(User $subject, User $by): UserAlertDecision
    {
        $state = $this->prefs->masterSwitchState($subject);
        if (! self::isAllOff($state)) {
            throw new \DomainException($this->nameOf($subject) . ' already has alerts switched on.');
        }

        return $this->record($subject, $by, UserAlertDecision::ACCEPTED_OFF, $state);
    }

    /**
     * "Turn alerts on": the user's in-app switch goes on (email and push are left exactly as they were) and the
     * decision is recorded with the switch state as it stands afterwards.
     *
     * @throws \DomainException when the agency controls the switches centrally, or alerts are already on
     */
    public function turnOn(User $subject, User $by): UserAlertDecision
    {
        $before = $this->prefs->masterSwitchState($subject);
        if (! self::isAllOff($before)) {
            throw new \DomainException($this->nameOf($subject) . ' already has alerts switched on.');
        }
        if ($before['locked']) {
            throw new \DomainException('Your agency sets the alert switches for everyone, so this cannot be changed per person. An administrator can change them under Settings, Notifications.');
        }

        return DB::transaction(function () use ($subject, $by) {
            $row = UserDashboardSetting::firstOrCreate(['user_id' => $subject->id], UserDashboardSetting::defaults());
            $row->update(['notify_in_app' => true]);

            return $this->record($subject, $by, UserAlertDecision::TURNED_ON, $this->prefs->masterSwitchState($subject));
        });
    }

    /** @param array{in_app:bool,email:bool,push:bool,stamp:?string} $state */
    private function record(User $subject, User $by, string $decision, array $state): UserAlertDecision
    {
        return UserAlertDecision::withoutGlobalScopes()->create([
            'agency_id' => $subject->effectiveAgencyId() ?: $subject->agency_id,
            'user_id' => $subject->id,
            'decided_by_user_id' => $by->id,
            'decision' => $decision,
            'switch_in_app' => $state['in_app'],
            'switch_email' => $state['email'],
            'switch_push' => $state['push'],
            'settings_stamp' => $state['stamp'],
        ]);
    }

    private function nameOf(User $user): string
    {
        return trim((string) $user->name) !== '' ? $user->name : 'This user';
    }

    // ── The agency list ────────────────────────────────────────────────────────────────────────

    /**
     * Active users the viewer may see, own / branch / agency scoped IN THE QUERY (the user-management data scope; with
     * no scope row the viewer is held to their own branch rather than shown nothing). Null = the viewer has no agency
     * context, so there is nothing to list — never "every agency".
     */
    public function scopedUsers(User $viewer): ?Builder
    {
        $agencyId = $viewer->effectiveAgencyId();
        if (! $agencyId) {
            return null;
        }

        $query = User::query()
            ->where('users.agency_id', $agencyId)
            ->whereNull('users.deleted_at')
            ->where('users.is_active', 1);

        $scope = PermissionService::getDataScope($viewer, 'users') ?? 'branch';

        if ($scope === 'own') {
            return $query->where('users.id', $viewer->id);
        }
        if ($scope === 'branch') {
            $branchId = $viewer->effectiveBranchId();

            return $branchId ? $query->where('users.branch_id', $branchId) : $query->whereRaw('1 = 0');
        }

        return $query; // 'all' — the whole agency (agency_id above is the hard boundary)
    }

    /**
     * Every user in the viewer's scope who has every alert switched off, with their latest decision and history.
     *
     * @param  array{q?:?string,status?:?string,branch_id?:?int,from?:?string,to?:?string,sort?:?string,dir?:?string}  $filters
     * @return Collection<int,array<string,mixed>>
     */
    public function allOffRows(User $viewer, array $filters = []): Collection
    {
        $query = $this->scopedUsers($viewer);
        if (! $query) {
            return collect();
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $query->where(fn ($w) => $w->where('users.name', 'like', $like)->orWhere('users.email', 'like', $like));
        }
        if (! empty($filters['branch_id'])) {
            $query->where('users.branch_id', (int) $filters['branch_id']);
        }

        $users = $query->get(['users.id', 'users.name', 'users.email', 'users.role', 'users.branch_id', 'users.agency_id']);
        if ($users->isEmpty()) {
            return collect();
        }

        $agencyId = (int) $viewer->effectiveAgencyId();
        $states = $this->prefs->masterSwitchStatesForAgency($users, $agencyId);
        $allOff = $users->filter(fn ($u) => self::isAllOff($states[$u->id]));
        if ($allOff->isEmpty()) {
            return collect();
        }

        $branches = Branch::query()->whereIn('id', $allOff->pluck('branch_id')->filter()->unique())->pluck('name', 'id');
        $decisions = UserAlertDecision::withoutGlobalScopes()
            ->whereIn('user_id', $allOff->pluck('id'))
            ->with('decidedBy:id,name')
            ->orderByDesc('id')
            ->get()
            ->groupBy('user_id');

        $rows = $allOff->map(function (User $u) use ($states, $branches, $decisions) {
            $state = $states[$u->id];
            $history = $decisions->get($u->id, collect());
            $latest = $history->first();
            $accepted = $this->isAccepted($latest, $state['stamp']);

            return [
                'user' => $u,
                'branch' => $u->branch_id ? ($branches[$u->branch_id] ?? '—') : '—',
                'locked' => $state['locked'],
                'since' => $state['stamp'] ? \Illuminate\Support\Carbon::parse($state['stamp']) : null,
                'accepted' => $accepted,
                'accepted_by_self' => $accepted ? $latest->decidedByUser() : null,
                'accepted_by_name' => $accepted ? ($latest->decidedBy?->name) : null,
                'history' => $history->take(8)->values(),
            ];
        });

        $status = $filters['status'] ?? 'all';
        if ($status === 'open') {
            $rows = $rows->reject(fn ($r) => $r['accepted']);
        } elseif ($status === 'accepted') {
            $rows = $rows->filter(fn ($r) => $r['accepted']);
        }
        if (! empty($filters['from'])) {
            $from = \Illuminate\Support\Carbon::parse($filters['from'])->startOfDay();
            $rows = $rows->filter(fn ($r) => $r['since'] && $r['since']->gte($from));
        }
        if (! empty($filters['to'])) {
            $to = \Illuminate\Support\Carbon::parse($filters['to'])->endOfDay();
            $rows = $rows->filter(fn ($r) => $r['since'] && $r['since']->lte($to));
        }

        // Default order: longest-off first (the ones nobody has looked at for longest). Users with no stamp sort last.
        $sort = $filters['sort'] ?? 'since';
        $desc = ($filters['dir'] ?? 'asc') === 'desc';
        $key = match ($sort) {
            'name' => fn ($r) => mb_strtolower((string) $r['user']->name),
            'role' => fn ($r) => (string) $r['user']->role,
            'branch' => fn ($r) => mb_strtolower((string) $r['branch']),
            default => fn ($r) => $r['since'] ? $r['since']->timestamp : PHP_INT_MAX,
        };

        return $rows->sortBy($key, SORT_REGULAR, $desc)->values();
    }

    /** The compact line's number: how many users in the viewer's scope have every alert off. */
    public function allOffCount(User $viewer): int
    {
        try {
            return $this->allOffRows($viewer)->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Find one user the viewer may act on — inside the SAME scoped query as the list, so a direct URL for someone
     * outside the viewer's own / branch / agency answers 404, not a quiet success.
     */
    public function findScopedUser(User $viewer, int $userId): ?User
    {
        return $this->scopedUsers($viewer)?->where('users.id', $userId)->first();
    }
}
