<?php

namespace App\Services\Rentals;

use App\Models\Agency;
use App\Models\Property;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalPortalSetting;
use App\Models\RentalSecureAccessToken;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * .ai/specs/rental-work-orders.md §14.27.5 / §14.29 — the ONE place that knows
 * "which job cards are this crew's open work", how the crew page groups them
 * (Today / Upcoming / Unscheduled / Recently completed) and what to load for
 * them. The crew page, its tests and any future API all read through here, so
 * there is exactly one query that decides what a crew link may reach.
 *
 * Everything is pinned to ONE agency AND ONE crew and strips global scopes
 * (a public crew link has no staff user for AgencyScope to resolve — which also
 * strips SoftDeletes, so archived rows are excluded explicitly everywhere).
 * Draft and Quoted cards are never booked work (RentalJobCard::
 * CREW_VISIBLE_STATUSES); completed / cancelled / archived cards drop off the
 * moment they change.
 *
 * Output rows are plain arrays — never models — so a Blade view cannot leak
 * anything the crew must not see.
 */
class RentalCrewScheduleService
{
    /** Most rows we ever render in one list (a crew with hundreds of open cards is a data problem, not a page). */
    private const MAX_ROWS = 200;
    private const MAX_RECENT = 25;

    /**
     * THE query: this crew's booked, open job cards in this agency. Not
     * archived, on a property that is not archived, in a crew-visible status.
     */
    public function openCardsQuery(int $agencyId, int $crewId): Builder
    {
        return RentalJobCard::withoutGlobalScopes()
            ->where('rental_job_cards.agency_id', $agencyId)
            ->where('rental_job_cards.rental_crew_id', $crewId)
            ->whereNull('rental_job_cards.deleted_at')
            ->whereIn('rental_job_cards.status', RentalJobCard::CREW_VISIBLE_STATUSES)
            ->whereIn('rental_job_cards.property_id', function ($q) use ($agencyId) {
                $q->select('id')->from('properties')->where('agency_id', $agencyId)->whereNull('deleted_at');
            });
    }

    /** One card, only if it is one of THIS crew's open cards — otherwise null (the caller shows "unavailable"). */
    public function findOpenCard(int $agencyId, int $crewId, int $cardId): ?RentalJobCard
    {
        return $this->openCardsQuery($agencyId, $crewId)->whereKey($cardId)->with('crew')->first();
    }

    /**
     * Everything the crew page renders.
     *
     * @return array{
     *   today: array<int, array>, upcoming: array<int, array>, unscheduled: array<int, array>,
     *   recent: array<int, array>, materials: array<int, array>, show_prices: bool,
     *   upcoming_days: int, recent_days: int, now: Carbon, agency: array
     * }
     */
    public function schedule(int $agencyId, int $crewId, ?CarbonInterface $now = null): array
    {
        $agency = Agency::withoutGlobalScopes()->find($agencyId);
        $tz = $agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
        $now = ($now ? Carbon::instance($now) : Carbon::now())->setTimezone($tz);

        $upcomingDays = RentalPortalSetting::crewPageUpcomingDaysFor($agencyId);
        $recentDays = RentalPortalSetting::crewPageRecentCompletedDaysFor($agencyId);
        $showPrices = RentalPortalSetting::crewLinkShowPricesFor($agencyId);

        $todayStart = $now->copy()->startOfDay();
        $todayEnd = $now->copy()->endOfDay();
        $upcomingEnd = $now->copy()->addDays($upcomingDays)->endOfDay();

        $cards = $this->openCardsQuery($agencyId, $crewId)
            ->orderBy('scheduled_at')->orderBy('id')
            ->limit(self::MAX_ROWS * 3)
            ->get();
        $properties = $this->propertiesFor($cards->pluck('property_id')->all(), $agencyId);

        $today = $upcoming = $unscheduled = [];
        foreach ($cards as $card) {
            $row = $this->row($card, $properties[$card->property_id] ?? null, $tz, $now);
            if (! $card->scheduled_at) {
                $unscheduled[] = $row;
            } elseif ($card->scheduled_at->lte($todayEnd)) {
                // Booked for today — or for an earlier day and still open (overdue). An open booked job
                // is never silently dropped: it sits at the top of Today, flagged.
                $row['overdue'] = $card->scheduled_at->lt($todayStart);
                $today[] = $row;
            } elseif ($card->scheduled_at->lte($upcomingEnd)) {
                $upcoming[] = $row;
            }
            // Booked beyond the upcoming window: not shown until it is inside it.
        }
        usort($unscheduled, fn ($a, $b) => [$a['due_sort'] ?? PHP_INT_MAX, $a['id']] <=> [$b['due_sort'] ?? PHP_INT_MAX, $b['id']]);

        $listedForMaterials = $cards->filter(fn (RentalJobCard $c) => $c->scheduled_at && $c->scheduled_at->lte($upcomingEnd))->values();

        return [
            'today' => array_slice($today, 0, self::MAX_ROWS),
            'upcoming' => array_slice($upcoming, 0, self::MAX_ROWS),
            'unscheduled' => array_slice($unscheduled, 0, self::MAX_ROWS),
            'recent' => $recentDays > 0 ? $this->recentlyCompleted($agencyId, $crewId, $now, $recentDays, $tz) : [],
            'materials' => $this->materials($listedForMaterials, $tz, $showPrices),
            'show_prices' => $showPrices,
            'upcoming_days' => $upcomingDays,
            'recent_days' => $recentDays,
            'now' => $now,
            'agency' => $agency ? Agency::publicBrandingFor($agencyId) : ['name' => 'our agency', 'logoUrl' => null, 'colors' => ['default' => '#0b2a4a', 'button' => '#00b4d8']],
        ];
    }

