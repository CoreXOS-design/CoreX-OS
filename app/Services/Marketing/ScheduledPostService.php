<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Models\AdSchedulePattern;
use App\Models\AgentSocialAccount;
use App\Models\CompanyAd;
use App\Models\Property;
use App\Models\PropertyMarketingPost;
use App\Models\User;
use App\Services\AdTemplates\CompanyAdLayout;
use App\Services\Compliance\MarketingReadinessService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AT-456 Part D — the rules of the Post Scheduler (spec §D.3): who may schedule what, when, and the
 * state changes a scheduled post can go through while it is still waiting (schedule, edit, cancel,
 * reschedule). Publishing itself is ScheduledPostPublisher.
 *
 * Time: the agent types a local date/time; it is stored in UTC and shown back in the agency timezone.
 * There is no per-agency timezone column (and none is added here), so the timezone is the app's.
 */
final class ScheduledPostService
{
    public const MIN_LEAD_MINUTES = 5;
    public const MAX_LEAD_DAYS    = 60;

    public const PLATFORMS = ['facebook' => 'Facebook', 'instagram' => 'Instagram'];

    /** "Schedule by agent" (§D.9): minutes between one agent's post and the next on the same day. */
    public const MIN_GAP_MINUTES = 5;
    public const MAX_GAP_MINUTES = 240;
    /** Most ads one "Schedule by agent" batch may hold (a full week for a large office). */
    public const MAX_AGENT_BATCH = 150;

    public function __construct(private readonly ScheduledPostImages $images) {}

    // ── time ────────────────────────────────────────────────────────────────

    /** The timezone times are typed and shown in. */
    public function timezone(): string
    {
        return config('app.timezone') ?: 'Africa/Johannesburg';
    }

    /**
     * Local wall-clock date + time (as typed) to a UTC instant.
     *
     * @throws ScheduleException when it is not a real date and time
     */
    public function toUtc(string $date, string $time): Carbon
    {
        $time = strlen($time) === 8 ? substr($time, 0, 5) : $time;
        $typed = trim($date) . ' ' . trim($time);

        try {
            $local = Carbon::createFromFormat('Y-m-d H:i', $typed, $this->timezone());
        } catch (\Throwable) {
            $local = false;
        }

        if ($local === false || $local->format('Y-m-d H:i') !== $typed) {
            throw new ScheduleException('That date or time is not valid.');
        }

        return $local->utc();
    }

    /** @throws ScheduleException */
    public function assertInWindow(Carbon $utc): void
    {
        if ($utc->lt(now()->addMinutes(self::MIN_LEAD_MINUTES))) {
            throw new ScheduleException('Pick a time at least ' . self::MIN_LEAD_MINUTES . ' minutes from now.');
        }
        if ($utc->gt(now()->addDays(self::MAX_LEAD_DAYS))) {
            throw new ScheduleException('Posts can be scheduled up to ' . self::MAX_LEAD_DAYS . ' days ahead.');
        }
    }

    /**
     * The UTC instants for a batch.
     *   mode 'at'    every post at the one date + time
     *   mode 'daily' "one a day at HH:MM": the first on $date, the next the day after, and so on
     *
     * @return list<Carbon>
     * @throws ScheduleException
     */
    public function plan(string $mode, string $date, string $time, int $count): array
    {
        $first = $this->toUtc($date, $time);
        $slots = [];

        for ($i = 0; $i < $count; $i++) {
            // Add days in LOCAL time so "09:00" stays 09:00 every day.
            $slots[] = $mode === 'daily'
                ? $first->copy()->setTimezone($this->timezone())->addDays($i)->utc()
                : $first->copy();
        }

        // The earliest must clear the 5-minute rule and the last the 60-day rule.
        $this->assertInWindow($slots[0]);
        $this->assertInWindow($slots[$count - 1]);

        return $slots;
    }

