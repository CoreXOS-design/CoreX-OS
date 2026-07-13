<?php

namespace Tests\Feature\Calendar;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-241 — buyer-pipeline "Schedule Viewing" appointment save.
 * Differential: works as an agency-bound agent (Cindy), fails as a super user
 * with a null agency context. Both cases below POST the same buyer-viewing
 * payload to command-center.calendar.store.
 */
class BuyerPipelineAppointmentTest extends TestCase
{
    use RefreshDatabase;

    private function payload(int $buyerId): array
    {
        return [
            'title'      => 'Viewing with Test Buyer',
            'category'   => 'viewing',
            'event_date' => '2026-07-20 14:00:00',
            'end_date'   => '2026-07-20 15:00:00',
            'attendees'  => [
                ['id' => $buyerId, 'type' => 'contact', 'role' => 'buyer_contact'],
            ],
        ];
    }

    /** CONTROL — agency-bound agent (the "Cindy works" case). */
    public function test_agency_agent_creates_and_links(): void
    {
        $agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc']);
        $user   = User::factory()->create(['agency_id' => $agency->id, 'role' => 'agent']);

        $resp = $this->actingAs($user)->post(route('command-center.calendar.store'), $this->payload(987654));

        $resp->assertSessionHasNoErrors();
        $resp->assertStatus(302);
        $this->assertDatabaseHas('calendar_events', ['title' => 'Viewing with Test Buyer']);
        $this->assertDatabaseHas('calendar_event_links', ['role' => 'buyer_contact', 'linkable_id' => 987654]);
    }

    /** REPRO — super user, null agency, STORE (the "super user fails" case). */
    public function test_super_user_null_agency_creates_and_links(): void
    {
        $this->withoutExceptionHandling(); // surface the raw exception instead of a 500 page
        Agency::create(['name' => 'HFC', 'slug' => 'hfc']); // agency id=1 exists for the ?:1 fallback
        $super = User::factory()->create(['agency_id' => null, 'role' => 'super_admin']);

        $resp = $this->actingAs($super)->post(route('command-center.calendar.store'), $this->payload(987654));

        $resp->assertStatus(302);
        $this->assertDatabaseHas('calendar_events', ['title' => 'Viewing with Test Buyer']);
        $this->assertDatabaseHas('calendar_event_links', ['role' => 'buyer_contact', 'linkable_id' => 987654]);
    }

    /** REPRO 2 — MULTI-AGENCY box (like staging), super user null agency, STORE. */
    public function test_super_user_null_agency_multi_agency_store(): void
    {
        $this->withoutExceptionHandling();
        Agency::create(['name' => 'HFC', 'slug' => 'hfc']);       // id 1
        Agency::create(['name' => 'Demo', 'slug' => 'demo']);     // id 2 — defeats single-agency fallback
        $super = User::factory()->create(['agency_id' => null, 'role' => 'super_admin']);

        $resp = $this->actingAs($super)->post(route('command-center.calendar.store'), $this->payload(987654));
        $resp->assertStatus(302);
        $this->assertDatabaseHas('calendar_events', ['title' => 'Viewing with Test Buyer']);
    }

    /**
     * LATENT class defect (NOT Johan's staging case): the calendar-index forAgency
     * callers use `$user->effectiveAgencyId() ?? 1`, which for a null-agency super
     * user resolves to forAgency(1) → firstOrCreate → FK-1452 WHEN agency id 1 does
     * not exist (single-agency installs where the one agency isn't id 1, or a fresh
     * install). Staging has agency 1 (HFC) + a settings row, so it does NOT fire
     * there — this test documents the fragility, not the reported failure.
     * (Safe pattern, used at CalendarController:465/1384, is `?: 0` → the <=0 guard.)
     */
}
