<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Events\Property\PropertyCompliancePassed;
use App\Listeners\Property\DismissComplianceClearedChores;
use App\Models\CommandCenter\CommandTask;
use App\Models\CommandCenter\NotificationDispatchLog;
use App\Models\CommandCenter\NotificationEventType;
use App\Models\CommandCenter\UserNotificationPreference;
use App\Models\Property;
use App\Models\User;
use App\Services\CommandCenter\AutoEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Properties marked compliant (P24 go-live imports especially) must not carry
 * the auto "Upload signed mandate / owner ID / proof of ownership" document
 * tasks or "needs attention" idle prompts — compliance is exactly what those
 * chase.
 */
final class ComplianceChoreClearingTest extends TestCase
{
    use RefreshDatabase;

    public function test_compliance_passed_clears_auto_chore_tasks_but_keeps_manual_and_done(): void
    {
        [$agencyId, $branchId, $agentId] = $this->seedBasics();
        $propId = $this->makeProperty($agencyId, $branchId, $agentId, compliant: true);

        $doc    = $this->makeTask($agencyId, $branchId, $agentId, $propId, 'document_upload', 'automation_rule', 'todo');
        $idle   = $this->makeTask($agencyId, $branchId, $agentId, $propId, 'review', 'automation_rule', 'todo');
        $manual = $this->makeTask($agencyId, $branchId, $agentId, $propId, 'document_upload', 'manual', 'todo');
        $done   = $this->makeTask($agencyId, $branchId, $agentId, $propId, 'document_upload', 'automation_rule', 'done');

        $property = Property::withoutGlobalScopes()->findOrFail($propId);
        (new DismissComplianceClearedChores())->handle(new PropertyCompliancePassed($property, $agentId));

        $this->assertSoftDeleted('command_tasks', ['id' => $doc]);
        $this->assertSoftDeleted('command_tasks', ['id' => $idle]);
        $this->assertNotSoftDeleted('command_tasks', ['id' => $manual]); // manual is sacred
        $this->assertNotSoftDeleted('command_tasks', ['id' => $done]);   // already resolved, untouched
    }

    public function test_dispatching_the_event_clears_tasks_via_the_registered_listener(): void
    {
        [$agencyId, $branchId, $agentId] = $this->seedBasics();
        $propId = $this->makeProperty($agencyId, $branchId, $agentId, compliant: true);
        $task   = $this->makeTask($agencyId, $branchId, $agentId, $propId, 'document_upload', 'automation_rule', 'todo');

        // Real dispatch through the app's event bus — proves AppServiceProvider
        // wired DismissComplianceClearedChores to PropertyCompliancePassed.
        $property = Property::withoutGlobalScopes()->findOrFail($propId);
        event(new PropertyCompliancePassed($property, $agentId));

        $this->assertSoftDeleted('command_tasks', ['id' => $task]);
    }

    public function test_already_compliant_property_gets_no_document_tasks_on_create(): void
    {
        [$agencyId, $branchId, $agentId] = $this->seedBasics();

        $compliant    = Property::withoutGlobalScopes()->findOrFail($this->makeProperty($agencyId, $branchId, $agentId, compliant: true));
        $nonCompliant = Property::withoutGlobalScopes()->findOrFail($this->makeProperty($agencyId, $branchId, $agentId, compliant: false));

        $svc = new AutoEventService();
        $svc->onPropertyCreated($compliant);
        $svc->onPropertyCreated($nonCompliant);

        $this->assertSame(0, CommandTask::withoutGlobalScopes()->where('property_id', $compliant->id)->count());
        $this->assertGreaterThan(0, CommandTask::withoutGlobalScopes()->where('property_id', $nonCompliant->id)->count());
    }

    public function test_idle_scan_skips_compliant_properties(): void
    {
        [$agencyId, $branchId, $agentId] = $this->seedBasics();
        // Idle (no activity) but compliant — must NOT be flagged.
        $compliantId = $this->makeProperty($agencyId, $branchId, $agentId, compliant: true);
        DB::table('properties')->where('id', $compliantId)->update(['last_activity_at' => now()->subDays(60)]);

        (new AutoEventService())->flagIdleProperties();

        $this->assertSame(0, CommandTask::withoutGlobalScopes()
            ->where('property_id', $compliantId)->where('task_type', 'review')->count());
    }

