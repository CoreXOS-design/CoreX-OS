<?php

declare(strict_types=1);

namespace App\Services\RoadToSold;

use App\Models\Property;
use App\Models\RoadToSold\RtsListItem;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Road to Sold v4 - the belt tiles and the lists behind them (.ai/specs/road-to-sold.md 23.13).
 *
 * One tile per station in `config/road-to-sold-belt.php` (only `built` ones). Each tile's count comes from TODAY's data - the station's own source
 * (open claims, owner on the property, presentations without an answer, the agent's draft / active / live stock) - and switches to the prospect
 * stage count the moment Lane A1 makes the station's stage key a real stage (`ProspectStages::has`), so A1 plugs in without rework.
 *
 * Scoping: the caller (RoadToSoldController) has already limited $agent to the viewer's breadth; every query here is bound to the agent AND
 * the agent's agency, never to a figure for anyone else. Read only.
 */
final class BeltService
{
    public const PER_PAGE = 25;

    public function __construct(private readonly StageCountService $stages, private readonly WorkdayService $workdays)
    {
    }

    /** @return list<array<string,mixed>> the stations to draw, in belt order */
    public static function stations(): array
    {
        return array_values(array_filter((array) config('road-to-sold-belt.stations', []), fn ($s) => ! empty($s['built'])));
    }

    /** @return array<string,mixed>|null */
    public static function station(string $key): ?array
    {
        foreach (self::stations() as $s) {
            if ($s['key'] === $key) {
                return $s;
            }
        }

        return null;
    }

    /**
     * @return list<array{key:string,label:string,short:string,figure:string,count:int,target:?int,alert:int,note:?string,url:array,stage:?string}>
     *         `url` is the route parameters for road-to-sold.index
     */
    public function tiles(User $viewer, User $agent, string $track, CarbonInterface $date, array $routeBase = []): array
    {
        if ($track !== RtsListItem::TRACK_SALES) {
            return [];   // Road to Rented has no belt yet (23)
        }
        $day = Carbon::instance($date)->startOfDay();
        $byStage = collect($this->stages->forStages($viewer, (int) $agent->id, $day->copy()->startOfMonth(), $day, $track))->keyBy('stage');
        $out = [];
        foreach (self::stations() as $s) {
            $stageKey = (string) ($s['stages'][0] ?? '');
            $useStage = ! empty($s['prefer_stage']) && $stageKey !== '' && ProspectStages::has($stageKey);
            $source = $useStage || $s['source'] === 'stage' ? 'stage' : $s['source'];

            $count = 0;
            $alert = 0;
            $target = null;
            $note = null;
            $url = ['block' => 'station', 'station' => $s['key']];
            switch ($source) {
                case 'stage':
                    foreach ($s['stages'] as $k) {
                        $count += (int) ($byStage[$k]['now'] ?? 0);
                        $alert += (int) ($byStage[$k]['behind'] ?? 0);
                    }
                    $url = ['block' => 'prospects', 'p_stage' => $stageKey];
                    break;
                case 'list_today':
                    $count = (clone $this->listItems($agent, $day, $track))->where('rts_list_items.origin', '!=', RtsListItem::ORIGIN_FOLLOW_UP)->count();
                    $target = $this->workdays->targetFor($agent, $day, $track);
                    $url = ['block' => 'intake'];
                    break;
                default:
                    [$count, $alert] = $this->countFor($source, $agent);
                    break;
            }
            if ($s['key'] === 'held') {
                $owed = $this->owedCount($agent);
                $note = $owed > 0 ? $owed . ' owed' : null;
                $alert += $owed;
                if ($owed > 0) {
                    $url = ['block' => 'station', 'station' => 'held'];   // the owed list first: "have you given feedback?"
                }
            }
            $out[] = [
                'key' => $s['key'], 'label' => (string) $s['label'], 'short' => (string) $s['short'],
                'figure' => $target !== null ? $count . ' / ' . $target : (string) $count,
                'count' => $count, 'target' => $target, 'alert' => $alert, 'note' => $note,
                'url' => array_filter($routeBase + $url, fn ($v) => $v !== null && $v !== ''),
                'stage' => $stageKey !== '' && $source === 'stage' ? $stageKey : null,
            ];
        }

        return $out;
    }

    /**
     * The list behind a tile (stations that have no prospect stage yet). Search (address / title), sort (oldest first by default), 25 a page.
     *
     * @param  array{q?:string,sort?:string,dir?:string,page?:int}  $f
     * @return array{rows:LengthAwarePaginator,label:string,heading:string,empty:string,owed:bool}
     */
    public function list(User $agent, string $stationKey, array $f): array
    {
        $s = self::station($stationKey);
        abort_if($s === null, 404);
        $source = $s['source'];
        $stageKey = (string) ($s['stages'][0] ?? '');
        if ($stationKey === 'held') {
            $source = 'owed';
        } elseif (! empty($s['prefer_stage']) && ProspectStages::has($stageKey) || $source === 'stage') {
            $source = 'prospects';
        }
        [$q, $titleExpr, $heading, $empty] = $this->query($source, $agent, (string) $s['label']);
        if ($source === 'prospects') {
            $q->whereIn('rp.stage', $s['stages']);   // a station read from the stage count lists only the properties AT that stage
        }

        $term = trim((string) ($f['q'] ?? ''));
        if ($term !== '') {
            $q->whereRaw("({$titleExpr}) LIKE ?", ['%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%']);
        }
        $dir = ($f['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        ($f['sort'] ?? 'age') === 'address' ? $q->orderByRaw("({$titleExpr}) {$dir}") : $q->orderBy('since', $dir === 'asc' ? 'asc' : 'desc');
        $page = max(1, (int) ($f['page'] ?? 1));
        $paginator = $q->paginate(self::PER_PAGE, ['*'], 'page', $page);
        $paginator->getCollection()->transform(fn ($r) => $this->decorate((array) $r, $source));

        return ['rows' => $paginator, 'label' => (string) $s['label'], 'heading' => $heading, 'empty' => $empty, 'owed' => $source === 'owed'];
    }

    // ── counts (today's data) ─────────────────────────────────────────────────────────────────────────────────

    /** @return array{0:int,1:int} count, red */
    private function countFor(string $source, User $agent): array
    {
        [$q] = $this->query($source, $agent, '');
        // the alias `since` is not usable in a WHERE: count over the query as a sub-select
        $sub = fn () => DB::query()->fromSub(clone $q, 't');
        $total = $sub()->count();
        $cut = $this->alertCut($source, $agent);
        $red = $cut === null ? 0 : $sub()->where('since', '<', $cut)->count();

        return [(int) $total, (int) $red];
    }

    private function alertCut(string $source, User $agent): ?string
    {
        return match ($source) {
            'claims_open', 'claims_owner' => Carbon::now()->subDays((int) config('road-to-sold-belt.claim_alert_days', 7))->toDateTimeString(),
            'presentations_open' => Carbon::now()->subDays((int) config('road-to-sold-belt.presentation_alert_days', 30))->toDateTimeString(),
            'properties_draft' => Carbon::now()->subDays(SettingsService::forUser($agent)->prospectStallDays())->toDateTimeString(),
            default => null,
        };
    }

    private function owedCount(User $agent): int
    {
        return (int) $this->owedQuery($agent)->count();
    }

    // ── the queries: one builder per source, columns id, title, suburb, owner, since (+ extras) ───────────────

    /**
     * @return array{0:Builder,1:string,2:string,3:string} builder, the SQL expression of the title (for search / sort), heading, empty text
     */
    private function query(string $source, User $agent, string $label): array
    {
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);
        $addr = "TRIM(COALESCE(NULLIF(l.address, ''), CONCAT_WS(' ', tp.street_number, tp.street_name), tp.address_raw, ''))";

        switch ($source) {
            case 'claims_open':
            case 'claims_owner':
                $q = DB::table('prospecting_claims as c')
                    ->join('prospecting_listings as l', 'l.id', '=', 'c.prospecting_listing_id')
                    ->leftJoin('tracked_properties as tp', 'tp.id', '=', 'l.tracked_property_id')
                    ->leftJoin('contacts as o', 'o.id', '=', 'tp.owner_contact_id')
                    ->where('c.agency_id', $agencyId)->where('c.user_id', $agent->id)->where('c.is_active', 1)->whereNull('c.deleted_at')
                    ->selectRaw("c.id as id, {$addr} as title, COALESCE(l.suburb, tp.suburb) as suburb, TRIM(CONCAT_WS(' ', o.first_name, o.last_name)) as owner, COALESCE(c.last_updated_at, c.claimed_at) as since, l.portal_ref as ref, c.pitched_at as pitched_at");
                if ($source === 'claims_open') {
                    $q->whereNull('tp.owner_contact_id');

                    return [$q, $addr, 'Claimed - owner not found yet', 'No open claims without an owner. Claim a property in Market Intelligence and it appears here.'];
                }
                $q->whereNotNull('tp.owner_contact_id')
                    ->whereNotExists(fn ($e) => $e->from('rts_contact_outcomes as oc')->whereColumn('oc.tracked_property_id', 'l.tracked_property_id')->where('oc.user_id', $agent->id)->whereNull('oc.deleted_at'));

                return [$q, $addr, 'Owner found - not contacted yet', 'No claimed property with an owner waiting for a first contact.'];

            case 'presentations_open':
                $title = "TRIM(COALESCE(NULLIF(p.property_address, ''), NULLIF(p.title, ''), 'Presentation'))";
                $q = DB::table('presentations as p')
                    ->where('p.agency_id', $agencyId)->where('p.created_by_user_id', $agent->id)->whereNull('p.deleted_at')
                    ->whereNotExists(fn ($e) => $e->from('presentation_outcomes as po')->whereColumn('po.presentation_id', 'p.id'))
                    ->selectRaw("p.id as id, {$title} as title, p.suburb as suburb, NULL as owner, p.created_at as since");

                return [$q, $title, 'Presentations with no answer yet', 'No presentation is waiting for an answer.'];

            case 'owed':
                $title = "TRIM(COALESCE(NULLIF(e.title, ''), 'Evaluation'))";

                return [$this->owedQuery($agent)->selectRaw("e.id as id, {$title} as title, NULL as suburb, NULL as owner, e.event_date as since, e.property_id as property_id"), $title,
                    'Evaluations that took place - have you given feedback?', 'Nothing is waiting for feedback. Well done.'];

            case 'properties_draft':
            case 'properties_active':
            case 'properties_live':
                $title = "TRIM(COALESCE(NULLIF(pr.address, ''), CONCAT_WS(' ', pr.street_number, pr.street_name)))";
                $q = DB::table('properties as pr')->where('pr.agency_id', $agencyId)->where('pr.agent_id', $agent->id)->whereNull('pr.deleted_at')
                    ->selectRaw("pr.id as id, {$title} as title, pr.suburb as suburb, NULL as owner, pr.updated_at as since, pr.price as price");
                $live = Property::query()->withoutGlobalScopes()->liveOnAnyPortal()->where('agency_id', $agencyId)->where('agent_id', $agent->id)->select('id');
                if ($source === 'properties_draft') {
                    $q->where('pr.status', 'draft');

                    return [$q, $title, 'Draft - getting it ready', 'None of your properties is in Draft.'];
                }
                $q->whereIn('pr.status', ['active', 'for_sale']);
                if ($source === 'properties_active') {
                    $q->whereNotIn('pr.id', $live->toBase());

                    return [$q, $title, 'Active - not on a portal yet', 'Nothing active is waiting to go on a portal.'];
                }
                $q->whereIn('pr.id', $live->toBase());

                return [$q, $title, 'Advertised - live on a portal', 'None of your properties is live on a portal yet.'];

            case 'prospects':
            default:
                $q = DB::table('rts_prospects as rp')->join('tracked_properties as tp', 'tp.id', '=', 'rp.tracked_property_id')
                    ->where('rp.agency_id', $agencyId)->where('rp.user_id', $agent->id)->whereNull('rp.deleted_at')->whereNull('rp.closed_at')->whereNull('rp.released_at')->whereNull('rp.parked_at')
                    ->selectRaw("rp.id as id, TRIM(COALESCE(NULLIF(tp.address_raw, ''), CONCAT_WS(' ', tp.street_number, tp.street_name))) as title, tp.suburb as suburb, NULL as owner, rp.stage_entered_at as since");

                return [$q, "TRIM(COALESCE(NULLIF(tp.address_raw, ''), CONCAT_WS(' ', tp.street_number, tp.street_name)))", $label, 'Nothing at this station.'];
        }
    }

    /** Evaluations whose time has passed with no feedback captured (23.10 "Owed"). */
    private function owedQuery(User $agent): Builder
    {
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);

        return DB::table('calendar_events as e')
            ->where('e.agency_id', $agencyId)->where('e.user_id', $agent->id)->whereNull('e.deleted_at')
            ->whereIn('e.category', ['property_evaluation', 'listing_presentation'])
            ->whereNotIn('e.status', ['dismissed', 'cancelled'])
            ->where('e.event_date', '<', Carbon::now()->toDateTimeString())
            ->whereNotExists(fn ($f) => $f->from('calendar_event_feedback as cf')->whereColumn('cf.calendar_event_id', 'e.id')->whereNotNull('cf.captured_at')->whereNull('cf.deleted_at'));
    }

    private function listItems(User $agent, Carbon $day, string $track)
    {
        return RtsListItem::query()->withoutGlobalScopes()->where('rts_list_items.agency_id', (int) ($agent->effectiveAgencyId() ?: 0))
            ->where('rts_list_items.user_id', $agent->id)->where('rts_list_items.track', $track)
            ->whereDate('rts_list_items.list_date', $day->toDateString())->whereNull('rts_list_items.deleted_at');
    }

    /** @param array<string,mixed> $r */
    private function decorate(array $r, string $source): array
    {
        $since = ! empty($r['since']) ? Carbon::parse($r['since']) : null;
        $r['since_label'] = $since?->format('j M Y');
        $r['days'] = $since ? (int) $since->diffInDays(now()) : null;
        $r['url'] = match ($source) {
            'claims_open', 'claims_owner' => route('market-intelligence.work', ['search' => $r['ref'] ?? '']),
            'presentations_open' => route('presentations.show', $r['id']),
            'owed' => route('command-center.calendar', ['capture_feedback' => $r['id']]),
            'properties_draft', 'properties_active', 'properties_live' => route('corex.properties.show', $r['id']),
            default => route('road-to-sold.index', ['block' => 'prospects']),
        };
        $r['title'] = $r['title'] !== null && trim((string) $r['title']) !== '' ? $r['title'] : 'Address not captured';

        return $r;
    }
}