    /**
     * "Schedule by agent" (§D.9): each agent's 1st ad goes out on the first chosen day, the 2nd on the second day,
     * and so on. Within a day the agents post one after the other in the chosen order, $gap minutes apart, from
     * $time. An agent with fewer ads posts on fewer days (the first ones); an agent with more ads than days is
     * refused, naming the agent. Every day follows the same agent order: $agentOrder as the window showed it
     * (A to Z, or the user's own order), any agent missing from it after them A to Z.
     *
     * @param  array<int, array{0:int, 1:string}>  $agentOf  item index => [agent user id (0 = no agent), agent name], in batch order
     * @param  list<string>  $days  local dates, Y-m-d
     * @param  list<int>  $agentOrder  agent user ids, first to last
     * @return array<int, Carbon>  item index => UTC instant
     * @throws ScheduleException
     */
    public function planByAgent(array $agentOf, array $days, string $time, int $gap, array $agentOrder = []): array
    {
        $days = array_values(array_unique(array_map('strval', $days)));
        sort($days);
        if ($days === []) {
            throw new ScheduleException('Tick at least one day to post on.');
        }
        if ($gap < self::MIN_GAP_MINUTES || $gap > self::MAX_GAP_MINUTES) {
            throw new ScheduleException('The time between agents must be ' . self::MIN_GAP_MINUTES . ' to ' . self::MAX_GAP_MINUTES . ' minutes.');
        }

        $groups = [];
        $names  = [];
        foreach ($agentOf as $i => [$agentId, $name]) {
            $groups[(int) $agentId][] = $i;
            $names[(int) $agentId]    = (string) $name;
        }

        $tooMany = [];
        foreach ($groups as $agentId => $items) {
            if (count($items) > count($days)) {
                $tooMany[] = $names[$agentId] . ' has ' . count($items) . ' ads';
            }
        }
        if ($tooMany !== []) {
            $dayWord = count($days) === 1 ? '1 day is' : count($days) . ' days are';
            throw new ScheduleException(implode(', ', $tooMany) . ', but only ' . $dayWord . ' ticked. Each agent gets one ad per day: remove ads or tick more days.');
        }

        $agents = $this->orderAgents($names, $agentOrder);
        $tz     = $this->timezone();
        $slots  = [];

        foreach ($days as $d => $day) {
            $start    = $this->toUtc($day, $time);
            $position = 0;
            foreach ($agents as $agentId) {
                if (! isset($groups[$agentId][$d])) {
                    continue;
                }
                $at = $start->copy()->addMinutes($position * $gap);
                if ($at->copy()->setTimezone($tz)->format('Y-m-d') !== $day) {
                    throw new ScheduleException('The posts on ' . Carbon::parse($day, $tz)->format('l j F') . ' would run past midnight. Start earlier or make the time between agents shorter.');
                }
                $slots[$groups[$agentId][$d]] = $at;
                $position++;
            }
        }

        // The earliest must clear the 5-minute rule and the last the 60-day rule.
        $ordered = collect($slots)->sortBy(fn (Carbon $c) => $c->getTimestamp())->values();
        $this->assertInWindow($ordered->first());
        $this->assertInWindow($ordered->last());

        ksort($slots);

        return $slots;
    }

    /**
     * Agent ids in posting order: the given ids first (in that order), any agent not in the list after them A to Z
     * by name. Listings with no agent (id 0) always come last.
     *
     * @param  array<int, string>  $names  agent id => name
     * @param  list<int>  $agentOrder
     * @return list<int>
     */
    public function orderAgents(array $names, array $agentOrder = []): array
    {
        $alpha = array_keys($names);
        usort($alpha, function (int $a, int $b) use ($names): int {
            if (($a === 0) !== ($b === 0)) {
                return $a === 0 ? 1 : -1;
            }

            return strnatcasecmp($names[$a], $names[$b]) ?: $a <=> $b;
        });

        $custom = array_values(array_filter(
            array_unique(array_map('intval', $agentOrder)),
            fn (int $id) => $id !== 0 && array_key_exists($id, $names)
        ));

        return array_values(array_merge($custom, array_diff($alpha, $custom)));
    }