    /**
     * "What to load": the part lines of the given cards summed by catalogue
     * item + unit (a free-text part with no catalogue item groups by its
     * normalised description + unit). Labour is not stock, so it is excluded.
     * Quantities and units only — prices appear only with `crew_link_show_prices`.
     * There is no stock-on-hand data in rentals, so this never says "short of".
     *
     * @param Collection<int, RentalJobCard> $cards
     * @return array<int, array<string, mixed>>
     */
    public function materials(Collection $cards, string $tz, bool $showPrices): array
    {
        if ($cards->isEmpty()) {
            return [];
        }

        $cardsById = $cards->keyBy('id');
        $lines = RentalJobCardLine::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('rental_job_card_id', $cardsById->keys()->all())
            ->where('type', \App\Models\RentalCatalogueItemType::KIND_PART)
            ->orderBy('id')
            ->get();
        // A line of an archived task is archived with it; a live line under an archived task is never loaded as work.
        $archivedTaskIds = \App\Models\RentalJobCardTask::withoutGlobalScopes()->onlyTrashed()
            ->whereIn('rental_job_card_id', $cardsById->keys()->all())->pluck('id')->all();

        $groups = [];
        foreach ($lines as $line) {
            if ($line->rental_job_card_task_id && in_array($line->rental_job_card_task_id, $archivedTaskIds, true)) {
                continue;
            }
            $unit = $this->norm((string) $line->unit);
            $key = $line->rental_catalogue_item_id
                ? 'item:' . $line->rental_catalogue_item_id . '|' . $unit
                : 'free:' . $this->norm((string) $line->description) . '|' . $unit;
            $card = $cardsById[$line->rental_job_card_id];
            $date = $card->scheduled_at?->copy()->setTimezone($tz);

            $groups[$key] ??= [
                'code' => $line->code ?: null,
                'description' => trim((string) $line->description),
                'unit' => trim((string) $line->unit),
                'quantity' => 0.0,
                'total_value' => 0.0,
                'first_date' => $date,
                'jobs' => [],
            ];
            $groups[$key]['quantity'] += (float) $line->quantity;
            $groups[$key]['total_value'] += (float) $line->line_total;
            if ($date && (! $groups[$key]['first_date'] || $date->lt($groups[$key]['first_date']))) {
                $groups[$key]['first_date'] = $date;
            }
            $groups[$key]['jobs'][$card->id] ??= ['id' => $card->id, 'title' => $card->title, 'date' => $date?->format('D j M'), 'quantity' => 0.0];
            $groups[$key]['jobs'][$card->id]['quantity'] += (float) $line->quantity;
        }

        $fmt = fn (float $q) => rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.');
        $rows = array_map(function (array $g) use ($fmt, $showPrices) {
            return [
                'code' => $g['code'],
                'description' => $g['description'],
                'unit' => $g['unit'],
                'quantity' => $fmt($g['quantity']),
                'quantity_raw' => round($g['quantity'], 2),
                'first_date' => $g['first_date']?->format('D j M'),
                'job_count' => count($g['jobs']),
                'jobs' => array_values(array_map(fn ($j) => ['id' => $j['id'], 'title' => $j['title'], 'date' => $j['date'], 'quantity' => $fmt($j['quantity'])], $g['jobs'])),
                'total_value' => $showPrices ? number_format($g['total_value'], 2) : null,
            ];
        }, array_values($groups));

        usort($rows, fn ($a, $b) => strcasecmp($a['description'], $b['description']));

        return $rows;
    }

    /** Completed cards of this crew inside the window — read-only display, never a link into a card. */
    private function recentlyCompleted(int $agencyId, int $crewId, Carbon $now, int $days, string $tz): array
    {
        $cards = RentalJobCard::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('rental_crew_id', $crewId)
            ->whereNull('deleted_at')
            ->where('status', RentalJobCard::STATUS_COMPLETED)
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $now->copy()->subDays($days)->startOfDay()->setTimezone(config('app.timezone'))->toDateTimeString())
            ->whereIn('property_id', function ($q) use ($agencyId) {
                $q->select('id')->from('properties')->where('agency_id', $agencyId)->whereNull('deleted_at');
            })
            ->orderByDesc('completed_at')
            ->limit(self::MAX_RECENT)
            ->get();
        $properties = $this->propertiesFor($cards->pluck('property_id')->all(), $agencyId);

