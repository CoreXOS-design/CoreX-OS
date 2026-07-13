<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Agency;
use App\Models\CommandCenter\NotificationDispatchLog;
use App\Models\CommandCenter\NotificationEventType;
use App\Models\CommandCenter\UserDashboardSetting;
use App\Models\Contact;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * AT-235 (R3) — the test that would have caught the 1.9-million-notification storm.
 *
 * `contact.fica_missing` fired 1,903,039 times between 26 May and 19 Jun 2026 —
 * 286,070 in a single day, 99.5% of the entire dispatch log — from INSIDE the one
 * pipeline that has preferences, cooldown and an idempotency ledger.
 *
 * The mechanism: `NotificationDispatcher::fire()` deduped on
 * (user, event, subject, channel, threshold_hit_at), and the threshold defaulted to
 * `now()`. A moving key mints a fresh dedup entry on every scan tick, so the check
 * `threshold_hit_at >= $thresholdHit` never matched a row written 30 minutes ago.
 * The scanner re-told the same user the same fact about the same contact every 30
 * minutes for 24 days.
 *
 * Nothing detected it. Nothing capped it. A human noticed and soft-deleted the event
 * type by hand, ten minutes after the last dispatch.
 *
 * The ledger is only ever as good as the stability of the key it dedups on — so the
 * stability of that key is now a test.
 */