    /**
     * The user's last "Schedule by agent" pattern in the agency they are working in, for the Schedule window's
     * defaults; null when they have never used it there.
     *
     * @return array{weekdays:list<int>, time:string, gap:int, order:string, agents:list<int>}|null
     */
    public function savedPattern(User $user): ?array
    {
        $row = AdSchedulePattern::query()
            ->where('agency_id', (int) $user->effectiveAgencyId())
            ->where('user_id', $user->id)
            ->first();

        return $row ? [
            'weekdays' => array_values(array_map('intval', (array) $row->weekdays)),
            'time'     => (string) $row->first_time,
            'gap'      => (int) $row->gap_minutes,
            'order'    => (string) $row->agent_order,
            'agents'   => array_values(array_map('intval', (array) ($row->custom_agent_ids ?? []))),
        ] : null;
    }

    /**
     * Remember the pattern. The agent list is kept only for 'custom' - choosing A to Z keeps the user's own order
     * from before, so switching back to it later brings it back.
     *
     * @param list<string> $days @param list<int> $agentOrder
     */
    private function rememberPattern(User $user, array $days, string $time, int $gap, string $order, array $agentOrder): void
    {
        $agencyId = (int) $user->effectiveAgencyId();
        $row = AdSchedulePattern::withTrashed()
            ->where('agency_id', $agencyId)
            ->where('user_id', $user->id)
            ->first() ?? new AdSchedulePattern();

        $custom = $order === AdSchedulePattern::ORDER_CUSTOM;

        $row->agency_id = $agencyId;
        $row->user_id   = $user->id;
        $row->fill([
            'weekdays'    => collect($days)->map(fn ($d) => Carbon::parse($d)->isoWeekday())->unique()->sort()->values()->all(),
            'first_time'  => substr($time, 0, 5),
            'gap_minutes' => $gap,
            'agent_order' => $custom ? AdSchedulePattern::ORDER_CUSTOM : AdSchedulePattern::ORDER_ALPHA,
        ]);
        if ($custom) {
            $row->custom_agent_ids = array_values(array_unique(array_map('intval', $agentOrder)));
        }
        $row->deleted_at = null;
        $row->save();
    }

    // ── who / what ──────────────────────────────────────────────────────────

    /** @return array<string,bool> platform => has an active connected account */
    public function connections(User $user): array
    {
        $have = AgentSocialAccount::query()->where('user_id', $user->id)->active()->pluck('platform')->all();

        return collect(self::PLATFORMS)->map(fn ($label, $key) => in_array($key, $have, true))->all();
    }

    public function connectHint(): string
    {
        return 'Connect it under My Portal, Tools, Social Media first, then schedule again.';
    }

    /**
     * What a Schedule / Post window needs on first paint (the Ad Manager results step and the Company ads list): the
     * endpoints, the user's own connections and the time rules.
     *
     * @return array<string, mixed>
     */
    public function panelConfig(User $user): array
    {
        $tz = $this->timezone();

        return [
            'imageUrl'    => route('api.v1.ad-manager.scheduled.image', [], false),
            'storeUrl'    => route('api.v1.ad-manager.scheduled.store', [], false),
            'listUrl'     => route('tools.ad-manager.scheduled'),
            'portalUrl'   => route('agent.portal', ['pane' => 'social']) . '#tools',
            'platforms'   => self::PLATFORMS,
            'connected'   => $this->connections($user),
            'connectHint' => $this->connectHint(),
            'tzLabel'     => Carbon::now($tz)->format('T') . ' (' . $tz . ')',
            'minLead'     => self::MIN_LEAD_MINUTES,
            'maxDays'     => self::MAX_LEAD_DAYS,
            'today'       => Carbon::now($tz)->format('Y-m-d'),
        ];
    }

    /**
     * May this user put a scheduled post on this property? Their properties data scope (own / branch /
     * agency, enforced in the query) AND the property must be on the market and marketing-ready —
     * the same rules as posting straight away.
     *
     * @throws ScheduleException
     */
    public function propertyFor(User $user, int $propertyId): Property
    {
        $property = Property::query()->visibleTo($user)->find($propertyId);

        if (! $property) {
            throw new ScheduleException('One of those listings is not yours to post for.');
        }

        $this->assertMarketable($property);

        return $property;
    }

