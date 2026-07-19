<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentPermission;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\CommandCenter\CalendarEventService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-267 §7.2 — an assistant works AS the agent: calendar events and daily-activity entries they
 * create land on the AGENT (show on the agent's day as if the agent made them), while the actor
 * is still the assistant. `ownershipUserId()` is the agent for an assistant and self for everyone
 * else, so a normal user is unaffected.
 */
final class AssistantActsForAgentTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private User $assistant;
    private AssistantAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid(), 'assistants_enabled' => true]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        Role::create(['name' => 'assistant', 'label' => 'Assistant', 'agency_id' => $this->agency->id]);

        $this->agent     = $this->makeUser('Sarah Nkosi', 'agent');
        $this->assistant = $this->makeUser('Thandi Mokoena', 'assistant', isAssistant: true);

        $this->assignment = AssistantAssignment::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'assistant_user_id' => $this->assistant->id, 'agent_user_id' => $this->agent->id,
            'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);

        foreach (['access_daily_activity', 'daily_activity.view'] as $key) {
            RolePermission::create(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id, 'scope' => 'all']);
            AssistantAssignmentPermission::create([
                'assistant_assignment_id' => $this->assignment->id, 'permission_key' => $key,
                'agency_id' => $this->agency->id, 'granted' => true,
            ]);
        }

        $this->reset();
    }

    public function test_a_calendar_event_an_assistant_creates_is_owned_by_the_agent(): void
    {
        $service = app(CalendarEventService::class);

        $event = $service->createManual(
            ['title' => 'Buyer viewing — 12 Ocean Dr', 'event_date' => now()->addDay(), 'category' => 'meeting'],
            $this->assistant,
        );

        $this->assertSame((int) $this->agent->id, (int) $event->user_id,
            'the event is OWNED by the agent — it shows on their calendar as their own');
        $this->assertSame((int) $this->assistant->id, (int) $event->created_by_id,
            'the assistant is recorded as the actual creator');

        // The assistant can see it on the agent's calendar (own scope → dataIdentityIds).
        $this->assertTrue(
            CalendarEvent::query()->visibleTo($this->assistant, 'own')->whereKey($event->id)->exists(),
            'the assistant must see the event they added to the agent\'s calendar'
        );

        // A normal agent is unaffected — their event is their own.
        $own = $service->createManual(['title' => 'x', 'event_date' => now(), 'category' => 'meeting'], $this->agent);
        $this->assertSame((int) $this->agent->id, (int) $own->user_id);
    }

    public function test_a_daily_activity_an_assistant_logs_lands_on_the_agent(): void
    {
        $defId = DB::table('activity_definitions')->insertGetId([
            'name' => 'Calls made', 'scope' => 'system', 'is_enabled' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->assistant)->post('/agent/daily', [
            'activity_date' => now()->toDateString(),
            'values'        => [$defId => 3],
            'baseline'      => [$defId => 0],
        ])->assertRedirect();

        $entry = DB::table('daily_activity_entries')
            ->where('activity_definition_id', $defId)
            ->where('activity_date', now()->toDateString())
            ->first();

        $this->assertNotNull($entry, 'the daily activity entry was written');
        $this->assertSame((int) $this->agent->id, (int) $entry->user_id,
            'the daily activity lands on the AGENT, not the assistant — it counts as the agent\'s work');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeUser(string $name, string $role, bool $isAssistant = false): User
    {
        return User::factory()->create([
            'name' => $name, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'role' => $role, 'is_active' => true, 'is_assistant' => $isAssistant,
        ]);
    }

    private function reset(): void
    {
        PermissionService::clearCache();
        Role::clearCache();
        User::flushAssistantsEnabledCache();
        PermissionService::forceProductionPosture();
    }
}
