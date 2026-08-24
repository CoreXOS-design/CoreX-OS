<?php

namespace App\Services;

use App\Models\Scopes\AgencyScope;
use App\Models\SystemUpdate;
use App\Models\SystemUpdateView;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * System Updates — eligibility, dismissal and adoption.
 *
 * Spec: .ai/specs/system-updates.md §8, §9.6.
 *
 * The single place that answers "should this user see this update?". The modal
 * partial, the archive page and the dismiss endpoint all route through here, so the
 * eligibility rule cannot drift between surfaces.
 */
class SystemUpdateService
{
    /**
     * The published list, cached as scalars (spec §9.6).
     *
     * Deliberately holds only what eligibility needs, so the common "nothing to
     * say" case is answered without touching the database at all. Busted by
     * SystemUpdate::booted() on every save/delete/restore, so no caller can forget.
     *
     * @return array<int,array{id:int,published_at:string}>
     */
    public function publishedList(): array
    {
        return Cache::rememberForever((string) config('system-updates.cache_key'), function () {
            return SystemUpdate::live()
                ->orderByDesc('published_at')
                ->get(['id', 'published_at'])
                ->map(fn (SystemUpdate $u) => [
                    'id'           => (int) $u->id,
                    'published_at' => $u->published_at?->toIso8601String(),
                ])
                ->all();
        });
    }

    public static function bustCache(): void
    {
        Cache::forget((string) config('system-updates.cache_key'));
    }

    /**
     * Candidate ids for a user, resolved WITHOUT a database query (spec §9.6).
     *
     * Applies eligibility rules 1-2 (published, not-before-my-account) against the
     * cached scalar list. Returns [] for the overwhelming majority of page loads,
     * so the caller can short-circuit before issuing any SQL.
     *
     * @return array<int,int>
     */
    public function candidateIdsFor(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $joinedAt = $user->created_at;

        $ids = [];
        foreach ($this->publishedList() as $row) {
            // Rule 2 — a user never sees changes that predate their own account.
            // Without this, an agent joining next March meets forty historical
            // notes about features that, to them, are simply how CoreX works.
            if ($joinedAt && $row['published_at'] && $row['published_at'] < $joinedAt->toIso8601String()) {
                continue;
            }

            $ids[] = $row['id'];
        }

        return $ids;
    }

    /**
     * Updates this user has not yet acknowledged, newest first.
     *
     * One query when candidates exist, zero when they don't. The dismissal check is
     * a correlated NOT EXISTS so the per-update re-notify watermark (spec §7.4) is
     * applied in SQL rather than by loading every view row.
     */
    public function pendingFor(?User $user): Collection
    {
        $candidateIds = $this->candidateIdsFor($user);

        if ($candidateIds === [] || ! $user) {
            return new Collection();
        }

        return SystemUpdate::live()
            ->whereIn('id', $candidateIds)
            ->whereNotExists(function ($q) use ($user) {
                $q->select(DB::raw(1))
                  ->from('system_update_views')
                  ->whereColumn('system_update_views.system_update_id', 'system_updates.id')
                  ->where('system_update_views.user_id', $user->id)
                  // Rule 4 — a dismissal only counts if it happened STRICTLY AFTER
                  // the acknowledgement floor (notify_reset_at, else published_at).
                  //
                  // Strict '>' is deliberate: "re-notify at time T" means every
                  // dismissal up to and including T is void. With '>=', a user who
                  // closed the modal in the same second the owner clicked
                  // "Re-notify everyone" would never be shown it again — silently,
                  // and only for them.
                  ->whereRaw('system_update_views.dismissed_at > COALESCE(system_updates.notify_reset_at, system_updates.published_at)');
            })
            ->orderByDesc('published_at')
            ->get();
    }

    /**
     * The capped set the modal shows, plus how many more are waiting (spec §8.3).
     *
     * @return array{updates:Collection,overflow:int}
     */
    public function modalPayloadFor(?User $user): array
    {
        $cap     = (int) config('system-updates.modal_cap', 5);
        $pending = $this->pendingFor($user);

        return [
            'updates'  => $pending->take($cap),
            'overflow' => max(0, $pending->count() - $cap),
        ];
    }

    /**
     * Everything this user is eligible to read, for the What's New archive
     * (spec §7.5) — including what they have already dismissed.
     */
    public function archiveFor(?User $user): Collection
    {
        $candidateIds = $this->candidateIdsFor($user);

        if ($candidateIds === []) {
            return new Collection();
        }

        return SystemUpdate::live()
            ->whereIn('id', $candidateIds)
            ->orderByDesc('published_at')
            ->get();
    }

    /**
     * Mark updates dismissed for this user (spec §11.2).
     *
     * Idempotent by design: updateOrCreate means a double-click, a retry or a
     * duplicated request is harmless. Ids the user is not eligible for (archived,
     * unknown, or published before they joined) are ignored rather than rejected —
     * a stale modal must never hand the user an error.
     *
     * @param  array<int,mixed>  $ids
     * @return int  how many were actually recorded
     */
    public function dismiss(?User $user, array $ids): int
    {
        if (! $user || $ids === []) {
            return 0;
        }

        $requested = array_filter(array_map('intval', $ids));
        $eligible  = array_intersect($requested, $this->candidateIdsFor($user));

        if ($eligible === []) {
            return 0;
        }

        // Only ids that are genuinely live right now — an update archived while the
        // modal was open is silently skipped (spec §9.4).
        $liveIds = SystemUpdate::live()->whereIn('id', $eligible)->pluck('id');

        foreach ($liveIds as $id) {
            SystemUpdateView::updateOrCreate(
                ['system_update_id' => $id, 'user_id' => $user->id],
                ['dismissed_at' => now()],
            );
        }

        return $liveIds->count();
    }

    /**
     * How many users this update was addressed to — the adoption denominator
     * (spec §7.1). Every update goes to everyone, so this is simply every user.
     *
     * Runs only on the owner-only admin screens, never on the hot path.
     *
     * The AgencyScope drop is deliberate and necessary: this is a cross-agency
     * owner view, and the scope's owner bypass STOPS the moment the owner has
     * entered an agency via the switcher (session active_agency_id) — which would
     * silently return a single agency's count with no error. Documented exception
     * per SYSTEM.md multi-tenancy rule 3; safe because the whole surface is
     * owner_only.
     */
    public function audienceUserCount(SystemUpdate $update): int
    {
        return User::withoutGlobalScope(AgencyScope::class)->count();
    }

    /** How many users have acknowledged this update (spec §7.1). */
    public function seenCount(SystemUpdate $update): int
    {
        // Strict '>' mirrors pendingFor() exactly — if the two ever disagreed, the
        // adoption count would contradict who the modal actually shows it to.
        return SystemUpdateView::where('system_update_id', $update->id)
            ->where('dismissed_at', '>', $update->acknowledgementFloor() ?? now()->subCentury())
            ->count();
    }
}
