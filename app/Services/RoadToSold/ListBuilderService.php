<?php

namespace App\Services\RoadToSold;

use App\Models\RoadToSold\RtsListItem;
use App\Models\User;
use App\Services\RoadToSold\Adapters\LaneAreaProvider;
use App\Services\RoadToSold\Adapters\LaneWorkCalendar;
use App\Services\RoadToSold\Adapters\LaneFollowUpSource;
use App\Services\RoadToSold\Adapters\LaneSuggestionGuard;
use App\Services\RoadToSold\Contracts\AreaProvider;
use App\Services\RoadToSold\Contracts\FollowUpSource;
use App\Services\RoadToSold\Contracts\SuggestionGuard;
use App\Services\RoadToSold\Contracts\WorkCalendar;
use App\Services\RoadToSold\Selections\Candidate;
use App\Services\RoadToSold\Selections\SelectionContext;
use App\Services\RoadToSold\Support\BuilderSettings;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Road to Sold — builds each agent's daily call list (AT-454, spec §9).
 *
 *   buildFor()      the day's list: follow-ups due, then rolled-over items, then new properties from the
 *                   agency's selections IN THE AGENCY'S ORDER until the day's target is reached.
 *                   Idempotent: it tops the list up to the target, so a second run adds nothing.
 *   addToDay()      the agent asks for extra from one selection; origin "added", reported apart,
 *                   never counted toward the target.
 *   closePastDays() end-of-day housekeeping: open items from earlier days become "missed", or are
 *                   "rolled" onto the next working day when the agency rolls over.
 *   markWorked()    called by the outcome capture (lane 3) when a property has been called.
 *
 * Everything the agency can change (which selections, their order, the windows, the lock, rolling
 * over) comes from settings; targets and working days from the WorkCalendar. Nothing is hard-coded to
 * one agency or to sales — `$track` is carried through for Road to Rented.
 */
class ListBuilderService
{
    public const MAX_ADD = 100;

    private SelectionRegistry $registry;
    private WorkCalendar $calendar;
    private AreaProvider $areas;
    private SuggestionGuard $guard;
    private FollowUpSource $followUps;
    private Closure $settingsResolver;

    /** @param Closure|null $settingsResolver fn(User, string $track): BuilderSettings */
    public function __construct(
        ?SelectionRegistry $registry = null,
        ?WorkCalendar $calendar = null,
        ?AreaProvider $areas = null,
        ?SuggestionGuard $guard = null,
        ?FollowUpSource $followUps = null,
        ?Closure $settingsResolver = null,
    ) {
        $this->registry = $registry ?? new SelectionRegistry();
        $this->calendar = $calendar ?? new LaneWorkCalendar();
        $this->areas = $areas ?? new LaneAreaProvider();
        $this->guard = $guard ?? new LaneSuggestionGuard();
        $this->followUps = $followUps ?? new LaneFollowUpSource();
        $this->settingsResolver = $settingsResolver ?? fn (User $u, string $track) => BuilderSettings::forUser($u, $track);
    }

    // ── build ────────────────────────────────────────────────────────────

    /**
     * The agent's list for the day, built (or topped up) if it is short of the target.
     *
     * @return Collection<int,RtsListItem> ordered by position; empty on a non-working day, for an agent
     *                                     with no agency, or when nothing is eligible
     */
    public function buildFor(User $user, CarbonInterface|string $date, string $track = RtsListItem::TRACK_SALES): Collection
    {
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        $day = Carbon::parse($date)->startOfDay();
        if ($agencyId <= 0) {
            return collect();   // STANDARDS Rule 17: no agency context, nothing is written
        }

        $lock = Cache::lock("rts-build:{$agencyId}:{$user->id}:{$track}:{$day->toDateString()}", 120);

        return $lock->block(15, function () use ($user, $agencyId, $day, $track) {
            $this->topUp($user, $agencyId, $day, $track);

            return $this->listFor($user, $day, $track);
        });
    }

    /** The stored list for the day, in order (no building). */
    public function listFor(User $user, CarbonInterface|string $date, string $track = RtsListItem::TRACK_SALES): Collection
    {
        return RtsListItem::query()->forDay($user, $date, $track)->orderBy('position')->orderBy('id')->get();
    }