        return $cards->map(fn (RentalJobCard $c) => [
            'id' => $c->id,
            'title' => $c->title,
            'address' => $this->address($properties[$c->property_id] ?? null),
            'completed_at' => $c->completed_at?->copy()->setTimezone($tz)->format('D j M, H:i'),
        ])->all();
    }

    /**
     * Link status per crew for the Rental Crews list: never issued / live /
     * revoked / expired / unavailable (e.g. the crew is inactive, or crew links
     * are switched off for the agency). One query for the latest token per crew.
     *
     * @param int[] $crewIds
     * @return array<int, array{state: string, label: string, expires_at: ?Carbon, last_used_at: ?Carbon}>
     */
    public function linkStatusesFor(array $crewIds): array
    {
        if (empty($crewIds)) {
            return [];
        }

        $latest = RentalSecureAccessToken::withoutGlobalScopes()
            ->where('purpose', RentalSecureAccessToken::PURPOSE_CREW_STANDING)
            ->whereIn('rental_crew_id', $crewIds)
            ->orderByDesc('id')
            ->get()
            ->unique('rental_crew_id');

        $out = [];
        foreach ($crewIds as $id) {
            $t = $latest->firstWhere('rental_crew_id', $id);
            if (! $t) {
                $out[$id] = ['state' => 'never', 'label' => 'No link', 'expires_at' => null, 'last_used_at' => null];
            } elseif ($t->revoked_at) {
                $out[$id] = ['state' => 'revoked', 'label' => 'Revoked', 'expires_at' => $t->expires_at, 'last_used_at' => $t->last_used_at];
            } elseif ($t->expires_at && $t->expires_at->isPast()) {
                $out[$id] = ['state' => 'expired', 'label' => 'Expired', 'expires_at' => $t->expires_at, 'last_used_at' => $t->last_used_at];
            } elseif ($t->isLive()) {
                $out[$id] = ['state' => 'live', 'label' => 'Live', 'expires_at' => $t->expires_at, 'last_used_at' => $t->last_used_at];
            } else {
                $out[$id] = ['state' => 'unavailable', 'label' => 'Unavailable', 'expires_at' => $t->expires_at, 'last_used_at' => $t->last_used_at];
            }
        }

        return $out;
    }

    /**
     * Everything the Rental Crews edit page's "Crew link" panel shows: the
     * current link's state (issued by / when, expiry or "stands until revoked",
     * last opened, how many times opened) and the newest log rows. The raw
     * link is never available here — only its hash is stored.
     *
     * @return array{state: string, label: string, issued_by: ?string, issued_at: ?Carbon, expires_at: ?Carbon, last_used_at: ?Carbon, open_count: int, events: Collection}
     */
    public function linkPanelFor(\App\Models\RentalCrew $crew, int $eventLimit = 15): array
    {
        $status = $this->linkStatusesFor([$crew->id])[$crew->id];
        $token = RentalSecureAccessToken::withoutGlobalScopes()
            ->where('purpose', RentalSecureAccessToken::PURPOSE_CREW_STANDING)
            ->where('rental_crew_id', $crew->id)
            ->orderByDesc('id')->first();

        return $status + [
            'issued_by' => $token?->createdByUser?->name,
            'issued_at' => $token?->created_at,
            'open_count' => $token
                ? \App\Models\RentalCrewLinkEvent::withoutGlobalScopes()->where('token_id', $token->id)->where('event', \App\Models\RentalCrewLinkEvent::EVENT_OPENED)->count()
                : 0,
            'events' => \App\Models\RentalCrewLinkEvent::withoutGlobalScopes()
                ->where('rental_crew_id', $crew->id)
                ->with(['actor', 'jobCard'])
                ->orderByDesc('id')->limit($eventLimit)->get(),
        ];
    }

    /** @param int[] $ids @return array<int, Property> */
    private function propertiesFor(array $ids, int $agencyId): array
    {
        if (empty($ids)) {
            return [];
        }

        return Property::withoutGlobalScopes()->where('agency_id', $agencyId)->whereIn('id', array_unique($ids))->get()->keyBy('id')->all();
    }

    private function address(?Property $property): string
    {
        return $property?->buildDisplayAddress() ?: '';
    }

    private function row(RentalJobCard $card, ?Property $property, string $tz, Carbon $now): array
    {
        $scheduled = $card->scheduled_at?->copy()->setTimezone($tz);
        $due = $card->due_at?->copy()->setTimezone($tz);

        return [
            'id' => $card->id,
            'title' => $card->title,
            'address' => $this->address($property),
            'access_notes' => $card->access_notes,
            'status' => $card->status,
            'status_label' => ucfirst(str_replace('_', ' ', $card->status)),
            'time' => $scheduled?->format('H:i'),
            'date' => $scheduled?->format('D j M'),
            'due' => $due?->format('D j M'),
            'due_sort' => $due?->getTimestamp(),
            'overdue' => false,
            'crew_completed' => $card->worker_signed_off_at !== null,
        ];
    }

    private function norm(string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $s) ?? ''));
    }
}