    public function test_backfill_command_clears_existing_redundant_tasks(): void
    {
        [$agencyId, $branchId, $agentId] = $this->seedBasics();
        $compliantId    = $this->makeProperty($agencyId, $branchId, $agentId, compliant: true);
        $nonCompliantId = $this->makeProperty($agencyId, $branchId, $agentId, compliant: false);

        $redundant = $this->makeTask($agencyId, $branchId, $agentId, $compliantId, 'document_upload', 'automation_rule', 'todo');
        $keep      = $this->makeTask($agencyId, $branchId, $agentId, $nonCompliantId, 'document_upload', 'automation_rule', 'todo');

        $this->artisan('command-center:clear-compliant-chores')->assertSuccessful();

        $this->assertSoftDeleted('command_tasks', ['id' => $redundant]);
        $this->assertNotSoftDeleted('command_tasks', ['id' => $keep]);
    }

    public function test_property_scan_skips_documents_missing_for_compliant_property(): void
    {
        [$agencyId, $branchId, $agentId] = $this->seedBasics();
        $agent = User::find($agentId);
        $this->makeDocsMissingType();
        $this->enablePref($agent, 'property.documents_missing');

        $compliantId    = $this->makeAgedProperty($agencyId, $branchId, $agentId, compliant: true);
        $nonCompliantId = $this->makeAgedProperty($agencyId, $branchId, $agentId, compliant: false);

        $this->artisan('notifications:scan-properties')->assertSuccessful();

        $typeId = NotificationEventType::where('key', 'property.documents_missing')->value('id');

        $this->assertSame(0, NotificationDispatchLog::where('subject_id', $compliantId)
            ->where('notification_event_type_id', $typeId)->count(), 'compliant property must not notify');
        $this->assertGreaterThan(0, NotificationDispatchLog::where('subject_id', $nonCompliantId)
            ->where('notification_event_type_id', $typeId)->count(), 'non-compliant property should notify');
    }

    public function test_backfill_marks_compliant_documents_missing_notifications_read(): void
    {
        [$agencyId, $branchId, $agentId] = $this->seedBasics();
        $compliantId    = $this->makeProperty($agencyId, $branchId, $agentId, compliant: true);
        $nonCompliantId = $this->makeProperty($agencyId, $branchId, $agentId, compliant: false);

        $clearMe = $this->makeDocsMissingNotification($agentId, $compliantId);
        $keepMe  = $this->makeDocsMissingNotification($agentId, $nonCompliantId);

        $this->artisan('command-center:clear-compliant-chores')->assertSuccessful();

        $this->assertNotNull(DB::table('notifications')->where('id', $clearMe)->value('read_at'), 'compliant notif marked read');
        $this->assertNull(DB::table('notifications')->where('id', $keepMe)->value('read_at'), 'non-compliant notif left unread');
    }

    public function test_imported_stock_skips_auto_chore_tasks_on_create(): void
    {
        [$agencyId, $branchId, $agentId] = $this->seedBasics();
        // Non-compliant at create (the leak case): without the flag this would
        // spawn the document-chase chore tasks that accumulated to 18k on staging.
        $propId   = $this->makeProperty($agencyId, $branchId, $agentId, compliant: false);
        $property = Property::withoutGlobalScopes()->findOrFail($propId);
        $property->skipNewListingAutomation = true;

        (new AutoEventService())->onPropertyCreated($property);

        $this->assertSame(0, CommandTask::withoutGlobalScopes()->where('property_id', $propId)->count(),
            'imported stock (skipNewListingAutomation) must not generate chore tasks');
    }

    public function test_normal_capture_still_generates_chore_tasks(): void
    {
        // Regression guard: the flag must not suppress genuine new mandates.
        [$agencyId, $branchId, $agentId] = $this->seedBasics();
        $property = Property::withoutGlobalScopes()
            ->findOrFail($this->makeProperty($agencyId, $branchId, $agentId, compliant: false));

        (new AutoEventService())->onPropertyCreated($property); // flag defaults false

        $this->assertGreaterThan(0, CommandTask::withoutGlobalScopes()->where('property_id', $property->id)->count(),
            'genuine new (non-imported, non-compliant) stock must still get its chase tasks');
    }

