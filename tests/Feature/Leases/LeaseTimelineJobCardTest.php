<?php

namespace Tests\Feature\Leases;

use App\Models\RentalJobCard;
use App\Models\RentalWorkOrderPhoto;
use App\Services\Rentals\LeaseTimelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRentalPortalFixtures;
use Tests\TestCase;

/**
 * rental-work-orders.md §14.29 — job cards as a source in the tenancy log
 * (LeaseTimelineService) and the Job cards panel on the Lease Hub.
 *
 * Input paths proven: opened / photos added (crew vs office, grouped per card
 * per day) / work completed (with and without a name, each `via`) / card
 * completed; lease scoping (a previous tenant's card never appears); the
 * `job_card` type filter; newest-first ordering; archived card drops out; a
 * lease with no cards has an empty log; the hub panel renders status + photos
 * for a user who may see job cards, is absent when there are none, and is
 * absent for a user whose job-card scope is 'own' and who did not create it.
 */
class LeaseTimelineJobCardTest extends TestCase
{
    use BuildsRentalPortalFixtures;
    use RefreshDatabase;

    private function scenario(): array
    {
        $agency = $this->makeAgency();
        $agent = $this->makeAgent($agency);
        $property = $this->makeProperty($agency, $agent);
        $lease = $this->makeLease($agency, $property);

        return [$agency, $agent, $property, $lease];
    }

    private function descriptions(\App\Models\Lease $lease, array $types = []): array
    {
        return app(LeaseTimelineService::class)->paginatedFor($lease->fresh(), null, $types)['entries']->pluck('description')->all();
    }

    public function test_job_card_is_a_declared_timeline_type(): void
    {
        $this->assertContains('job_card', LeaseTimelineService::TYPES);
    }

    public function test_opened_entry_appears_with_the_creator_and_a_link_to_the_card(): void
    {
        [$agency, $agent, $property, $lease] = $this->scenario();
        $card = $this->makeJobCard($agency, $property, $lease, ['title' => 'Fix gate motor', 'created_by_user_id' => $agent->id]);

        $entries = app(LeaseTimelineService::class)->allEntriesFor($lease->fresh());
        $entry = $entries->firstWhere('description', 'Job card opened: Fix gate motor');

        $this->assertNotNull($entry);
        $this->assertSame('job_card', $entry['type']);
        $this->assertSame($agent->name, $entry['actor']);
        $this->assertSame('corex.rental-job-cards.show', $entry['route_name']);
        $this->assertSame($card->id, $entry['route_param']);
    }

    public function test_crew_photos_and_office_photos_are_grouped_per_card_per_day_with_a_count(): void
    {
        [$agency, $agent, $property, $lease] = $this->scenario();
        $card = $this->makeJobCard($agency, $property, $lease, ['title' => 'Repaint lounge']);
        $this->makePhoto($card, 'in_progress');
        $this->makePhoto($card, 'in_progress');
        $this->makePhoto($card, 'completed');
        $this->makePhoto($card, 'reported', $agent);

        $d = $this->descriptions($lease);

        $this->assertContains('Crew photos added (3): Repaint lounge', $d);
        $this->assertContains('Photos added (1): Repaint lounge', $d);
    }

    public function test_photos_on_different_days_are_separate_entries(): void
    {
        [$agency, , $property, $lease] = $this->scenario();
        $card = $this->makeJobCard($agency, $property, $lease, ['title' => 'Tiling']);
        $this->makePhoto($card, 'in_progress');
        $old = $this->makePhoto($card, 'in_progress');
        RentalWorkOrderPhoto::withoutGlobalScopes()->whereKey($old->id)->update(['created_at' => now()->subDays(3)]);

        $d = $this->descriptions($lease);

        $this->assertSame(2, count(array_filter($d, fn ($x) => str_starts_with($x, 'Crew photos added (1): Tiling'))));
    }

