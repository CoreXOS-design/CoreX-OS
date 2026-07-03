<?php

namespace App\Services\CommandCenter;

use App\Models\AgencyContactSettings;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\CommandCenter\CalendarUserPreference;
use App\Models\CommandCenter\CommandTask;
use App\Models\DealV2\DealV2;
use App\Models\User;
use App\Services\CommandCenter\Calendar\CalendarLayers;
use App\Services\CommandCenter\Calendar\CalendarSourceLinkResolver;
use App\Services\CommandCenter\Calendar\CalendarThresholdResolver;
use App\Services\CommandCenter\Calendar\CalendarVisibilityResolver;
use App\Services\PermissionService;
use Carbon\Carbon;

/**
 * AT-164 Gate 4 — the Calendar Tile Deck.
 *
 * Resolves a per-user Deck of tiles (below the grid) into card payloads that feed
 * the shared <x-tile> component (Gate 3). Four launch tiles plus any of the
 * dashboard's clean tiles a user pins into a slot. Layout is per-user (survives
 * across devices via CalendarUserPreference), with agency/role defaults.
 *
 * Doctrine (§15.8): every tile degrades — a builder that throws yields a quiet
 * "couldn't load" card, never a 500. All limits agency-configurable.
 */
class CalendarTileService
{
    public const TILE_UPCOMING  = 'cal_upcoming';
    public const TILE_DEADLINES = 'cal_deadlines';
    public const TILE_TODOS     = 'cal_todos';
    public const TILE_MY_DEALS  = 'cal_my_deals';

    /** Capability gating the My Deals tile — seeded OFF (DR2 hold, §15.5). */
    public const MY_DEALS_CAPABILITY = 'calendar.tile.my_deals';

    /** Code default Deck (launch tiles, minus the gated My Deals). */
    public const DEFAULT_DECK = [self::TILE_UPCOMING, self::TILE_DEADLINES, self::TILE_TODOS];

    /** RAG rank for "worst wins" colouring. */
    private const RAG_RANK = ['overdue' => 4, 'red' => 3, 'amber' => 2, 'green' => 1, 'neutral' => 0];

    /** Per-request memo of occupies_time by class. */
    private array $occ = [];

    public function __construct(
        private CalendarEventService $events,
        private CalendarThresholdResolver $threshold,
        private CalendarVisibilityResolver $visibility,
        private CommandCentreService $commandCentre,
    ) {}

    // ───────────────────────── Deck resolution ─────────────────────────

    /** Agency-configurable Deck slot count. */
    public function slotCount(User $user): int
    {
        return AgencyContactSettings::forAgency($user->effectiveAgencyId() ?? 1)->calendarDeckSlots();
    }

    /**
     * The ordered tile-id list for this user's Deck: user pref → agency role default
     * → code default. Unknown / unavailable tiles are dropped; clamped to the slot count.
     *
     * @return string[]
     */
    public function resolveLayout(User $user): array
    {
        $pref = CalendarUserPreference::where('user_id', $user->id)->first();
        $layout = is_array($pref?->calendar_deck_layout) ? $pref->calendar_deck_layout : null;

        if ($layout === null) {
            $agency = AgencyContactSettings::forAgency($user->effectiveAgencyId() ?? 1);
            $roleDefaults = $agency->calendarDefaultDeckLayouts();
            $role = $user->effectiveRole();
            $layout = $roleDefaults[$role] ?? self::DEFAULT_DECK;
        }

        $available = array_column($this->catalog($user), 'tile_id');
        $layout = array_values(array_filter(
            array_map('strval', (array) $layout),
            fn ($id) => in_array($id, $available, true)
        ));
        // De-dupe while preserving order.
        $layout = array_values(array_unique($layout));

        return array_slice($layout, 0, $this->slotCount($user));
    }

    /**
     * Persist a user's Deck layout. Only known/available tile-ids are stored;
     * clamped to slots. Returns the stored layout.
     *
     * @param  string[]  $tileIds
     * @return string[]
     */
    public function saveLayout(User $user, array $tileIds): array
    {
        $available = array_column($this->catalog($user), 'tile_id');
        $clean = array_values(array_unique(array_values(array_filter(
            array_map('strval', $tileIds),
            fn ($id) => in_array($id, $available, true)
        ))));
        $clean = array_slice($clean, 0, $this->slotCount($user));

        $pref = CalendarUserPreference::firstOrNew(['user_id' => $user->id]);
        $pref->calendar_deck_layout = $clean;
        $pref->save();

        return $clean;
    }

