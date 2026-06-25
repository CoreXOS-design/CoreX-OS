<?php

namespace Tests\Feature\Admin;

use App\Jobs\SyncAgentToP24Job;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncAgentToP24Test extends TestCase
{
    use RefreshDatabase;

    /**
     * The "Sync to P24" button must hand the work to the queue and return
     * immediately — never run the blocking P24 round-trips (getAgents +
     * create/update + photo download/upload) inline, which hung the page.
     */
    public function test_sync_button_queues_the_job_and_returns_immediately(): void
    {
        Queue::fake();

        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        $admin = User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role'      => 'super_admin',
        ]);
        $agent = User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role'      => 'agent',
        ]);

        $resp = $this->actingAs($admin)->post(route('admin.users.sync-p24', $agent));

        $resp->assertSessionHasNoErrors();
        $resp->assertSessionHas('status');

        Queue::assertPushed(SyncAgentToP24Job::class, function (SyncAgentToP24Job $job) use ($agent) {
            return $job->userId === $agent->id && $job->registerIfMissing === true;
        });
    }
}