    public function test_work_completed_entry_says_who_signed_and_how(): void
    {
        [$agency, , $property, $lease] = $this->scenario();
        $this->makeJobCard($agency, $property, $lease, [
            'title' => 'Named job', 'worker_signed_off_at' => now(), 'worker_sign_off_name' => 'Sipho Dlamini',
        ]);
        $this->makeJobCard($agency, $property, $lease, ['title' => 'No name job', 'worker_signed_off_at' => now()]);

        $d = implode(' | ', $this->descriptions($lease));

        // A row with no `via` (every card signed off before the crew-link build) reads with no suffix — never an error.
        $this->assertStringContainsString('Work completed — signed by Sipho Dlamini', $d);
        $this->assertStringContainsString('Work completed — signed by the crew: No name job', $d);
    }

    public function test_work_completed_names_the_signing_route_when_the_via_column_exists(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('rental_job_cards', 'worker_sign_off_via')) {
            $this->markTestSkipped('worker_sign_off_via arrives with the crew-link build (§14.28).');
        }

        [$agency, , $property, $lease] = $this->scenario();
        foreach (['crew_link' => 'via link', 'signed_copy' => 'via signed copy', 'crew_page' => 'via crew page'] as $via => $label) {
            $this->makeJobCard($agency, $property, $lease, [
                'title' => 'Job ' . $via, 'worker_signed_off_at' => now(), 'worker_sign_off_name' => 'Sipho Dlamini', 'worker_sign_off_via' => $via,
            ]);
            $this->assertStringContainsString("Work completed — signed by Sipho Dlamini {$label}: Job {$via}", implode(' | ', $this->descriptions($lease)));
        }
    }

    public function test_card_completed_entry_appears(): void
    {
        [$agency, , $property, $lease] = $this->scenario();
        $this->makeJobCard($agency, $property, $lease, ['title' => 'Replace lock', 'status' => RentalJobCard::STATUS_COMPLETED, 'completed_at' => now()]);

        $this->assertContains('Job card completed: Replace lock', $this->descriptions($lease));
    }

    public function test_a_previous_tenants_card_never_appears_on_the_current_lease(): void
    {
        [$agency, , $property, $lease] = $this->scenario();
        $oldLease = $this->makeLease($agency, $property);
        $this->makeJobCard($agency, $property, $oldLease, ['title' => 'Previous tenant geyser']);
        $this->makeJobCard($agency, $property, $lease, ['title' => 'Current tenant tap']);

        $d = implode(' | ', $this->descriptions($lease));

        $this->assertStringContainsString('Current tenant tap', $d);
        $this->assertStringNotContainsString('Previous tenant geyser', $d);
    }

    public function test_the_job_card_type_filter_narrows_the_log(): void
    {
        [$agency, , $property, $lease] = $this->scenario();
        $this->makeJobCard($agency, $property, $lease, ['title' => 'Fix hinge']);
        $this->makeWorkOrder($agency, $property, $lease, ['title' => 'Unrelated order']);

        $only = $this->descriptions($lease, ['job_card']);

        $this->assertNotEmpty($only);
        foreach ($only as $d) {
            $this->assertStringNotContainsString('Unrelated order', $d);
        }
        $this->assertStringContainsString('Unrelated order', implode(' | ', $this->descriptions($lease)));
    }

    public function test_entries_are_newest_first(): void
    {
        [$agency, , $property, $lease] = $this->scenario();
        $older = $this->makeJobCard($agency, $property, $lease, ['title' => 'Older job']);
        $newer = $this->makeJobCard($agency, $property, $lease, ['title' => 'Newer job']);
        // created_at is not mass-assignable — set the moments directly.
        RentalJobCard::withoutGlobalScopes()->whereKey($older->id)->update(['created_at' => now()->subDays(9)]);
        RentalJobCard::withoutGlobalScopes()->whereKey($newer->id)->update(['created_at' => now()->subDay()]);

        $d = $this->descriptions($lease);

        $this->assertLessThan(array_search('Job card opened: Older job', $d, true), array_search('Job card opened: Newer job', $d, true));
    }

    public function test_an_archived_card_drops_out_and_a_lease_with_no_cards_has_no_job_card_entries(): void
    {
        [$agency, , $property, $lease] = $this->scenario();
        $this->assertSame([], $this->descriptions($lease, ['job_card']));

        $card = $this->makeJobCard($agency, $property, $lease, ['title' => 'Soon archived']);
        $card->delete();

        $this->assertSame([], $this->descriptions($lease, ['job_card']));
    }

    public function test_lease_hub_shows_the_job_cards_panel_with_status_and_photos(): void
    {
        [$agency, $agent, $property, $lease] = $this->scenario();
        $card = $this->makeJobCard($agency, $property, $lease, ['title' => 'Replace geyser element', 'status' => RentalJobCard::STATUS_IN_PROGRESS]);
        $photo = $this->makePhoto($card, 'in_progress');

        $resp = $this->actingAs($agent)->get(route('corex.leases.show', $lease));

        $resp->assertOk();
        $resp->assertSee('id="lease-job-cards"', false);
        $resp->assertSee('Replace geyser element');
        $resp->assertSee('In progress');
        $resp->assertSee($photo->storage_path, false);
        // The new "Job card" tenancy-log filter checkbox.
        $resp->assertSee('value="job_card"', false);
    }

    public function test_lease_hub_has_no_job_cards_panel_when_the_tenancy_has_none(): void
    {
        [, $agent, , $lease] = $this->scenario();

        $this->actingAs($agent)->get(route('corex.leases.show', $lease))
            ->assertOk()->assertDontSee('id="lease-job-cards"', false);
    }

    public function test_lease_hub_panel_does_not_list_another_leases_card_or_an_archived_one(): void
    {
        [$agency, $agent, $property, $lease] = $this->scenario();
        $other = $this->makeLease($agency, $property);
        $this->makeJobCard($agency, $property, $other, ['title' => 'Belongs to old lease']);
        $this->makeJobCard($agency, $property, $lease, ['title' => 'Archived one'])->delete();
        $this->makeJobCard($agency, $property, $lease, ['title' => 'Visible one']);

        $resp = $this->actingAs($agent)->get(route('corex.leases.show', $lease));

        $resp->assertSee('Visible one');
        $resp->assertDontSee('Belongs to old lease');
        $resp->assertDontSee('Archived one');
    }

    public function test_lease_hub_panel_respects_the_viewers_own_job_card_scope(): void
    {
        [$agency, $creator, $property, $lease] = $this->scenario();
        \App\Models\Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $agency->id]);
        foreach (['leases.view' => 'all', 'rental_job_cards.view' => 'own'] as $key => $scope) {
            \App\Models\RolePermission::updateOrCreate(
                ['role' => 'agent', 'permission_key' => $key, 'agency_id' => $agency->id],
                ['scope' => $scope],
            );
        }
        \App\Services\PermissionService::clearCache();
        $viewer = $this->makeAgent($agency, 'agent');
        $others = $this->makeJobCard($agency, $property, $lease, ['title' => 'Created by someone else', 'created_by_user_id' => $creator->id]);
        $mine = $this->makeJobCard($agency, $property, $lease, ['title' => 'Created by the viewer', 'created_by_user_id' => $viewer->id]);

        $resp = $this->actingAs($viewer)->get(route('corex.leases.show', $lease));

        // The PANEL honours the viewer's own job-card scope (the lease-scoped tenancy log
        // below it lists every source on the tenancy, exactly as it already does for faults and work orders).
        $resp->assertOk();
        $resp->assertSee('data-job-card-row="' . $mine->id . '"', false);
        $resp->assertDontSee('data-job-card-row="' . $others->id . '"', false);
    }
}