    /** Reset to the role/agency/code default (nulls the per-user override). Returns the resolved default. */
    public function resetLayout(User $user): array
    {
        $pref = CalendarUserPreference::firstOrNew(['user_id' => $user->id]);
        $pref->calendar_deck_layout = null;
        $pref->save();

        return $this->resolveLayout($user);
    }

    /**
     * Every tile a user may pin: launch tiles (My Deals only when permitted) plus the
     * dashboard's clean tiles (repurposable — same component, no data change).
     *
     * @return array<int,array{tile_id:string,title:string,icon:string,launch:bool}>
     */
    public function catalog(User $user): array
    {
        $tiles = [
            ['tile_id' => self::TILE_UPCOMING,  'title' => 'Upcoming Events',        'icon' => 'calendar',      'launch' => true],
            ['tile_id' => self::TILE_DEADLINES, 'title' => 'Notifications & Deadlines', 'icon' => 'bell',       'launch' => true],
            ['tile_id' => self::TILE_TODOS,     'title' => 'To-dos',                 'icon' => 'check-square',  'launch' => true],
        ];
        if ($this->canSeeMyDeals($user)) {
            $tiles[] = ['tile_id' => self::TILE_MY_DEALS, 'title' => 'My Deals', 'icon' => 'briefcase', 'launch' => true];
        }

        // Repurposable dashboard tiles (audit: 22 clean). Reuse the existing builders.
        foreach ($this->dashboardCardMap($user) as $cardId => $card) {
            $tiles[] = [
                'tile_id' => $cardId,
                'title'   => $card['title'] ?? $cardId,
                'icon'    => $card['icon'] ?? 'clock',
                'launch'  => false,
            ];
        }

        return $tiles;
    }

    /**
     * Build the ordered Deck of card payloads for the shared <x-tile> component.
     *
     * @return array<int,array<string,mixed>>
     */
    public function buildDeck(User $user): array
    {
        $cards = [];
        foreach ($this->resolveLayout($user) as $tileId) {
            $card = $this->buildTile($user, $tileId);
            if ($card !== null) {
                $cards[] = $card;
            }
        }
        return $cards;
    }

    /** Dispatch a single tile-id to its builder. Null = not available to this user. */
    public function buildTile(User $user, string $tileId): ?array
    {
        try {
            return match ($tileId) {
                self::TILE_UPCOMING  => $this->upcomingEventsTile($user),
                self::TILE_DEADLINES => $this->deadlinesTile($user),
                self::TILE_TODOS     => $this->todosTile($user),
                self::TILE_MY_DEALS  => $this->myDealsTile($user),
                default              => $this->dashboardCard($user, $tileId),
            };
        } catch (\Throwable $e) {
            report($e);
            // Degrade, never 500 (§15.8 robustness).
            return [
                'card_id'  => $tileId,
                'title'    => 'Tile',
                'icon'     => 'alert-circle',
                'urgency'  => 'low',
                'count'    => 0,
                'items'    => [],
                'degraded' => true,
            ];
        }
    }

    // ───────────────────────── Launch tiles ─────────────────────────