    private function topUp(User $user, int $agencyId, Carbon $day, string $track): void
    {
        if (! $this->calendar->isWorkday($user, $day, $track)) {
            return;
        }
        $target = $this->calendar->targetFor($user, $day, $track);
        $settings = ($this->settingsResolver)($user, $track);

        // A cheap guard so a page opened ten times does not run the selection queries ten times
        // when the pool is simply short today.
        $marker = "rts-built:{$agencyId}:{$user->id}:{$track}:{$day->toDateString()}";
        $exists = RtsListItem::query()->forDay($user, $day, $track)->exists();
        if ($exists && Cache::has($marker)) {
            return;
        }

        // 1. follow-ups due (they count toward the target, and are never trimmed to fit it)
        $this->addFollowUps($user, $agencyId, $day, $track);

        // 2. new properties, selection by selection, in the agency's order
        $counted = $this->countedToward($user, $day, $track);
        $remaining = $target - $counted;
        if ($remaining > 0) {
            foreach ($settings->enabledSelectionKeys as $key) {
                if ($remaining <= 0) {
                    break;
                }
                $selection = $this->registry->get($key);
                if ($selection === null) {
                    continue;
                }
                $candidates = $selection->candidates($this->context($user, $agencyId, $day, $track, $settings), $remaining);
                foreach ($candidates as $candidate) {
                    if ($this->insert($user, $agencyId, $day, $track, $candidate, RtsListItem::ORIGIN_AUTO)) {
                        $remaining--;
                    }
                }
            }
        }

        Cache::put($marker, 1, now()->addMinutes(10));
    }

    private function addFollowUps(User $user, int $agencyId, Carbon $day, string $track): void
    {
        $due = $this->followUps->dueOn($user, $day, $track);
        if ($due === []) {
            return;
        }
        $tracked = array_values(array_filter(array_map(fn ($f) => $f->trackedPropertyId, $due)));
        $blocked = array_flip($this->guard->blockedFor($user, $tracked, $track));

        foreach ($due as $f) {
            if ($f->trackedPropertyId !== null && isset($blocked[$f->trackedPropertyId])) {
                continue; // do-not-contact (or taken by someone else) since the follow-up was set
            }
            $this->insert($user, $agencyId, $day, $track, new Candidate(
                trackedPropertyId: $f->trackedPropertyId,
                prospectingListingId: $f->prospectingListingId,
                propertyId: $f->propertyId,
                selection: RtsListItem::SELECTION_FOLLOW_UP,
                reason: $f->reason,
                hasAddress: true,
                inArea: true,
            ), RtsListItem::ORIGIN_FOLLOW_UP);
        }
    }

    /** Items that count toward the target: everything on the list except "added" extras. */
    private function countedToward(User $user, Carbon $day, string $track): int
    {
        return RtsListItem::query()->forDay($user, $day, $track)->countingTowardTarget()->count();
    }

    private function context(User $user, int $agencyId, Carbon $day, string $track, BuilderSettings $settings): SelectionContext
    {
        $items = RtsListItem::query()->forDay($user, $day, $track)->get(['tracked_property_id', 'prospecting_listing_id', 'property_id']);

        return new SelectionContext(
            user: $user,
            agencyId: $agencyId,
            date: $day,
            track: $track,
            settings: $settings,
            guard: $this->guard,
            areaSuburbIds: $this->areas->suburbIdsFor($user, $track),
            areaSuburbNames: $this->areas->suburbNamesNormalisedFor($user, $track),
            areaSuburbNamesLower: $this->areas->suburbNamesLowerTrimFor($user, $track),
            identityIds: $user->dataIdentityIds(),
            excludeTrackedIds: $items->pluck('tracked_property_id')->filter()->map(fn ($v) => (int) $v)->values()->all(),
            excludeListingIds: $items->pluck('prospecting_listing_id')->filter()->map(fn ($v) => (int) $v)->values()->all(),
            excludePropertyIds: $items->pluck('property_id')->filter()->map(fn ($v) => (int) $v)->values()->all(),
        );
    }