    /** @throws ScheduleException */
    public function assertMarketable(Property $property): void
    {
        $name = $property->title ?: 'This listing';

        if ($property->isOtherAgencyStock() || ! $property->isOnMarket()) {
            throw new ScheduleException("$name is not on the market, so it cannot be advertised.");
        }
        if (! app(MarketingReadinessService::class)->isMarketable($property)) {
            throw new ScheduleException("$name is not marketing-ready yet (see its Compliance Status).");
        }
    }

    /**
     * May this user post this company ad? It must be one of the agency's own (AgencyScope - another agency's id is
     * simply not found), not archived, and the user must be allowed to manage company ads (Company Ads §7, §9).
     *
     * @throws ScheduleException
     */
    /** Instagram feed pictures must be between upright 4:5 and wide 1.91:1 (a Story-size ad is refused by Meta). */
    public static function fitsInstagramFeed(string $canvas): bool
    {
        [$w, $h] = CompanyAdLayout::CANVAS_SIZES[$canvas] ?? [1200, 628];
        $ratio   = $w / max(1, $h);

        return $ratio >= 0.8 && $ratio <= 1.92;   // 1.92: the standard 1200x628 is 1.911
    }

    public function companyAdFor(User $user, int $companyAdId): CompanyAd
    {
        if (! CompanyAd::userMayManage($user)) {
            throw new ScheduleException('You are not allowed to post company ads.');
        }
        // Pinned to the agency the user is working in, also for an owner account the agency scope does not limit.
        $ad = CompanyAd::query()->where('agency_id', (int) $user->effectiveAgencyId())->find($companyAdId);
        if (! $ad) {
            throw new ScheduleException('That company ad is no longer available (it may have been archived).');
        }

        return $ad;
    }

    // ── schedule ────────────────────────────────────────────────────────────

