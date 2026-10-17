<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Models\AgentSocialAccount;
use App\Models\Property;
use App\Models\PropertyMarketingPost;
use App\Models\User;
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

    // ── schedule ────────────────────────────────────────────────────────────

    /**
     * Schedule one post per (ad, platform). All or nothing: if any ad or the time is refused, nothing is saved.
     *
     * @param  list<array{property_id:int|string, copy:string, image_path:string}>  $items
     * @param  list<string>  $platforms
     * @return Collection<int,PropertyMarketingPost>
     * @throws ScheduleException
     */
    public function schedule(User $user, array $items, array $platforms, string $mode, string $date, string $time): Collection
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

        $slots = $this->plan($mode, $date, $time, count($items));

        // Validate every item first, so a refusal saves nothing.
        $checked = [];
        foreach ($items as $i => $item) {
            $property = $this->propertyFor($user, (int) ($item['property_id'] ?? 0));
            $copy     = trim((string) ($item['copy'] ?? ''));
            $this->assertCopy($copy, $platforms);

            $path = (string) ($item['image_path'] ?? '');
            if ($path === '' || ! $this->images->belongsTo($user, $path)) {
                throw new ScheduleException('The picture for "' . ($property->title ?: 'one ad') . '" was not uploaded. Please try again.');
            }
            $checked[$i] = [$property, $copy, $path];
        }

        return DB::transaction(function () use ($user, $checked, $platforms, $slots) {
            $created = collect();
            foreach ($checked as $i => [$property, $copy, $path]) {
                foreach ($platforms as $platform) {
                    $created->push(PropertyMarketingPost::create([
                        'agency_id'     => $property->agency_id,
                        'property_id'   => $property->id,
                        'user_id'       => $user->id,
                        'platform'      => $platform,
                        'ad_copy'       => $copy,
                        'image_urls'    => [$this->images->urlFor($path)],
                        'status'        => PropertyMarketingPost::STATUS_SCHEDULED,
                        'scheduled_for' => $slots[$i],
                    ]));
                }
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

        $owner = User::withoutGlobalScopes()->find($post->user_id);
        if (! $owner || empty($this->connections($owner)[$post->platform] ?? false)) {
            throw new ScheduleException(
                (self::PLATFORMS[$post->platform] ?? ucfirst((string) $post->platform)) . ' is not connected for the person who owns this post. ' . $this->connectHint()
            );
        }

        $property = Property::query()->find($post->property_id);
        if (! $property) {
            throw new ScheduleException('The listing for this post is no longer available.');
        }
        $this->assertMarketable($property);

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
