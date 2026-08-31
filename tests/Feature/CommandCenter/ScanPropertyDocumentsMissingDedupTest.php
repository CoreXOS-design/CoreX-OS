<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\CommandCenter\NotificationDispatchLog;
use App\Models\CommandCenter\NotificationEventType;
use App\Models\CommandCenter\UserDashboardSetting;
use App\Models\CommandCenter\UserNotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Listed 47 days ago, no documents on file" — told to the same agent, about the
 * same listing, four times a day, forever.
 *
 * ScanPropertyNotifications passed `now()->startOfHour()` as the dispatcher's
 * dedup key for property.documents_missing. The dispatcher suppresses a repeat
 * only when an existing log row has `threshold_hit_at >= $thresholdHit`, so an
 * hourly bucket mints a fresh key every hour and the idempotency ledger never
 * matches. The 6h cooldown (min_minutes_between_same, default 360) was the only
 * thing left holding it back — which is why it landed ~4x/day/pair rather than
 * every 30 minutes, and why it looked like a tolerable trickle instead of the
 * open tap it was: 23,792 alerts between 26 May and 31 Aug 2026, still firing
 * when it was found. One agent was taking 39 pushes a day about 19 listings.
 *
 * This is the SAME defect as the 1,903,039-notification contact.fica_missing
 * storm, and NotificationDispatcherDedupTest had already named it in advance —
 * "a time bucket is not a fact". That test proved the dispatcher honours a stable
 * key; nothing proved the SCANNER passed one. So the storm shipped again, in the
 * next call site along, while the guard test sat green.
 *
 * This test closes that gap for the property scanner: it drives the real command
 * across real hour and day boundaries and asserts the agent is told ONCE.
 */
final class ScanPropertyDocumentsMissingDedupTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_scans_across_hours_notify_the_agent_exactly_once(): void
    {
        Notification::fake();

        [$agencyId, $agent] = $this->seedAgencyAgent();
        $this->makeDocsMissingType();
        $this->enablePref($agent, 'property.documents_missing');

        // ── ISOLATE THE DEDUP KEY ────────────────────────────────────────────
        // The 6h cooldown MASKS this bug completely: leave it on and the scanner
        // looks almost well-behaved (4/day instead of 24/day), and this test
        // passes against the broken key. It is a BACKSTOP, not the control under
        // test — and a user can set it to 0. Turn it off so the dedup key is the
        // only thing between a scan tick and a push.
        UserDashboardSetting::updateOrCreate(
            ['user_id' => $agent->id],
            array_merge(UserDashboardSetting::defaults(), ['min_minutes_between_same' => 0])
        );

        $propertyId = $this->insertProperty($agent->id, $agencyId);
        $typeId = (int) NotificationEventType::where('key', 'property.documents_missing')->value('id');

        $count = fn (): int => NotificationDispatchLog::where('user_id', $agent->id)
            ->where('notification_event_type_id', $typeId)
            ->where('subject_id', $propertyId)
            ->count();

        $this->artisan('notifications:scan-properties')->assertExitCode(0);

        $afterFirst = $count();
        $this->assertGreaterThan(0, $afterFirst, 'the agent must be told once that documents are missing');

        // 24 further ticks at 65-minute intervals — every one lands in a NEW hour
        // bucket, and the run crosses a day boundary. This is precisely what a
        // clock-derived key cannot survive and a fact-derived one does not notice.
        for ($i = 0; $i < 24; $i++) {
            $this->travel(65)->minutes();
            $this->artisan('notifications:scan-properties')->assertExitCode(0);
        }

        $this->assertSame(
            $afterFirst,
            $count(),
            'THE FLOOD: a still-true condition must be reported ONCE — not once per hour. '
            . 'A clock-derived threshold_hit_at re-opens the tap on every tick.'
        );
    }

    /** Dedup must not over-reach: a second listing is a second fact and must still notify. */
    public function test_a_second_listing_is_still_reported(): void
    {
        Notification::fake();

        [$agencyId, $agent] = $this->seedAgencyAgent();
        $this->makeDocsMissingType();
        $this->enablePref($agent, 'property.documents_missing');

        $first = $this->insertProperty($agent->id, $agencyId);
        $this->artisan('notifications:scan-properties')->assertExitCode(0);

        $second = $this->insertProperty($agent->id, $agencyId);
        $this->travel(65)->minutes();
        $this->artisan('notifications:scan-properties')->assertExitCode(0);

        $typeId = (int) NotificationEventType::where('key', 'property.documents_missing')->value('id');

        $this->assertGreaterThan(
            0,
            NotificationDispatchLog::where('user_id', $agent->id)
                ->where('notification_event_type_id', $typeId)
                ->where('subject_id', $second)->count(),
            'a genuinely different listing must still reach the agent'
        );
        $this->assertNotSame($first, $second);
    }

    // ── Helpers (mirrored from ScanTenantScopeTest) ──────────────────────────

    /** @return array{0:int,1:User} */
    private function seedAgencyAgent(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent',
        ]);

        return [$agencyId, $user];
    }

    private function insertProperty(int $agentId, int $agencyId): int
    {
        $p = new \App\Models\Property();
        $p->forceFill([
            'title'     => 'Listing ' . Str::random(5),
            'address'   => '12 Test Road',
            'agent_id'  => $agentId,
            'agency_id' => $agencyId,
            'status'    => 'active',
        ])->save();

        // Backdate so the documents-missing age threshold is comfortably exceeded.
        DB::table('properties')->where('id', $p->id)->update([
            'created_at' => now()->subDays(30),
            'updated_at' => now()->subDays(30),
        ]);

        return (int) $p->id;
    }

    private function makeDocsMissingType(): NotificationEventType
    {
        return NotificationEventType::create([
            'key'               => 'property.documents_missing',
            'pillar'            => 'property',
            'group_label'       => 'Documents',
            'label'             => 'Documents not uploaded after listing',
            'description'       => 'Notify when a newly listed property has no documents on file.',
            'default_enabled'   => true,
            'threshold_unit'    => 'hours',
            'default_threshold' => 24,
            'threshold_min'     => 1,
            'threshold_max'     => 168,
            'supports_in_app'   => true,
            'supports_email'    => true,
            'supports_push'     => true,
            'is_adapter'        => false,
            'adapter_column'    => null,
            'sort_order'        => 1,
        ]);
    }

    private function enablePref(User $user, string $key): void
    {
        $type = NotificationEventType::where('key', $key)->firstOrFail();
        UserNotificationPreference::updateOrCreate(
            ['user_id' => $user->id, 'notification_event_type_id' => $type->id],
            ['enabled' => true, 'threshold' => 1, 'channel_in_app' => true, 'channel_email' => false, 'channel_push' => true]
        );
    }
}