    public function test_clear_command_clears_imported_and_orphaned_chores_but_keeps_genuine(): void
    {
        [$agencyId, $branchId, $agentId] = $this->seedBasics();

        // (b) imported P24 stock, NOT compliant → cleared.
        $importedId = $this->makeProperty($agencyId, $branchId, $agentId, compliant: false);
        DB::table('properties')->where('id', $importedId)->update(['p24_listing_number' => '109876543']);
        $importedChore = $this->makeTask($agencyId, $branchId, $agentId, $importedId, 'document_upload', 'automation_rule', 'todo');

        // genuine non-imported, non-compliant stock → KEPT (active mandate still chased).
        $genuineId    = $this->makeProperty($agencyId, $branchId, $agentId, compliant: false);
        $genuineChore = $this->makeTask($agencyId, $branchId, $agentId, $genuineId, 'document_upload', 'automation_rule', 'todo');

        // (c) orphaned: property soft-deleted → cleared.
        $orphanPropId = $this->makeProperty($agencyId, $branchId, $agentId, compliant: false);
        $orphanChore  = $this->makeTask($agencyId, $branchId, $agentId, $orphanPropId, 'document_upload', 'automation_rule', 'todo');
        DB::table('properties')->where('id', $orphanPropId)->update(['deleted_at' => now()]);

        $this->artisan('command-center:clear-compliant-chores')->assertSuccessful();

        $this->assertSoftDeleted('command_tasks', ['id' => $importedChore]);
        $this->assertSoftDeleted('command_tasks', ['id' => $orphanChore]);
        $this->assertNotSoftDeleted('command_tasks', ['id' => $genuineChore]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:int,2:int} */
    private function seedBasics(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'Branch 1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agentId = \App\Models\User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'role' => 'agent',
        ])->id;

        return [$agencyId, $branchId, $agentId];
    }

    private function makeProperty(int $agencyId, int $branchId, int $agentId, bool $compliant): int
    {
        return (int) DB::table('properties')->insertGetId([
            'external_id' => (string) Str::uuid(), 'title' => 'Test Property',
            'agent_id' => $agentId, 'branch_id' => $branchId, 'agency_id' => $agencyId,
            'listing_type' => 'sale',
            'compliance_snapshot_at' => $compliant ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeTask(int $agencyId, int $branchId, int $userId, int $propertyId, string $type, string $source, string $status): int
    {
        return (int) DB::table('command_tasks')->insertGetId([
            'title' => ucfirst($type) . ' task', 'task_type' => $type, 'status' => $status,
            'priority' => 'normal', 'assigned_to' => $userId, 'source_type' => $source,
            'property_id' => $propertyId, 'due_date' => now()->subDay(),
            'agency_id' => $agencyId, 'branch_id' => $branchId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A property old enough to trip the documents-missing age threshold. */
    private function makeAgedProperty(int $agencyId, int $branchId, int $agentId, bool $compliant): int
    {
        $id = $this->makeProperty($agencyId, $branchId, $agentId, $compliant);
        DB::table('properties')->where('id', $id)->update([
            'address'    => '12 Test Road',
            'status'     => 'active',
            'created_at' => now()->subDays(30),
        ]);

        return $id;
    }

    private function makeDocsMissingType(): void
    {
        NotificationEventType::create([
            'key' => 'property.documents_missing', 'pillar' => 'property',
            'group_label' => 'Documents', 'label' => 'Documents not uploaded after listing',
            'description' => 'Notify when a newly listed property has no documents on file.',
            'default_enabled' => true, 'threshold_unit' => 'hours', 'default_threshold' => 24,
            'threshold_min' => 1, 'threshold_max' => 168,
            'supports_in_app' => true, 'supports_email' => true, 'supports_push' => true,
            'is_adapter' => false, 'adapter_column' => null, 'sort_order' => 1,
        ]);
    }

    private function enablePref(User $user, string $key): void
    {
        $type = NotificationEventType::where('key', $key)->firstOrFail();
        UserNotificationPreference::updateOrCreate(
            ['user_id' => $user->id, 'notification_event_type_id' => $type->id],
            ['enabled' => true, 'threshold' => 1, 'channel_in_app' => true, 'channel_email' => false, 'channel_push' => false]
        );
    }

    private function makeDocsMissingNotification(int $userId, int $propertyId): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id, 'type' => \App\Notifications\PillarEventNotification::class,
            'notifiable_type' => 'App\\Models\\User', 'notifiable_id' => $userId,
            'data' => json_encode([
                'event_key' => 'property.documents_missing',
                'subject_type' => 'App\\Models\\Property', 'subject_id' => $propertyId,
                'title' => 'Test Property — documents missing',
            ]),
            'read_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
