<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\CommandCenter\CommandTask;
use App\Models\Property;
use App\Models\User;
use App\Services\CommandCenter\AutoEventService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-164 — idle-property to-do titles carry a humanised whole-day count (never the
 * signed/fractional garbage), the critical flag fires on genuinely-idle stock, and the
 * repair command rewrites already-persisted malformed titles.
 */
final class IdleTaskTitleTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->agencyId, $this->agent] = $this->seedAgencyUser();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_flagged_idle_title_is_humanised_and_critical_flag_fires(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-04 09:00:00'));
        // 40 calendar days idle → past the default 30-day critical threshold.
        $property = $this->property(Carbon::parse('2026-05-25 15:00:00'));

        app(AutoEventService::class)->flagIdleProperties();

        $task = CommandTask::withoutGlobalScopes()->where('property_id', $property->id)->firstOrFail();

        $this->assertStringContainsString('no activity for 40 days', $task->title);
        $this->assertStringStartsWith('URGENT:', $task->title, '40 days idle is critical');
        // The class of bug: never a negative or fractional day count in the text.
        $this->assertDoesNotMatchRegularExpression('/-?\d+\.\d+\s*days/', $task->title);
        $this->assertStringNotContainsString('in -', $task->title);
    }

    public function test_repair_command_rewrites_a_persisted_malformed_title(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-04 09:00:00'));
        $property = $this->property(Carbon::parse('2026-06-04 09:00:00')); // 30 days idle

        $badId = DB::table('command_tasks')->insertGetId([
            'title'       => 'Property needs attention — no activity in -0.605399650034 days — 12 Beach Rd, Ballito',
            'task_type'   => 'review', 'priority' => 'high', 'status' => 'todo',
            'assigned_to' => $this->agent->id, 'property_id' => $property->id,
            'source_type' => 'automation_rule', 'agency_id' => $this->agencyId,
            'created_at'  => now(), 'updated_at' => now(),
        ]);

        $this->artisan('command-center:repair-idle-task-titles')->assertSuccessful();

        $fixed = CommandTask::withoutGlobalScopes()->findOrFail($badId)->title;
        $this->assertStringContainsString('no activity for 30 days', $fixed);
        $this->assertStringNotContainsString('-0.60', $fixed);
        $this->assertDoesNotMatchRegularExpression('/\d+\.\d+\s*days/', $fixed);

        // Idempotent: a second pass leaves the repaired title unchanged.
        $this->artisan('command-center:repair-idle-task-titles')->assertSuccessful();
        $this->assertSame($fixed, CommandTask::withoutGlobalScopes()->findOrFail($badId)->title);
    }

    // ── helpers ──

    private function property(Carbon $lastActivity): Property
    {
        $id = (int) DB::table('properties')->insertGetId([
            'external_id' => (string) Str::uuid(), 'title' => '12 Beach Road, Ballito',
            'agency_id' => $this->agencyId, 'agent_id' => $this->agent->id,
            'branch_id' => $this->agencyId, 'status' => 'active',
            'last_activity_at' => $lastActivity->toDateTimeString(),
            'street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Ballito',
            'created_at' => $lastActivity->toDateTimeString(), 'updated_at' => $lastActivity->toDateTimeString(),
        ]);
        return Property::withoutGlobalScopes()->findOrFail($id);
    }

    private function seedAgencyUser(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent', 'is_active' => 1,
        ]);
        return [$agencyId, $user];
    }
}