    /** §15.5(1) — the user's next appointments (today first). Appointment species only. */
    private function upcomingEventsTile(User $user): array
    {
        $scope = PermissionService::calendarScope($user);
        $from  = Carbon::today();
        $to    = Carbon::today()->addDays(14)->endOfDay();

        $raw = $this->events->getEventsForRange($user, $from->toDateString(), $to->toDateTimeString(), [], $scope);
        $visible = collect($this->visibility->filterVisible($raw, $user));

        $agencyId = $user->effectiveAgencyId();
        // Gate 6 — Upcoming Events is the Appointments layer; hidden when it is toggled off.
        $appointmentsOn = in_array('appointments', CalendarLayers::resolveActive($user), true);
        $items = ($appointmentsOn ? $visible : collect())
            ->filter(fn ($e) => $this->isAppointment($e, $agencyId))
            ->map(function ($e) {
                $e->resolved_colour = $this->threshold->resolveForEvent($e);
                return $e;
            })
            ->filter(fn ($e) => $e->resolved_colour !== null && $e->event_date)
            ->sortBy('event_date')
            ->take(12)
            ->map(fn ($e) => [
                'id'    => $e->id,
                'title' => (string) $e->title,
                'rag'   => $e->resolved_colour,
                'due'   => $e->event_date->isToday()
                            ? 'Today ' . $e->event_date->format('H:i')
                            : $e->event_date->format('D d M, H:i'),
                'url'   => route('command-center.calendar', ['view' => 'day', 'date' => $e->event_date->toDateString()]),
            ])
            ->values()
            ->all();

        return [
            'card_id'      => self::TILE_UPCOMING,
            'title'        => 'Upcoming Events',
            'icon'         => 'calendar',
            'urgency'      => 'medium',
            'count'        => count($items),
            'items'        => $items,
            'view_all_url' => route('command-center.calendar'),
            'empty_text'   => 'No upcoming appointments',
        ];
    }

    /** §15.5(2) — RAG-ranked deadline groups as a flat, urgency-ranked list. */
    private function deadlinesTile(User $user): array
    {
        $scope = PermissionService::calendarScope($user);
        $from  = Carbon::today()->subDays(7); // include recently-overdue
        $to    = Carbon::today()->addDays(30)->endOfDay();

        $raw = $this->events->getEventsForRange($user, $from->toDateString(), $to->toDateTimeString(), [], $scope);
        $visible = collect($this->visibility->filterVisible($raw, $user));

        $agencyId = $user->effectiveAgencyId();
        // Gate 6 — the Notifications tile respects the user's active layer toggles.
        $active = CalendarLayers::resolveActive($user);
        $items = $visible
            ->filter(fn ($e) => ! $this->isAppointment($e, $agencyId)) // deadline species
            ->filter(fn ($e) => in_array(CalendarLayers::layerFor($e, false), $active, true))
            ->map(function ($e) {
                $e->resolved_colour = $this->threshold->resolveForEvent($e);
                return $e;
            })
            ->filter(fn ($e) => $e->resolved_colour !== null)
            ->sort(function ($a, $b) {
                $ra = self::RAG_RANK[$a->resolved_colour] ?? 0;
                $rb = self::RAG_RANK[$b->resolved_colour] ?? 0;
                if ($ra !== $rb) return $rb <=> $ra;            // worst first
                return ($a->event_date <=> $b->event_date);      // then soonest
            })
            ->take(25)
            ->map(fn ($e) => [
                'id'    => $e->id,
                'title' => (string) $e->title,
                'rag'   => $e->resolved_colour,
                'due'   => $e->event_date ? $e->event_date->format('d M') : null,
                'url'   => CalendarSourceLinkResolver::resolve($e)['url'] ?? null,
            ])
            ->values()
            ->all();

        $worst = 'neutral';
        foreach ($items as $it) {
            if ((self::RAG_RANK[$it['rag']] ?? 0) > (self::RAG_RANK[$worst] ?? 0)) {
                $worst = $it['rag'];
            }
        }

        return [
            'card_id'      => self::TILE_DEADLINES,
            'title'        => 'Notifications & Deadlines',
            'icon'         => 'bell',
            'rag'          => $worst,               // RAG-accent variant (delta 2)
            'urgency'      => 'high',
            'count'        => count($items),
            'items'        => $items,
            'view_all_url' => route('command-center.calendar'),
            'empty_text'   => 'Nothing due — all clear',
        ];
    }