final class NotificationDispatcherDedupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Contact $contact;
    private NotificationEventType $eventType;
    private NotificationDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc']);
        $branch = \App\Models\Branch::create(['agency_id' => $agency->id, 'name' => 'Shelly Beach']);

        $this->user = User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role'      => 'agent',
        ]);

        $this->contact = Contact::create([
            'agency_id'  => $agency->id,
            'branch_id'  => $branch->id,
            'first_name' => 'Thandeka',
            'last_name'  => 'Mkhize',
            'email'      => 'thandeka.mkhize@example.co.za',
        ]);

        $this->eventType = NotificationEventType::create([
            'key'              => 'contact.test_nag',
            'pillar'           => 'contact',
            'group_label'      => 'Contact',
            'label'            => 'Test nag',
            'description'      => 'Fixture',
            'default_enabled'  => true,
            'threshold_unit'   => 'hours',
            'default_threshold' => 24,
            'supports_in_app'  => true,
            'supports_email'   => false,
            'supports_push'    => false,
            'is_adapter'       => false,
            'sort_order'       => 999,
        ]);

        // ── ISOLATE THE DEDUP KEY ────────────────────────────────────────────
        //
        // The 6-hour cooldown (min_minutes_between_same, default 360) MASKS the
        // dedup-key bug completely: with it on, these tests pass even against the
        // pre-fix `?? now()` default, and prove nothing. I caught that by running
        // them against the reverted dispatcher — they were green. Test theatre.
        //
        // The storm ran with NO cooldown at all (the cooldown check only landed on
        // 29 May, mid-storm — and a user can still set it to 0). So the cooldown is
        // a BACKSTOP, not the control under test. Turn it off here so the dedup key
        // is the only thing standing between a scan tick and a notification —
        // exactly the condition that produced 1.9M of them.
        //
        // The backstop gets its own test at the bottom.
        UserDashboardSetting::updateOrCreate(
            ['user_id' => $this->user->id],
            array_merge(UserDashboardSetting::defaults(), ['min_minutes_between_same' => 0])
        );

        $this->dispatcher = app(NotificationDispatcher::class);
    }

    private function fire(array $args = []): bool
    {
        return $this->dispatcher->fire($this->user, 'contact.test_nag', $this->contact, array_merge([
            'title' => 'FICA outstanding',
            'body'  => 'This contact has no FICA on file.',
        ], $args));
    }

    private function dispatchCount(): int
    {
        return NotificationDispatchLog::where('user_id', $this->user->id)
            ->where('notification_event_type_id', $this->eventType->id)
            ->count();
    }

    // ── THE STORM ───────────────────────────────────────────────────────────

    /**
     * THE REGRESSION TEST. A scanner runs every 30 minutes and re-evaluates the same
     * still-true predicate ("this contact still has no FICA"). It must tell the user
     * ONCE — not once per tick.
     *
     * Pre-fix this failed: with `?? now()` the second call minted a new key and fired
     * again. That is the whole storm, in three lines.
     */
    public function test_a_scanner_re_evaluating_the_same_fact_does_not_re_notify(): void
    {
        // A persistent condition keys off WHEN THE FACT BECAME TRUE — stable forever,
        // however many times the scanner re-evaluates it.
        $factBecameTrue = $this->contact->created_at->copy()->startOfDay();

        $this->assertTrue($this->fire(['threshold_hit_at' => $factBecameTrue]), 'first scan tick should notify');
        $first = $this->dispatchCount();
        $this->assertGreaterThan(0, $first);

        // The next scan tick, minutes later. Same user, same contact, same fact.
        $this->travel(31)->minutes();
        $this->assertFalse(
            $this->fire(['threshold_hit_at' => $factBecameTrue]),
            'the SAME fact must not be re-notified on the next tick'
        );

        $this->assertSame(
            $first,
            $this->dispatchCount(),
            'THE 1.9M STORM: a moving dedup key re-fired the same fact every 30 minutes for 24 days'
        );
    }

    /**
     * THE TEST THAT KILLS THE TIME-BUCKET TEMPTATION.
     *
     * My first attempt at this fix defaulted an omitted key to `now()->startOfHour()`.
     * It looked safe and it made the tests green — but it only turns a half-hourly
     * storm into an HOURLY one: 24 alerts/day/pair x 15,178 pairs is still ~364,000
     * notifications a day. A time bucket is not a fact.
     *
     * A fact-derived key holds across any number of ticks, over any number of hours.
     */
    public function test_scan_ticks_across_many_hours_produce_exactly_one_notification(): void
    {
        $factBecameTrue = $this->contact->created_at->copy()->startOfDay();

        $this->fire(['threshold_hit_at' => $factBecameTrue]);
        $after = $this->dispatchCount();

        // 48 ticks over 24 hours — deliberately crossing every hour boundary, which
        // is exactly what defeats a time-bucket key.
        for ($i = 0; $i < 48; $i++) {
            $this->travel(30)->minutes();
            $this->fire(['threshold_hit_at' => $factBecameTrue]);
        }

        $this->assertSame(
            $after,
            $this->dispatchCount(),
            'a persistent fact must notify ONCE — not once per tick, and not once per hour'
        );
    }

    /**
     * The caller SHOULD pass an explicit, stable threshold. When it does, dedup is
     * exact — this is the correct usage (ScanPropertyNotifications passes
     * now()->startOfHour()).
     */
    public function test_an_explicit_stable_threshold_dedups_exactly(): void
    {
        $stable = now()->startOfHour();

        $this->assertTrue($this->fire(['threshold_hit_at' => $stable]));
        $count = $this->dispatchCount();

        $this->travel(20)->minutes();
        $this->assertFalse($this->fire(['threshold_hit_at' => $stable]));

        $this->assertSame($count, $this->dispatchCount());
    }

    /**
     * PREVENT, don't absorb. The dispatcher cannot guess whether the caller means a
     * persistent condition (notify once) or a discrete event (notify each time) — and
     * the guess it used to make (`?? now()`) silently turned every persistent
     * condition into a discrete one. So the key is REQUIRED, and a caller that forgets
     * fails loudly in dev rather than shipping an un-closable tap.
     */
    public function test_omitting_the_dedup_key_is_refused_outright(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/requires an explicit threshold_hit_at/');

        $this->fire(); // no threshold_hit_at — the storm's root cause
    }

    /**
     * STATIC GUARD — so the throw above can never reach production.
     *
     * Every `->fire(` call site in app/ must pass a threshold_hit_at. This fails the
     * BUILD, not the user, the moment someone adds a 9th call site that forgets.
     */
    public function test_every_fire_call_site_passes_a_dedup_key(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $src = file_get_contents($file->getPathname());

            // Only NotificationDispatcher's fire() — other classes have their own
            // unrelated fire() methods (e.g. DealMoneyLineObserver::fire()), and
            // flagging those would be a false positive that trains people to ignore
            // this guard.
            if (! str_contains($src, 'NotificationDispatcher')) {
                continue;
            }

            // Isolate each ->fire( ... ) call and check its arg array carries the key.
            if (preg_match_all('/->fire\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$_, $offset]) {
                    $window = substr($src, $offset, 2000); // the call's full arg array
                    if (! str_contains($window, 'threshold_hit_at')) {
                        $offenders[] = str_replace(base_path() . '/', '', $file->getPathname());
                    }
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($offenders)),
            "These NotificationDispatcher::fire() call sites do not pass a threshold_hit_at dedup key:\n  - "
            . implode("\n  - ", array_unique($offenders))
            . "\n\nPass a STABLE key for a persistent condition (notify once) or now() for a discrete "
            . "event (notify each time). Omitting it is what let contact.fica_missing fire 1,903,039 times."
        );
    }

    // ── it must still notify when it genuinely should ────────────────────────

    /** A DIFFERENT contact is a different fact — it must still notify (no false dedup). */
    public function test_a_different_subject_still_notifies(): void
    {
        $this->fire(['threshold_hit_at' => now()->startOfHour()]);
        $before = $this->dispatchCount();

        $other = Contact::create([
            'agency_id'  => $this->user->agency_id,
            'branch_id'  => $this->user->branch_id,
            'first_name' => 'Sipho',
            'last_name'  => 'Dlamini',
            'email'      => 'sipho.dlamini@example.co.za',
        ]);

        $this->dispatcher->fire($this->user, 'contact.test_nag', $other, [
            'title'            => 'FICA outstanding',
            'body'             => 'Another contact.',
            'threshold_hit_at' => now()->startOfHour(),
        ]);

        $this->assertGreaterThan(
            $before,
            $this->dispatchCount(),
            'dedup must not silence a genuinely different subject'
        );
    }

    /** A genuinely NEW threshold (the fact re-occurred later) must notify again. */
    public function test_a_new_threshold_notifies_again(): void
    {
        $this->fire(['threshold_hit_at' => now()->startOfHour()]);
        $before = $this->dispatchCount();

        // A day later, the predicate trips again with a new threshold. The cooldown
        // (default 360 min) has long expired.
        $this->travel(25)->hours();

        $this->assertTrue(
            $this->fire(['threshold_hit_at' => now()->startOfHour()]),
            'a genuinely new occurrence must still reach the user'
        );

        $this->assertGreaterThan($before, $this->dispatchCount());
    }

    // ── the backstop, tested separately ─────────────────────────────────────

    /**
     * The cooldown is the SECOND line of defence, and it must keep working — but it
     * is not a substitute for a stable dedup key. During the storm it capped the
     * damage at ~4 dispatches/day/pair (15,178 pairs x 4 = ~60k/day, which matches
     * the 9 June figure of 59,776 almost exactly) — while the broken key kept the
     * tap open.
     */
    public function test_the_cooldown_backstop_still_suppresses_a_repeat_within_its_window(): void
    {
        UserDashboardSetting::updateOrCreate(
            ['user_id' => $this->user->id],
            array_merge(UserDashboardSetting::defaults(), ['min_minutes_between_same' => 360])
        );

        // Two DIFFERENT thresholds — so the dedup key would allow the second fire.
        // Only the cooldown can stop it.
        $this->fire(['threshold_hit_at' => now()->startOfHour()]);
        $before = $this->dispatchCount();

        $this->travel(70)->minutes(); // new hour = new key, but inside the 6h cooldown

        $this->assertFalse(
            $this->fire(['threshold_hit_at' => now()->startOfHour()]),
            'the cooldown must still suppress a repeat inside its window'
        );
        $this->assertSame($before, $this->dispatchCount());
    }
}
