<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\CommandCenter\NotificationDispatchLog;
use App\Models\CommandCenter\NotificationEventType;
use App\Models\CommandCenter\UserNotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tenant isolation for the background notification scanners.
 *
 * The scanners run in a console context where AgencyScope is inert (no
 * Auth::user()), so the underlying Property/Deal queries sweep EVERY agency.
 * These tests lock the guarantee that an agent is only ever notified about a
 * subject in their OWN agency — the regression behind "I get push alerts for
 * properties that aren't on my account".
 */
final class ScanTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_property_scan_does_not_notify_agent_about_another_agencys_property(): void
    {
        [$agencyA, $agent] = $this->seedAgencyAgent();
        $agencyB = $this->seedAgency();

        // The event-type catalog is populated by a data migration; the test DB
        // bootstraps from the structure-only schema snapshot, so create it here.
        $this->makeDocsMissingType();
        $this->enablePref($agent, 'property.documents_missing');

        // Property assigned to our agent BUT owned by a different agency.
        $foreignId = $this->insertProperty($agent->id, $agencyB);
        // Property correctly in the agent's own agency.
        $ownId = $this->insertProperty($agent->id, $agencyA);

        $this->artisan('notifications:scan-properties')->assertExitCode(0);

        $type = NotificationEventType::where('key', 'property.documents_missing')->firstOrFail();

        // Cross-agency property: never notified.
        $this->assertSame(0, NotificationDispatchLog::where('user_id', $agent->id)
            ->where('notification_event_type_id', $type->id)
            ->where('subject_id', $foreignId)
            ->count(), 'agent must NOT be notified about a property in another agency');

        // Own-agency property: notified.
        $this->assertGreaterThan(0, NotificationDispatchLog::where('user_id', $agent->id)
            ->where('notification_event_type_id', $type->id)
            ->where('subject_id', $ownId)
            ->count(), 'agent SHOULD be notified about a property in their own agency');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function seedAgency(): int
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

        return $agencyId;
    }

    /** @return array{0:int,1:User} */
    private function seedAgencyAgent(): array
    {
        $agencyId = $this->seedAgency();
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent',
        ]);

        return [$agencyId, $user];
    }

    private function insertProperty(int $agentId, int $agencyId): int
    {
        // Use the model so generated columns (external_id) populate. With no
        // Auth::user() the BelongsToAgency trait trusts the explicit agency_id.
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