    /**
     * Put one property on the day's list. Returns the item, or null when that property is already on
     * the list (one property, once per agent per day — the unique index is the backstop).
     */
    private function insert(User $user, int $agencyId, Carbon $day, string $track, Candidate $c, string $origin, ?int $rolledFrom = null): ?RtsListItem
    {
        if (! $c->trackedPropertyId && ! $c->prospectingListingId && ! $c->propertyId) {
            return null; // nothing identifies the property — never write an unidentifiable row
        }

        $base = RtsListItem::query()->forDay($user, $day, $track);
        $already = (clone $base)->where(function ($q) use ($c) {
            if ($c->trackedPropertyId) {
                $q->orWhere('tracked_property_id', $c->trackedPropertyId);
            }
            if (! $c->trackedPropertyId && $c->prospectingListingId) {
                $q->orWhere('prospecting_listing_id', $c->prospectingListingId);
            }
            if (! $c->trackedPropertyId && ! $c->prospectingListingId && $c->propertyId) {
                $q->orWhere('property_id', $c->propertyId);
            }
        })->exists();
        if ($already) {
            return null;
        }

        $position = (int) (clone $base)->max('position') + 1;
        $attrs = [
            'agency_id' => $agencyId, 'branch_id' => $user->branch_id, 'user_id' => $user->id,
            'list_date' => $day->toDateString(), 'track' => $track,
            'tracked_property_id' => $c->trackedPropertyId, 'prospecting_listing_id' => $c->prospectingListingId,
            'property_id' => $c->propertyId, 'selection' => $c->selection, 'origin' => $origin,
            'position' => $position, 'status' => RtsListItem::STATUS_OPEN, 'reason' => mb_substr($c->reason, 0, 255),
            'rolled_from_item_id' => $rolledFrom,
        ];

        // I vouch for agency_id (it is the agent's own): the auto-stamp must not replace it with the
        // acting user's (a manager building for an agent, or a console run).
        return RtsListItem::withoutAgencyStamping(function () use ($attrs) {
            // The unique index ignores soft-deletes (BUILD_STANDARD §5a): bring a trashed twin back.
            if ($attrs['tracked_property_id']) {
                $trashed = RtsListItem::onlyTrashed()->where([
                    'agency_id' => $attrs['agency_id'], 'track' => $attrs['track'], 'user_id' => $attrs['user_id'],
                    'list_date' => $attrs['list_date'], 'tracked_property_id' => $attrs['tracked_property_id'],
                ])->first();
                if ($trashed) {
                    $trashed->restore();
                    $trashed->forceFill($attrs)->save();

                    return $trashed;
                }
            }

            return RtsListItem::create($attrs);
        });
    }

    // ── add to my day ────────────────────────────────────────────────────

    /**
     * The agent asks for $count more from one selection. Added items are over-and-above: origin
     * "added", not counted toward the target, reported apart.
     *
     * @return Collection<int,RtsListItem> the items actually added (fewer than asked if the selection ran dry)
     * @throws InvalidArgumentException with a plain-English message the page can show
     */
    public function addToDay(User $user, string $selection, int $count, CarbonInterface|string|null $date = null, string $track = RtsListItem::TRACK_SALES): Collection
    {
        if ($count < 1 || $count > self::MAX_ADD) {
            throw new InvalidArgumentException('Choose a number between 1 and ' . self::MAX_ADD . '.');
        }
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            throw new InvalidArgumentException('You need to be working in an agency to add to your day.');
        }
        $settings = ($this->settingsResolver)($user, $track);
        $sel = $this->registry->get($selection);
        if ($sel === null || ! $settings->selectionEnabled($selection)) {
            throw new InvalidArgumentException('That selection is not switched on for your agency.');
        }

        $day = Carbon::parse($date ?? now())->startOfDay();
        $lock = Cache::lock("rts-build:{$agencyId}:{$user->id}:{$track}:{$day->toDateString()}", 120);

