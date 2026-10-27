<?php

namespace App\Services\RoadToSold;

use App\Models\RoadToSold\RtsListItem;
use App\Models\User;
use App\Services\RoadToSold\Adapters\LaneAreaProvider;
use App\Services\RoadToSold\Adapters\LaneWorkCalendar;
use App\Services\RoadToSold\Adapters\LaneFollowUpSource;
use App\Services\RoadToSold\Adapters\LaneSuggestionGuard;
use App\Services\RoadToSold\Contracts\AreaMatchProvider;
use App\Services\RoadToSold\Contracts\AreaProvider;
use App\Services\RoadToSold\Contracts\FollowUpSource;
use App\Services\RoadToSold\Contracts\SuggestionGuard;
use App\Services\RoadToSold\Contracts\WorkCalendar;
use App\Services\RoadToSold\Selections\Candidate;
use App\Services\RoadToSold\Selections\SelectionContext;
use App\Services\RoadToSold\Support\BuilderSettings;
use App\Services\RoadToSold\Support\OtherAgentStock;
use App\Services\RoadToSold\Support\TrackedPropertyResolver;
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
 *   addFound()      the properties a captured task (a drive) just found go straight onto the agent's list (v2 phase 2).
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
    private SelectionRegistry $registry;
    private WorkCalendar $calendar;
    private AreaProvider $areas;
    private SuggestionGuard $guard;
    private FollowUpSource $followUps;
    private Closure $settingsResolver;
    /** The "never another agent's stock" check for the agent being built; set at the start of every operation. */
    private ?OtherAgentStock $stock = null;
    private ?TrackedPropertyResolver $resolver = null;

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

    // ── whose list ───────────────────────────────────────────────────────

    /**
     * THE assistant rule (conductor 13 Oct): an assistant works the list of the agent they act for. Every entry point below turns whoever is
     * asking into the LIST OWNER first - the agent (User::ownershipUserId(), which honours a multi-agent assistant's "Acting for" choice) - so the
     * page's build, the page's read, the nightly build, add-to-day, roll-over and "worked" all use the same user id. A normal user is their own
     * list owner. Spec: road-to-sold.md section "Assistants".
     */
    public function listOwner(User $user): User
    {
        if (! $user->is_assistant || ! $user->isAssistant()) {
            return $user;
        }
        $ownerId = (int) $user->ownershipUserId();

        return $ownerId === (int) $user->id ? $user : (User::withoutGlobalScopes()->find($ownerId) ?? $user);
    }

    /** A track nobody has built (a typo) is an error, not a quiet empty list. */
    private function assertTrack(string $track): void
    {
        if (! in_array($track, RtsListItem::TRACKS, true)) {
            throw new InvalidArgumentException("Unknown Road to Sold track '{$track}'.");
        }
    }

    // ── build ────────────────────────────────────────────────────────────

    /**
     * The agent's list for the day, built (or topped up) if it is short of the target.
     *
     * $limitNew caps the NEW properties this call may add (the nightly build deals stock out in rounds of one so every agent gets a fair share,
     * road-to-sold.md 22.6); null = fill to the day's target.
     *
     * @return Collection<int,RtsListItem> ordered by position; empty on a non-working day, for an agent
     *                                     with no agency, or when nothing is eligible
     */
    public function buildFor(User $user, CarbonInterface|string $date, string $track = RtsListItem::TRACK_SALES, ?int $limitNew = null): Collection
    {
        $this->assertTrack($track);
        $user = $this->listOwner($user);
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        $day = Carbon::parse($date)->startOfDay();
        if ($agencyId <= 0) {
            return collect();   // STANDARDS Rule 17: no agency context, nothing is written
        }

        $lock = Cache::lock("rts-build:{$agencyId}:{$user->id}:{$track}:{$day->toDateString()}", 120);

        return $lock->block(15, function () use ($user, $agencyId, $day, $track, $limitNew) {
            $this->topUp($user, $agencyId, $day, $track, $limitNew);

            return $this->listFor($user, $day, $track);
        });
    }

    /** The stored list for the day, in order (no building). */
    public function listFor(User $user, CarbonInterface|string $date, string $track = RtsListItem::TRACK_SALES): Collection
    {
        $user = $this->listOwner($user);

        return RtsListItem::query()->forDay($user, $date, $track)->orderBy('position')->orderBy('id')->get();
    }

    private function topUp(User $user, int $agencyId, Carbon $day, string $track, ?int $limitNew = null): void
    {
        if (! $this->calendar->isWorkday($user, $day, $track)) {
            return;
        }
        $target = $this->calendar->targetFor($user, $day, $track);
        $settings = ($this->settingsResolver)($user, $track);
        $this->stock = new OtherAgentStock($agencyId, $user->dataIdentityIds());

        // A cheap guard so a page opened ten times does not run the selection queries ten times
        // when the pool is simply short today.
        $marker = "rts-built:{$agencyId}:{$user->id}:{$track}:{$day->toDateString()}";
        $exists = RtsListItem::query()->forDay($user, $day, $track)->exists();
        if ($limitNew === null && $exists && Cache::has($marker)) {
            return;
        }

        // 1. follow-ups due (they count toward the target, and are never trimmed to fit it)
        $this->addFollowUps($user, $agencyId, $day, $track);

        // 1b. what the agent found themselves (a drive, a door knock) and has not worked yet - theirs before any pool is
        $this->addFoundByYou($user, $agencyId, $day, $track);

        // 2. new properties, selection by selection, in the agency's order
        $counted = $this->countedToward($user, $day, $track);
        $remaining = min($target, $limitNew ?? $target) - $counted;
        if ($remaining > 0) {
            foreach ($settings->enabledSelectionKeys as $key) {
                if ($remaining <= 0) {
                    break;
                }
                $selection = $this->registry->get($key);
                if ($selection === null) {
                    // An enabled source with no code behind it is a setup fault: say so, loudly, and carry on with the rest.
                    Log::error('Road to Sold: an enabled selection has no implementation (skipped)', ['selection' => $key, 'user_id' => $user->id]);
                    continue;
                }
                // A candidate another agent has just taken is refused at insert(); ask the same source again (its context now excludes
                // what was refused) up to three times so a race never leaves the day short.
                for ($pass = 0; $pass < 3 && $remaining > 0; $pass++) {
                    $refused = 0;
                    foreach ($selection->candidates($this->context($user, $agencyId, $day, $track, $settings), $remaining) as $candidate) {
                        if ($this->insert($user, $agencyId, $day, $track, $candidate, RtsListItem::ORIGIN_AUTO)) {
                            $remaining--;
                        } else {
                            $refused++;
                        }
                    }
                    if ($refused === 0) {
                        break;
                    }
                }
            }
        }

        if ($limitNew === null || $limitNew >= $target) {
            Cache::put($marker, 1, now()->addMinutes(10));
        }
    }

    /** The pseudo-selection key stored on a row that came from the agent's own captured work. */
    public const SELECTION_FOUND_BY_YOU = 'found_by_you';

    /**
     * Tracked properties the agent found through a captured task and has not yet recorded a result for (oldest find first). Only the
     * agent's own confirmed, un-archived captures count; a property someone else has taken or a do-not-contact is still refused by insert().
     *
     * @return int[]
     */
    public function foundTrackedIds(User $user, int $agencyId, string $track): array
    {
        return DB::table('rts_task_completion_records as r')
            ->join('rts_task_completions as c', 'c.id', '=', 'r.completion_id')
            ->join('tracked_properties as tp', 'tp.id', '=', 'r.record_id')
            ->where('r.agency_id', $agencyId)->where('r.record_type', \App\Models\Prospecting\TrackedProperty::class)
            ->where('c.user_id', $user->id)->where('c.track', $track)->where('c.source', 'captured')
            ->whereNull('c.deleted_at')->whereIn('c.state', ['confirmed', 'overridden'])
            ->whereNull('tp.deleted_at')->whereNotIn('tp.status', ['archived', 'duplicate'])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('rts_contact_outcomes as o')
                ->whereColumn('o.tracked_property_id', 'r.record_id')->where('o.user_id', $user->id)->where('o.track', $track)->whereNull('o.deleted_at'))
            ->orderBy('c.occurred_at')->orderBy('r.id')
            ->pluck('r.record_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    private function addFoundByYou(User $user, int $agencyId, Carbon $day, string $track): void
    {
        foreach ($this->foundTrackedIds($user, $agencyId, $track) as $tid) {
            $this->insert($user, $agencyId, $day, $track, new Candidate($tid, null, null, self::SELECTION_FOUND_BY_YOU, 'Found by you', true, true), RtsListItem::ORIGIN_AUTO);
        }
    }

    /**
     * Put properties a captured task just found on the agent's list for today, over and above the target (origin "added").
     * Anything the gate refuses (another agent's stock, do-not-contact) is simply not placed.
     *
     * @param  int[]  $trackedIds
     * @return int how many were placed
     */
    public function addFound(User $user, array $trackedIds, string $reason, string $track = RtsListItem::TRACK_SALES, CarbonInterface|string|null $date = null): int
    {
        $this->assertTrack($track);
        $user = $this->listOwner($user);
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0 || $trackedIds === []) {
            return 0;
        }
        $day = Carbon::parse($date ?? now())->startOfDay();
        $lock = Cache::lock("rts-build:{$agencyId}:{$user->id}:{$track}:{$day->toDateString()}", 120);

        return $lock->block(15, function () use ($user, $agencyId, $day, $track, $trackedIds, $reason) {
            $this->stock = new OtherAgentStock($agencyId, $user->dataIdentityIds());
            $placed = 0;
            foreach (array_unique(array_map('intval', $trackedIds)) as $tid) {
                if ($this->insert($user, $agencyId, $day, $track, new Candidate($tid, null, null, self::SELECTION_FOUND_BY_YOU, $reason, true, true), RtsListItem::ORIGIN_ADDED)) {
                    $placed++;
                }
            }

            return $placed;
        });
    }

    private function addFollowUps(User $user, int $agencyId, Carbon $day, string $track): void
    {
        $due = $this->followUps->dueOn($user, $day, $track);
        if ($due === []) {
            return;
        }
        $tracked = array_values(array_filter(array_map(fn ($f) => $f->trackedPropertyId, $due)));
        $blocked = array_flip($this->guard->blockedFor($user, $tracked, $track, $day));

        foreach ($due as $f) {
            if ($f->trackedPropertyId !== null && isset($blocked[$f->trackedPropertyId])) {
                continue; // do-not-contact (or taken by someone else) since the follow-up was set
            }
            // insert() also refuses a property that has become another agent's stock since the follow-up was set.
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
        $items = RtsListItem::query()->forDay($user, $day, $track)->get(['tracked_property_id', 'prospecting_listing_id', 'property_id', 'selection']);
        // Areas by suburb id + town when the provider can say so (never by bare name: two towns share names).
        $matches = $this->areas instanceof AreaMatchProvider ? $this->areas->suburbMatchesFor($user, $track) : [];

        return new SelectionContext(
            user: $user,
            agencyId: $agencyId,
            date: $day,
            track: $track,
            settings: $settings,
            guard: $this->guard,
            areaSuburbIds: $this->areas->suburbIdsFor($user, $track),
            areaSuburbNames: $matches === [] ? $this->areas->suburbNamesNormalisedFor($user, $track) : [],
            areaSuburbNamesLower: $matches === [] ? $this->areas->suburbNamesLowerTrimFor($user, $track) : [],
            identityIds: $user->dataIdentityIds(),
            excludeTrackedIds: $items->pluck('tracked_property_id')->filter()->map(fn ($v) => (int) $v)->values()->all(),
            excludeListingIds: $items->pluck('prospecting_listing_id')->filter()->map(fn ($v) => (int) $v)->values()->all(),
            excludePropertyIds: $items->pluck('property_id')->filter()->map(fn ($v) => (int) $v)->values()->all(),
            areaMatches: $matches,
            countsBySelection: $items->countBy('selection')->map(fn ($n) => (int) $n)->all(),
            otherStock: $this->stock,
            resolver: $this->resolver ??= new TrackedPropertyResolver(),
        );
    }

    /**
     * THE never-suggest gate, shared by every path that puts a property on a day (auto, follow-up, rollover,
     * add-to-day). For that agent on that list DAY a property is refused when it is another agent's CoreX
     * stock, or when the suggestion guard blocks it: held by another agent, marked do-not-contact, or parked by
     * the agent's own outcome until a later date. The day is passed in so a follow-up that is due that day is
     * never mistaken for a parked one.
     */
    private function mayPlace(User $user, int $agencyId, Carbon $day, string $track, Candidate $c): bool
    {
        $this->stock ??= new OtherAgentStock($agencyId, $user->dataIdentityIds());
        if ($this->stock->candidateOwnedByAnother($c)) {
            return false;
        }

        return ! $c->trackedPropertyId || $this->guard->blockedFor($user, [(int) $c->trackedPropertyId], $track, $day) === [];
    }

    /** Is that property already on this agent's list for the day? (tracked property first, else listing, else property) */
    private function onDay(User $user, Carbon $day, string $track, Candidate $c): bool
    {
        return RtsListItem::query()->forDay($user, $day, $track)->where(function ($q) use ($c) {
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

        // The last gate on EVERY path (auto, follow-up, rollover, added): never another agent's stock ...
        if (! $this->mayPlace($user, $agencyId, $day, $track, $c)) {
            return null;
        }

        // Every row carries a tracked property, so holds / do-not-contact / outcomes always see it.
        if (! $c->trackedPropertyId) {
            $resolver = $this->resolver ??= new TrackedPropertyResolver();
            $tid = $c->prospectingListingId
                ? $resolver->forListing((int) $c->prospectingListingId, $agencyId, $user->id)
                : $resolver->forProperty((int) $c->propertyId, $agencyId, $user->id);
            if ($tid === null) {
                return null; // could not be given a tracked property: not placed (logged by the resolver)
            }
            $c = new Candidate($tid, $c->prospectingListingId, $c->propertyId, $c->selection, $c->reason, $c->hasAddress, $c->inArea);
            if (! $this->mayPlace($user, $agencyId, $day, $track, $c)) {
                return null;
            }
        }

        $base = RtsListItem::query()->forDay($user, $day, $track);
        if ($this->onDay($user, $day, $track, $c)) {
            return null;
        }

        // The allocation (road-to-sold.md 22.6): a property belongs to ONE agent. If another agent owns it, it is not placed -
        // the caller takes its next candidate. A follow-up on a property worked before prospects existed is adopted at "contacted".
        $prospect = app(ProspectService::class)->place($user, (int) $c->trackedPropertyId, $track, [
            'prospecting_listing_id' => $c->prospectingListingId, 'property_id' => $c->propertyId,
            'selection' => $c->selection, 'reason' => $c->reason, 'reason_facts' => $c->facts,
            'stage' => in_array($origin, [RtsListItem::ORIGIN_FOLLOW_UP, RtsListItem::ORIGIN_ROLLOVER], true) && $c->selection === RtsListItem::SELECTION_FOLLOW_UP
                ? ProspectStages::CONTACTED : ProspectStages::FOUND,
        ]);
        if ($prospect === null) {
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
            'prospect_id' => $prospect->id, 'reason_facts' => $c->facts,
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
        $this->assertTrack($track);
        $user = $this->listOwner($user);
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            throw new InvalidArgumentException('You need to be working in an agency to add to your day.');
        }
        $settings = ($this->settingsResolver)($user, $track);
        if ($count < 1 || $count > $settings->addToDayMax) {
            throw new InvalidArgumentException('Choose a number between 1 and ' . $settings->addToDayMax . '.');
        }
        $sel = $this->registry->get($selection);
        if ($sel === null || ! $settings->selectionEnabled($selection)) {
            throw new InvalidArgumentException('That selection is not switched on for your agency.');
        }

        $day = Carbon::parse($date ?? now())->startOfDay();
        $lock = Cache::lock("rts-build:{$agencyId}:{$user->id}:{$track}:{$day->toDateString()}", 120);

        return $lock->block(15, function () use ($user, $agencyId, $day, $track, $settings, $sel, $count) {
            $this->stock = new OtherAgentStock($agencyId, $user->dataIdentityIds());
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
        $this->assertTrack($track);
        $user = $this->listOwner($user);
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

        $this->stock = new OtherAgentStock($agencyId, $user->dataIdentityIds());

        DB::transaction(function () use ($open, $user, $agencyId, $track, $settings, $target, &$result) {
            foreach ($open as $item) {
                $rolls = $settings->rollsOver && $target !== null && $item->origin === RtsListItem::ORIGIN_AUTO;
                if ($rolls) {
                    $candidate = new Candidate(
                        trackedPropertyId: $item->tracked_property_id,
                        prospectingListingId: $item->prospecting_listing_id,
                        propertyId: $item->property_id,
                        selection: $item->selection,
                        reason: 'Carried over from ' . $item->list_date->format('j M') . ': ' . ($item->reason ?? ''),
                        hasAddress: true,
                        inArea: true,
                    );
                    // Carried only if the next list may have it: not another agent's stock, not held by another
                    // agent, not do-not-contact, not parked by the agent's own outcome past that day. Otherwise it
                    // is closed as missed - the same gate as every other path (mayPlace).
                    $placed = $this->mayPlace($user, $agencyId, $target, $track, $candidate)
                        ? $this->insert($user, $agencyId, $target, $track, $candidate, RtsListItem::ORIGIN_ROLLOVER, $item->id)
                        : null;
                    if ($placed === null && ! $this->onDay($user, $target, $track, $candidate)) {
                        $item->forceFill(['status' => RtsListItem::STATUS_MISSED, 'status_changed_at' => now()])->save();
                        $result['missed']++;
                        continue;
                    }
                    // Rolled: placed, or already on that day's list (a follow-up, say) where it is covered.
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
        $user = $this->listOwner($user);

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
