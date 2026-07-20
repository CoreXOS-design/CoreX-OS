<?php

namespace Tests\Feature\Syndication;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Notifications\MandateNeedsResyndicationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-68 — RE-LIST is the agent's task, not automatic. When the agent extends a
 * previously-EXPIRED listing's expiry_date into the future and saves, CoreX
 * REMINDS the listing agent to re-syndicate (it does NOT auto-relist). The reminder
 * fires only on a genuine renewal: was-expired + date-moved-to-today-or-later.
 */
class MandateRenewalReminderTest extends TestCase
{
    use RefreshDatabase;

    private function makeProperty(string $status, ?string $expiry): array
    {
        Queue::fake(); // isolate observer-dispatched jobs
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        // NOT syndicated → no off-market delist dispatch noise on create.
        $p = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => $status, 'price' => 1500000,
            'expiry_date' => $expiry,
        ]);

        return [$agent, $p];
    }

    public function test_expired_listing_extended_into_future_reminds_the_agent(): void
    {
        [$agent, $p] = $this->makeProperty('expired', Carbon::today()->subDays(10)->toDateString());
        Notification::fake();

        // Renewal: agent pushes the expiry date 30 days out and saves.
        $p->update(['expiry_date' => Carbon::today()->addDays(30)->toDateString()]);

        Notification::assertSentTo($agent, MandateNeedsResyndicationNotification::class);
    }

    public function test_no_reminder_when_property_was_not_expired(): void
    {
        [$agent, $p] = $this->makeProperty('active', Carbon::today()->addDays(5)->toDateString());
        Notification::fake();

        // Active listing simply gets its date extended — nothing to re-list.
        $p->update(['expiry_date' => Carbon::today()->addDays(60)->toDateString()]);

        Notification::assertNothingSentTo($agent);
    }

    public function test_no_reminder_when_new_date_is_still_in_the_past(): void
    {
        [$agent, $p] = $this->makeProperty('expired', Carbon::today()->subDays(10)->toDateString());
        Notification::fake();

        // Date "changed" but still expired (e.g. a correction to another past date) —
        // the listing is still lapsed, so there is nothing to re-syndicate yet.
        $p->update(['expiry_date' => Carbon::today()->subDays(3)->toDateString()]);

        Notification::assertNothingSentTo($agent);
    }

    public function test_no_reminder_and_no_autorelist_side_effect(): void
    {
        // Guard: the reminder path must never itself put the listing back on a portal.
        [$agent, $p] = $this->makeProperty('expired', Carbon::today()->subDays(10)->toDateString());
        Notification::fake();

        $p->update(['expiry_date' => Carbon::today()->addDays(30)->toDateString()]);

        // Status stays 'expired' (re-listing is the agent's explicit action, not this save).
        $this->assertSame('expired', $p->fresh()->status);
        Notification::assertSentTo($agent, MandateNeedsResyndicationNotification::class);
    }
}