        return $lock->block(15, function () use ($user, $agencyId, $day, $track, $settings, $sel, $count) {
            $added = collect();
            foreach ($sel->candidates($this->context($user, $agencyId, $day, $track, $settings), $count) as $candidate) {
                if ($item = $this->insert($user, $agencyId, $day, $track, $candidate, RtsListItem::ORIGIN_ADDED)) {
                    $added->push($item);
                }
            }

            return $added;
        });
    }

    // ── end of day ───────────────────────────────────────────────────────

    /**
     * Close every still-open item of days BEFORE $today for this agent: "missed" (the default), or —
     * when the agency rolls over — "rolled" onto the first working day on or after $today as a new
     * "rollover" item. Only auto items roll, and only once: follow-ups come back by themselves while
     * they are still due, extras the agent asked for are not carried, and an item that was already
     * rolled once and still wasn't called is marked missed rather than carried forever.
     *
     * @return array{missed:int,rolled:int}
     */
    public function closePastDays(User $user, CarbonInterface|string $today, string $track = RtsListItem::TRACK_SALES): array
    {
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        $today = Carbon::parse($today)->startOfDay();
        $result = ['missed' => 0, 'rolled' => 0];
        if ($agencyId <= 0) {
            return $result;
        }

        $settings = ($this->settingsResolver)($user, $track);
        $open = RtsListItem::query()
            ->where('user_id', $user->id)->where('track', $track)
            ->where('status', RtsListItem::STATUS_OPEN)
            ->where('list_date', '<', $today->toDateString())
            ->orderBy('list_date')->orderBy('position')->get();
        if ($open->isEmpty()) {
            return $result;
        }

        $target = null;
        if ($settings->rollsOver) {
            $target = $this->calendar->isWorkday($user, $today, $track)
                ? $today
                : $this->calendar->nextWorkday($user, $today, $track);
        }

        DB::transaction(function () use ($open, $user, $agencyId, $track, $settings, $target, &$result) {
            foreach ($open as $item) {
                $rolls = $settings->rollsOver && $target !== null && $item->origin === RtsListItem::ORIGIN_AUTO;
                if ($rolls) {
                    $this->insert($user, $agencyId, $target, $track, new Candidate(
                        trackedPropertyId: $item->tracked_property_id,
                        prospectingListingId: $item->prospecting_listing_id,
                        propertyId: $item->property_id,
                        selection: $item->selection,
                        reason: 'Carried over from ' . $item->list_date->format('j M') . ': ' . ($item->reason ?? ''),
                        hasAddress: true,
                        inArea: true,
                    ), RtsListItem::ORIGIN_ROLLOVER, $item->id);
                    // Rolled whether or not a copy was needed: if the property is already on that day's
                    // list (a follow-up, say) it is covered there.
                    $item->forceFill(['status' => RtsListItem::STATUS_ROLLED, 'status_changed_at' => now()])->save();
                    $result['rolled']++;
                    continue;
                }
                $item->forceFill(['status' => RtsListItem::STATUS_MISSED, 'status_changed_at' => now()])->save();
                $result['missed']++;
            }
        });

        return $result;
    }

    // ── called by the outcome capture ───────────────────────────────────

    /**
     * An outcome was captured for this property today: mark the agent's open item(s) for it worked.
     * Matches on the canonical tracked property id, else the listing / property id. Returns how many
     * items changed.
     */
    public function markWorked(User $user, ?int $trackedPropertyId, CarbonInterface|string $date, string $track = RtsListItem::TRACK_SALES, ?int $prospectingListingId = null, ?int $propertyId = null): int
    {
        if (! $trackedPropertyId && ! $prospectingListingId && ! $propertyId) {
            return 0;
        }

        return RtsListItem::query()->forDay($user, $date, $track)->open()
            ->where(function ($q) use ($trackedPropertyId, $prospectingListingId, $propertyId) {
                if ($trackedPropertyId) {
                    $q->orWhere('tracked_property_id', $trackedPropertyId);
                }
                if ($prospectingListingId) {
                    $q->orWhere('prospecting_listing_id', $prospectingListingId);
                }
                if ($propertyId) {
                    $q->orWhere('property_id', $propertyId);
                }
            })
            ->update(['status' => RtsListItem::STATUS_WORKED, 'status_changed_at' => now(), 'updated_at' => now()]);
    }

    /** Log helper for the nightly command. */
    public static function logFailure(User $user, \Throwable $e): void
    {
        Log::warning('Road to Sold: list build failed for a user (continuing)', [
            'user_id' => $user->id, 'message' => $e->getMessage(),
        ]);
    }
}
