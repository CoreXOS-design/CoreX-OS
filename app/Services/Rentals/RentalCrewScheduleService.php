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
 * "which job cards are this crew's open work", how the crew home page groups
 * them (Today / Scheduled / Waiting for you / In progress / Done this month —
 * spec §17.42) and what to load for them. The crew page, its tests and any future API all read through here, so
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
    private const MAX_DONE = 50;

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
            // §17.5.4 — booked work (§17.12 crew-visible statuses) PLUS any Draft/Quoted card the office has asked this crew
            // to price: that card is reachable (and listed under "To price") while its price request is open, and not otherwise.
            ->where(function (Builder $q) use ($agencyId) {
                $q->whereIn('rental_job_cards.status', RentalJobCard::CREW_VISIBLE_STATUSES)
                    ->orWhere(function (Builder $q2) use ($agencyId) {
                        $q2->whereIn('rental_job_cards.status', [RentalJobCard::STATUS_DRAFT, RentalJobCard::STATUS_QUOTED])
                            ->whereIn('rental_job_cards.id', $this->openPriceRequestCardIds($agencyId));
                    });
            })
            ->whereIn('rental_job_cards.property_id', function ($q) use ($agencyId) {
                $q->select('id')->from('properties')->where('agency_id', $agencyId)->whereNull('deleted_at');
            });
    }

    /** §17.5.4 — ids of this agency's cards that have a price request still open for the crew to answer. */
    private function openPriceRequestCardIds(int $agencyId): \Closure
    {
        return function ($q) use ($agencyId) {
            $q->select('rental_job_card_id')->from('rental_job_card_price_requests')
                ->where('agency_id', $agencyId)->where('status', \App\Models\RentalJobCardPriceRequest::STATUS_OPEN);
        };
    }

    /** One card, only if it is one of THIS crew's open cards — otherwise null (the caller shows "unavailable"). */
    public function findOpenCard(int $agencyId, int $crewId, int $cardId): ?RentalJobCard
    {
        return $this->openCardsQuery($agencyId, $crewId)->whereKey($cardId)->with('crew')->first();
    }

    /**
     * Everything the crew home page renders. Every open card lands in exactly ONE group (§17.42):
     *   in_progress — the crew has started it;
     *   waiting     — the ball is in the crew's court: the office asked for a price, the tenant sent the work back,
     *                 or it is approved/booked but has no date yet;
     *   today       — dated today, or earlier and still open (flagged overdue — an open job is never silently dropped);
     *   upcoming    — dated inside the "Scheduled" window (agency setting);
     * plus done (completed this calendar month, read-only). A card booked beyond the window is not listed yet.
     *
     * @return array{
     *   waiting: array<int, array>, today: array<int, array>, upcoming: array<int, array>, in_progress: array<int, array>,
     *   done: array<int, array>, materials: array<int, array>, show_costs: bool,
     *   upcoming_days: int, now: Carbon, agency: array
     * }
     */
    public function schedule(int $agencyId, int $crewId, ?CarbonInterface $now = null): array
    {
        $agency = Agency::withoutGlobalScopes()->find($agencyId);
        $tz = \App\Support\AppDate::agencyTimezone($agency);
        $now = ($now ? Carbon::instance($now) : Carbon::now())->setTimezone($tz);

        $upcomingDays = RentalPortalSetting::crewPageUpcomingDaysFor($agencyId);
        $showCosts = RentalPortalSetting::crewLinkShowCostsFor($agencyId);
        $showTenant = RentalPortalSetting::crewLinkShowTenantContactFor($agencyId);

        $todayStart = $now->copy()->startOfDay();
        $todayEnd = $now->copy()->endOfDay();
        $upcomingEnd = $now->copy()->addDays($upcomingDays)->endOfDay();

        $cards = $this->openCardsQuery($agencyId, $crewId)
            ->orderBy('scheduled_at')->orderBy('id')
            ->limit(self::MAX_ROWS * 4)
            ->get();
        $properties = $this->propertiesFor($cards->pluck('property_id')->all(), $agencyId);

        $toPriceIds = \App\Models\RentalJobCardPriceRequest::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->where('status', \App\Models\RentalJobCardPriceRequest::STATUS_OPEN)
            ->whereIn('rental_job_card_id', $cards->pluck('id')->all())->pluck('rental_job_card_id')->all();

        $tenantsByLease = [];
        $today = $upcoming = $waiting = $inProgress = [];
        $loadCards = collect();   // the cards whose parts count towards "What to load": Today + Scheduled
        foreach ($cards as $card) {
            $property = $properties[$card->property_id] ?? null;
            $row = $this->row($card, $property, $tz);
            $row['to_price'] = in_array($card->id, $toPriceIds, true);
            if ($showTenant && $card->lease_id) {
                $tenantsByLease[$card->lease_id] ??= JobSiteInfo::tenants((int) $card->lease_id);
            }
            $row['tenants'] = $showTenant && $card->lease_id ? $tenantsByLease[$card->lease_id] : [];

            if ($card->status === RentalJobCard::STATUS_IN_PROGRESS) {
                $inProgress[] = $row;
            } elseif ($card->status === RentalJobCard::STATUS_DISPUTED) {
                $row['why_waiting'] = 'The tenant says it is not fixed yet';
                $waiting[] = $row;
            } elseif ($row['to_price'] || ! in_array($card->status, RentalJobCard::CREW_VISIBLE_STATUSES, true)) {
                // §17.5.4 — a Draft/Quoted card is reachable only while the office's price request is open.
                $row['why_waiting'] = 'The office asked you to price this job';
                $waiting[] = $row;
            } elseif (! $card->scheduled_at) {
                $row['why_waiting'] = $card->status === RentalJobCard::STATUS_APPROVED ? 'Approved — waiting for a date' : 'No date yet';
                $waiting[] = $row;
            } elseif ($card->scheduled_at->lte($todayEnd)) {
                // Booked for today — or for an earlier day and still open (overdue).
                $row['overdue'] = $card->scheduled_at->lt($todayStart);
                $today[] = $row;
                $loadCards->push($card);
            } elseif ($card->scheduled_at->lte($upcomingEnd)) {
                $upcoming[] = $row;
                $loadCards->push($card);
            }
            // Booked beyond the Scheduled window: not shown until it is inside it.
        }
        usort($waiting, fn ($a, $b) => [(int) empty($a['to_price']), $a['due_sort'] ?? PHP_INT_MAX, $a['id']] <=> [(int) empty($b['to_price']), $b['due_sort'] ?? PHP_INT_MAX, $b['id']]);

        return [
            'waiting' => array_slice($waiting, 0, self::MAX_ROWS),
            'today' => array_slice($today, 0, self::MAX_ROWS),
            'upcoming' => array_slice($upcoming, 0, self::MAX_ROWS),
            'in_progress' => array_slice($inProgress, 0, self::MAX_ROWS),
            'done' => $this->doneThisMonth($agencyId, $crewId, $now, $tz),
            'materials' => $this->materials($loadCards->values(), $tz, $showCosts),
            'show_costs' => $showCosts,
            'upcoming_days' => $upcomingDays,
            'now' => $now,
            'agency' => $agency ? Agency::publicBrandingFor($agencyId) : ['name' => 'our agency', 'logoUrl' => null, 'colors' => ['default' => '#0b2a4a', 'button' => '#00b4d8']],
        ];
    }

    /**
     * "What to load": the part lines of the given cards summed by catalogue
     * item + unit (a free-text part with no catalogue item groups by its
     * normalised description + unit). Labour is not stock, so it is excluded.
     * Quantities and units only — COST (never selling, §17.4.7) appears only with `crew_link_show_costs`.
     * There is no stock-on-hand data in rentals, so this never says "short of".
     *
     * @param Collection<int, RentalJobCard> $cards
     * @return array<int, array<string, mixed>>
     */
    public function materials(Collection $cards, string $tz, bool $showCosts): array
    {
        if ($cards->isEmpty()) {
            return [];
        }

        $cardsById = $cards->keyBy('id');
        // §17.4.6 — only ACCEPTED lines are work to load; a crew's draft or a line awaiting the office is not.
        $lines = RentalJobCardLine::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('office_status', RentalJobCardLine::OFFICE_ACCEPTED)
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
                'has_cost' => false,
                'first_date' => $date,
                'jobs' => [],
            ];
            $groups[$key]['quantity'] += (float) $line->quantity;
            // COST column only (§17.4.7); a line with no cost recorded adds nothing and never shows a 0.00.
            if ($line->cost_total !== null) {
                $groups[$key]['total_value'] += (float) $line->cost_total;
                $groups[$key]['has_cost'] = true;
            }
            if ($date && (! $groups[$key]['first_date'] || $date->lt($groups[$key]['first_date']))) {
                $groups[$key]['first_date'] = $date;
            }
            $groups[$key]['jobs'][$card->id] ??= ['id' => $card->id, 'title' => $card->title, 'date' => $date?->format('D j M'), 'quantity' => 0.0];
            $groups[$key]['jobs'][$card->id]['quantity'] += (float) $line->quantity;
        }

        $fmt = fn (float $q) => rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.');
        $rows = array_map(function (array $g) use ($fmt, $showCosts) {
            return [
                'code' => $g['code'],
                'description' => $g['description'],
                'unit' => $g['unit'],
                'quantity' => $fmt($g['quantity']),
                'quantity_raw' => round($g['quantity'], 2),
                'first_date' => $g['first_date']?->format('D j M'),
                'job_count' => count($g['jobs']),
                'jobs' => array_values(array_map(fn ($j) => ['id' => $j['id'], 'title' => $j['title'], 'date' => $j['date'], 'quantity' => $fmt($j['quantity'])], $g['jobs'])),
                'total_value' => $showCosts && $g['has_cost'] ? number_format($g['total_value'], 2) : null,
            ];
        }, array_values($groups));

        usort($rows, fn ($a, $b) => strcasecmp($a['description'], $b['description']));

        return $rows;
    }

    /** Completed cards of this crew since the 1st of this month (agency time) — read-only display, never a link into a card. */
    private function doneThisMonth(int $agencyId, int $crewId, Carbon $now, string $tz): array
    {
        $cards = RentalJobCard::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('rental_crew_id', $crewId)
            ->whereNull('deleted_at')
            ->where('status', RentalJobCard::STATUS_COMPLETED)
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $now->copy()->startOfMonth()->setTimezone(config('app.timezone'))->toDateTimeString())
            ->whereIn('property_id', function ($q) use ($agencyId) {
                $q->select('id')->from('properties')->where('agency_id', $agencyId)->whereNull('deleted_at');
            })
            ->orderByDesc('completed_at')
            ->limit(self::MAX_DONE)
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
     * Link status per crew for the Rental Crews list and the edit page's panel: never issued / live / revoked /
     * expired / unavailable (e.g. the crew is inactive, or crew links are switched off for the agency), plus the
     * home-link line the office reads — when it was last sent and how many times it was opened. "Sent" and "opened"
     * look at the crew's CURRENT links (every one not revoked), so a Resend keeps the history and a Regenerate
     * starts a clean one.
     *
     * @param int[] $crewIds
     * @return array<int, array{state: string, label: string, expires_at: ?Carbon, last_used_at: ?Carbon, issued_at: ?Carbon, open_count: int, sent_at: ?Carbon, sent_via: ?string}>
     */
    public function linkStatusesFor(array $crewIds): array
    {
        if (empty($crewIds)) {
            return [];
        }

        $tokens = RentalSecureAccessToken::withoutGlobalScopes()
            ->where('purpose', RentalSecureAccessToken::PURPOSE_CREW_STANDING)
            ->whereIn('rental_crew_id', $crewIds)
            ->orderByDesc('id')
            ->get();
        $latest = $tokens->unique('rental_crew_id');
        $currentIds = $tokens->whereNull('revoked_at')->pluck('id')->all();

        // Per crew and event over the current links only — aggregated in SQL (a busy crew can log hundreds of opens).
        $stats = $currentIds
            ? \App\Models\RentalCrewLinkEvent::withoutGlobalScopes()
                ->whereIn('token_id', $currentIds)
                ->whereIn('event', [\App\Models\RentalCrewLinkEvent::EVENT_OPENED, \App\Models\RentalCrewLinkEvent::EVENT_EMAILED, \App\Models\RentalCrewLinkEvent::EVENT_WHATSAPP])
                ->selectRaw('rental_crew_id, event, count(*) as n, max(created_at) as last_at')
                ->groupBy('rental_crew_id', 'event')
                ->get()
                ->groupBy('rental_crew_id')
            : collect();

        $out = [];
        foreach ($crewIds as $id) {
            $t = $latest->firstWhere('rental_crew_id', $id);
            if (! $t) {
                $out[$id] = ['state' => 'never', 'label' => 'No link', 'expires_at' => null, 'last_used_at' => null, 'issued_at' => null, 'open_count' => 0, 'sent_at' => null, 'sent_via' => null];
                continue;
            }
            if ($t->revoked_at) {
                $state = ['revoked', 'Revoked'];
            } elseif ($t->expires_at && $t->expires_at->isPast()) {
                $state = ['expired', 'Expired'];
            } elseif ($t->isLive()) {
                $state = ['live', 'Live'];
            } else {
                $state = ['unavailable', 'Unavailable'];
            }

            $byEvent = ($stats[$id] ?? collect())->keyBy('event');
            $emailedAt = $byEvent[\App\Models\RentalCrewLinkEvent::EVENT_EMAILED]->last_at ?? null;
            $whatsappAt = $byEvent[\App\Models\RentalCrewLinkEvent::EVENT_WHATSAPP]->last_at ?? null;
            $sentAt = $emailedAt && $whatsappAt ? max($emailedAt, $whatsappAt) : ($emailedAt ?: $whatsappAt);

            $out[$id] = [
                'state' => $state[0], 'label' => $state[1],
                'expires_at' => $t->expires_at, 'last_used_at' => $t->last_used_at, 'issued_at' => $t->created_at,
                'open_count' => (int) ($byEvent[\App\Models\RentalCrewLinkEvent::EVENT_OPENED]->n ?? 0),
                'sent_at' => $sentAt ? Carbon::parse($sentAt) : null,
                'sent_via' => $sentAt ? ($sentAt === $emailedAt ? 'email' : 'whatsapp') : null,
            ];
        }

        return $out;
    }

    /**
     * Everything the Rental Crews edit page's "Crew link" panel shows: the
     * current link's state (issued by / when, expiry or "stands until revoked",
     * last opened, how many times opened, when it was last sent) and the newest
     * log rows. The raw link is never available here — only its hash is stored.
     *
     * @return array{state: string, label: string, issued_by: ?string, issued_at: ?Carbon, expires_at: ?Carbon, last_used_at: ?Carbon, open_count: int, sent_at: ?Carbon, sent_via: ?string, events: Collection}
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

    private function row(RentalJobCard $card, ?Property $property, string $tz): array
    {
        $scheduled = $card->scheduled_at?->copy()->setTimezone($tz);
        $due = $card->due_at?->copy()->setTimezone($tz);
        $address = $this->address($property);
        // AT-459 — the access contact + note as the work order holds it (the card's own, then the property's, when the work order has none)
        $access = JobSiteInfo::accessContact($card->workOrder()->withoutGlobalScopes()->first(), $card);

        return [
            'id' => $card->id,
            'title' => $card->title,
            'address' => $address,
            'map_url' => JobSiteInfo::mapUrl($property, $address),
            'access' => $access,
            'access_notes' => $access['note'],
            'tenants' => [],
            'status' => $card->status,
            'status_label' => ucfirst(str_replace('_', ' ', $card->status)),
            'time' => $scheduled?->format('H:i'),
            'date' => $scheduled?->format('D j M'),
            'due' => $due?->format('D j M'),
            'due_sort' => $due?->getTimestamp(),
            'overdue' => false,
            'to_price' => false,
            'why_waiting' => null,
            'crew_completed' => $card->worker_signed_off_at !== null,
        ];
    }

    private function norm(string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $s) ?? ''));
    }
}