    /** §15.5(3) — the user's open tasks due this week (CommandTask module). */
    private function todosTile(User $user): array
    {
        $scope = PermissionService::taskScope($user);
        $tasks = CommandTask::query()
            ->visibleTo($user, $scope)
            ->open()
            ->thisWeek()
            ->orderBy('due_date')
            ->limit(25)
            ->get();

        $items = $tasks->map(function (CommandTask $t) {
            $overdue = $t->due_date && $t->due_date->isPast() && ! $t->due_date->isToday();
            $today   = $t->due_date && $t->due_date->isToday();
            return [
                'id'    => $t->id,
                'title' => (string) $t->title,
                'rag'   => $overdue ? 'red' : ($today ? 'amber' : ($t->priority === 'critical' ? 'red' : 'neutral')),
                'due'   => $t->due_date ? $t->due_date->format('D d M') : null,
            ];
        })->values()->all();

        $hasOverdue = collect($items)->contains(fn ($i) => $i['rag'] === 'red');

        return [
            'card_id'      => self::TILE_TODOS,
            'title'        => 'To-dos',
            'icon'         => 'check-square',
            'urgency'      => $hasOverdue ? 'high' : 'medium',
            'count'        => count($items),
            'items'        => $items,
            'view_all_url' => route('command-center.tasks'),
            'empty_text'   => 'No tasks due this week',
        ];
    }

    /**
     * §15.5(4) — DR2 pipeline attention. FLAGGED HIDDEN behind the DR2 hold: returns
     * null unless the user holds the (default-OFF) capability. When DR2 ships and the
     * capability is granted, it lights up with no rebuild.
     */
    private function myDealsTile(User $user): ?array
    {
        if (! $this->canSeeMyDeals($user)) {
            return null;
        }

        $deals = DealV2::query()
            ->visibleTo($user)
            ->whereIn('overall_rag', ['amber', 'red', 'overdue'])
            ->orderByRaw("FIELD(overall_rag,'overdue','red','amber')")
            ->limit(25)
            ->get();

        $items = $deals->map(function (DealV2 $d) {
            $addr = null;
            try { $addr = $d->property?->short_address ?? $d->property?->address; } catch (\Throwable $e) {}
            return [
                'id'    => $d->id,
                'title' => trim(($d->reference ? $d->reference . ' — ' : '') . ($addr ?: 'Deal')),
                'rag'   => $d->overall_rag,
                'due'   => $d->expected_registration?->format('d M'),
                'url'   => $this->safeRoute('deals-v2.show', $d->id),
            ];
        })->values()->all();

        $worst = 'neutral';
        foreach ($items as $it) {
            if ((self::RAG_RANK[$it['rag']] ?? 0) > (self::RAG_RANK[$worst] ?? 0)) {
                $worst = $it['rag'];
            }
        }

        return [
            'card_id'      => self::TILE_MY_DEALS,
            'title'        => 'My Deals',
            'icon'         => 'briefcase',
            'rag'          => $worst,
            'urgency'      => 'high',
            'count'        => count($items),
            'items'        => $items,
            'view_all_url' => $this->safeRoute('deals-v2.index') ?? route('command-center.calendar'),
            'empty_text'   => 'No deals need attention',
        ];
    }

    // ───────────────────────── Helpers ─────────────────────────

    /** Capability gate for the My Deals tile (default OFF — DR2 hold). */
    public function canSeeMyDeals(User $user): bool
    {
        return PermissionService::userHasPermission($user, self::MY_DEALS_CAPABILITY);
    }

    /** Repurposed dashboard card (by card_id), reshaped to the tile contract. */
    private function dashboardCard(User $user, string $cardId): ?array
    {
        return $this->dashboardCardMap($user)[$cardId] ?? null;
    }

    /** card_id → card map from the existing CommandCentreService (memoised per request). */
    private array $dashCache = [];
    private function dashboardCardMap(User $user): array
    {
        if (isset($this->dashCache[$user->id])) {
            return $this->dashCache[$user->id];
        }
        $map = [];
        try {
            foreach ($this->commandCentre->assembleForUser($user) as $card) {
                if (isset($card['card_id'])) {
                    $map[$card['card_id']] = $card;
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
        return $this->dashCache[$user->id] = $map;
    }

    /** Appointment vs deadline species — occupies_time is authoritative (§5). */
    private function isAppointment($event, ?int $agencyId): bool
    {
        $class = (string) $event->category;
        if (! array_key_exists($class, $this->occ)) {
            $cfg = CalendarEventClassSetting::forAgencyAndClass($agencyId, $class);
            $this->occ[$class] = $cfg ? (bool) $cfg->occupies_time : true; // unknown → appointment
        }
        return $this->occ[$class] === true;
    }

    private function safeRoute(string $name, mixed $param = null): ?string
    {
        try {
            return $param === null ? route($name) : route($name, $param);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