    /**
     * Schedule one post per (ad, platform). All or nothing: if any ad or the time is refused, nothing is saved.
     * An item is about a listing (property_id) or a company ad (company_ad_id) - never both.
     * Mode 'now' (company ads only, Company Ads §7 "Post now"): every post is stored due this minute and the caller
     * publishes it straight away through the same claim + checks as a scheduled post (ScheduledPostPublisher).
     * Mode 'agents' (listing ads only, §D.9 "Schedule by agent"): the times come from planByAgent() using each
     * listing's own agent as stored (never a client value); $pattern carries days, gap, order ('alpha'|'custom',
     * remembered) and agents (the posting order the window showed).
     * The pattern is remembered for the user in the same transaction.
     *
     * @param  list<array{property_id?:int|string, company_ad_id?:int|string, copy:string, image_path:string}>  $items
     * @param  list<string>  $platforms
     * @param  array{days?:list<string>, time?:string, gap?:int, order?:string, agents?:list<int>}  $pattern
     * @return Collection<int,PropertyMarketingPost>
     * @throws ScheduleException
     */
    public function schedule(User $user, array $items, array $platforms, string $mode, string $date, string $time, array $pattern = []): Collection
    {
        $platforms = array_values(array_intersect(array_keys(self::PLATFORMS), $platforms));
        if ($platforms === [] || $items === []) {
            throw new ScheduleException('Choose at least one ad and one platform.');
        }

        $connections = $this->connections($user);
        foreach ($platforms as $p) {
            if (empty($connections[$p])) {
                throw new ScheduleException(
                    self::PLATFORMS[$p] . ' is not connected for you yet. ' . $this->connectHint()
                );
            }
        }

        if ($mode === 'now') {
            foreach ($items as $item) {
                if (empty($item['company_ad_id']) || ! empty($item['property_id'])) {
                    throw new ScheduleException('Only a company ad can be posted straight away from here.');
                }
            }
            $slots = array_fill(0, count($items), now()->utc()->startOfSecond());
        } elseif ($mode === 'agents') {
            foreach ($items as $item) {
                if (! empty($item['company_ad_id']) || empty($item['property_id'])) {
                    throw new ScheduleException('Schedule by agent is for listing ads only.');
                }
            }
            $slots = []; // planned below, once every listing (and so its agent) is known
        } else {
            $slots = $this->plan($mode, $date, $time, count($items));
        }

        // Validate every item first, so a refusal saves nothing.
        $checked = [];
        foreach ($items as $i => $item) {
            $hasProperty = ! empty($item['property_id']);
            $hasAd       = ! empty($item['company_ad_id']);
            if ($hasProperty === $hasAd) {
                throw new ScheduleException('Each post must be for one listing or one company ad.');
            }
            $subject = $hasAd ? $this->companyAdFor($user, (int) $item['company_ad_id']) : $this->propertyFor($user, (int) $item['property_id']);
            if ($hasAd && in_array('instagram', $platforms, true) && ! self::fitsInstagramFeed((string) $subject->canvas)) {
                throw new ScheduleException('"' . $subject->name . '" is Story size, and Instagram only takes square or upright (4:5) pictures in the feed. Post it to Facebook, or make the ad in another size.');
            }
            $copy    = trim((string) ($item['copy'] ?? ''));
            $this->assertCopy($copy, $platforms);

            $path = (string) ($item['image_path'] ?? '');
            if ($path === '' || ! $this->images->belongsTo($user, $path)) {
                $name = $hasAd ? $subject->name : ($subject->title ?: 'one ad');
                throw new ScheduleException('The picture for "' . $name . '" was not uploaded. Please try again.');
            }
            $checked[$i] = [$subject, $copy, $path];
        }

        if ($mode === 'agents') {
            $agentOf = [];
            foreach ($checked as $i => [$subject]) {
                $agentOf[$i] = [(int) $subject->agent_id, $subject->agent?->name ?? 'Unassigned'];
            }
            $slots = $this->planByAgent(
                $agentOf,
                (array) ($pattern['days'] ?? []),
                $time,
                (int) ($pattern['gap'] ?? 0),
                (array) ($pattern['agents'] ?? []),
            );
        }

        return DB::transaction(function () use ($user, $checked, $platforms, $slots, $mode, $time, $pattern) {
            $created = collect();
            foreach ($checked as $i => [$subject, $copy, $path]) {
                $isAd = $subject instanceof CompanyAd;
                foreach ($platforms as $platform) {
                    $created->push(PropertyMarketingPost::create([
                        'agency_id'     => $subject->agency_id,
                        'property_id'   => $isAd ? null : $subject->id,
                        'company_ad_id' => $isAd ? $subject->id : null,
                        'user_id'       => $user->id,
                        'platform'      => $platform,
                        'ad_copy'       => $copy,
                        'image_urls'    => [$this->images->urlFor($path)],
                        'status'        => PropertyMarketingPost::STATUS_SCHEDULED,
                        'scheduled_for' => $slots[$i],
                    ]));
                }
            }

            if ($mode === 'agents') {
                $this->rememberPattern($user, (array) $pattern['days'], $time, (int) $pattern['gap'], (string) ($pattern['order'] ?? ''), (array) ($pattern['agents'] ?? []));
            }

            return $created;
        });
    }

    /** @param list<string> $platforms @throws ScheduleException */
    public function assertCopy(string $copy, array $platforms): void
    {
        if ($copy === '') {
            throw new ScheduleException('Every post needs some text.');
        }
        $limit = in_array('instagram', $platforms, true) ? 2200 : 5000;
        if (mb_strlen($copy) > $limit) {
            throw new ScheduleException('That text is too long (' . $limit . ' characters at most).');
        }
    }

    // ── change a waiting post ───────────────────────────────────────────────

    /**
     * Edit copy and/or time while the post is still 'scheduled'. Atomic against the publisher: if the
     * post was claimed a moment ago the update matches nothing and the agent is told.
     *
     * @throws ScheduleException
     */
    public function update(PropertyMarketingPost $post, ?string $copy, ?string $date, ?string $time): PropertyMarketingPost
    {
        $changes = [];

        if ($copy !== null) {
            $copy = trim($copy);
            $this->assertCopy($copy, [$post->platform]);
            $changes['ad_copy'] = $copy;
        }

        if ($date !== null && $time !== null) {
            $when = $this->toUtc($date, $time);
            // Only a CHANGED time has to clear the rules (editing the copy of a post due in 2 minutes is fine).
            if (! $post->scheduled_for || ! $post->scheduled_for->equalTo($when)) {
                $this->assertInWindow($when);
            }
            $changes['scheduled_for'] = $when;
        }

        if ($changes === []) {
            return $post;
        }

        $this->guardedChange($post, PropertyMarketingPost::STATUS_SCHEDULED, $changes + ['status' => PropertyMarketingPost::STATUS_SCHEDULED],
            'This post is already being published, so it can no longer be changed.');

        return $post->refresh();
    }

    /** Cancel a waiting post. It stays on the list as 'cancelled'; nothing is deleted. */
    public function cancel(PropertyMarketingPost $post): PropertyMarketingPost
    {
        $this->guardedChange($post, PropertyMarketingPost::STATUS_SCHEDULED, ['status' => PropertyMarketingPost::STATUS_CANCELLED],
            'This post can no longer be cancelled here (it is ' . $post->status . ').');

        return $post->refresh();
    }

    /**
     * Put a cancelled post back on the schedule at a new time. Re-checks everything that could have
     * changed since: the connection, the listing and the picture.
     *
     * @throws ScheduleException
     */
    public function reschedule(PropertyMarketingPost $post, string $date, string $time): PropertyMarketingPost
    {
        if ($post->status !== PropertyMarketingPost::STATUS_CANCELLED) {
            throw new ScheduleException('Only a cancelled post can be put back on the schedule.');
        }

        $when = $this->toUtc($date, $time);
        $this->assertInWindow($when);

        $owner = User::withoutGlobalScopes()->whereNull('deleted_at')->find($post->user_id);
        if (! $owner || ! $owner->is_active) {
            throw new ScheduleException('The person who owns this post no longer has access, so it cannot be put back on the schedule.');
        }
        if (empty($this->connections($owner)[$post->platform] ?? false)) {
            throw new ScheduleException(
                (self::PLATFORMS[$post->platform] ?? ucfirst((string) $post->platform)) . ' is not connected for the person who owns this post. ' . $this->connectHint()
            );
        }

        if ($post->company_ad_id !== null) {
            if (! CompanyAd::userMayManage($owner) || ! CompanyAd::query()->where('agency_id', $post->agency_id)->find($post->company_ad_id)) {
                throw new ScheduleException('The company ad for this post is no longer available, or its owner may no longer post company ads.');
            }
        } else {
            $property = Property::query()->find($post->property_id);
            if (! $property) {
                throw new ScheduleException('The listing for this post is no longer available.');
            }
            $this->assertMarketable($property);
        }

        if (! $this->images->allPresent($post)) {
            throw new ScheduleException('This post\'s picture has been cleared away (pictures are kept 30 days after a post is cancelled). Make the ad again and schedule it fresh.');
        }

        $this->guardedChange($post, PropertyMarketingPost::STATUS_CANCELLED, [
            'status' => PropertyMarketingPost::STATUS_SCHEDULED, 'scheduled_for' => $when, 'failure_reason' => null,
        ], 'This post changed in the meantime. Refresh the page and try again.');

        return $post->refresh();
    }

    /**
     * UPDATE ... WHERE status = $from, so a change and the publisher's claim can never both win.
     *
     * @param  array<string,mixed>  $changes
     * @throws ScheduleException when the post was no longer in $from
     */
    private function guardedChange(PropertyMarketingPost $post, string $from, array $changes, string $lostMessage): void
    {
        $row = $changes;
        if (array_key_exists('scheduled_for', $row)) {
            $row['scheduled_for'] = $row['scheduled_for']->copy()->utc()->format('Y-m-d H:i:s');
        }
        $row['updated_at'] = now();

        $n = DB::table('property_marketing_posts')
            ->where('id', $post->id)
            ->where('status', $from)
            ->whereNull('deleted_at')
            ->update($row);

        if ($n !== 1) {
            throw new ScheduleException($lostMessage);
        }
    }
}
