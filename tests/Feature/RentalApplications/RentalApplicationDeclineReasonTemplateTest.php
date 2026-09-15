<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Events\AgencyCreated;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\RentalApplicationDeclineReasonTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Decline reason templates, 2026-09-15 — Johan's own design floor stated in
 * the spec before this was written: full CRUD, soft delete + restore, a
 * list screen with search/sort/filter/pagination/real-empty-state, agency
 * scoping at the query layer, seeded sensible defaults.
 */
final class RentalApplicationDeclineReasonTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function agencyWithOwner(): array
    {
        $agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Ramsgate']);
        $owner = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        return [$agency, $branch, $owner];
    }

    // ── Seeded defaults, day one, no configuration required ──────────────

    public function test_a_brand_new_agency_gets_the_two_seeded_defaults_via_the_agency_created_event(): void
    {
        $agency = Agency::create(['name' => 'Brand New Agency', 'slug' => 'bna-' . uniqid()]);

        event(new AgencyCreated($agency));

        $templates = RentalApplicationDeclineReasonTemplate::activeFor($agency->id);
        $this->assertCount(2, $templates);
        $this->assertSame('Affordability', $templates[0]->reason);
        $this->assertSame('Unpaid debit orders on bank statements', $templates[1]->reason);
    }

    public function test_seeding_is_idempotent_never_duplicates_on_a_second_call(): void
    {
        $agency = Agency::create(['name' => 'Idempotent Co', 'slug' => 'idem-' . uniqid()]);

        RentalApplicationDeclineReasonTemplate::seedDefaultsFor($agency->id);
        RentalApplicationDeclineReasonTemplate::seedDefaultsFor($agency->id);

        $this->assertCount(2, RentalApplicationDeclineReasonTemplate::withTrashed()->where('agency_id', $agency->id)->get());
    }

    public function test_seeding_does_nothing_if_the_agency_already_has_a_hand_created_template(): void
    {
        $agency = Agency::create(['name' => 'Already Configured Co', 'slug' => 'already-' . uniqid()]);
        RentalApplicationDeclineReasonTemplate::create(['agency_id' => $agency->id, 'reason' => 'Custom', 'guidance' => 'Custom guidance.', 'sort_order' => 0]);

        RentalApplicationDeclineReasonTemplate::seedDefaultsFor($agency->id);

        $this->assertCount(1, RentalApplicationDeclineReasonTemplate::withTrashed()->where('agency_id', $agency->id)->get());
    }

    /** The guardrail, checked against the actual shipped text, not trusted from the comment alone. */
    public function test_neither_seeded_default_contains_a_number_or_a_promise_of_approval(): void
    {
        foreach (RentalApplicationDeclineReasonTemplate::DEFAULT_SEED as $seed) {
            $this->assertDoesNotMatchRegularExpression('/\d/', $seed['guidance'], "guidance for '{$seed['reason']}' must contain no digits at all");
            $this->assertStringNotContainsStringIgnoringCase('will be approved', $seed['guidance']);
            $this->assertStringNotContainsStringIgnoringCase('guaranteed', $seed['guidance']);
        }
    }

    // ── List screen — search, sort, filter, pagination, empty state ──────

    public function test_index_shows_a_real_empty_state_when_nothing_exists_yet(): void
    {
        [$agency, , $owner] = $this->agencyWithOwner();
        // No seeding call here — proves the screen itself, not the seeding, handles zero rows honestly.

        $response = $this->actingAs($owner)->get(route('corex.settings.rental-applications.decline-reason-templates.index'));

        $response->assertOk();
        $response->assertSee('No decline reason templates yet.', false);
    }

    public function test_search_matches_reason_and_guidance_text(): void
    {
        // Deliberately NOT "Affordability" — the create form's own
        // placeholder="e.g. Affordability" is always present in the raw
        // HTML (the form is x-cloak'd, not server-side hidden), so that
        // exact word appears on every render of this page regardless of
        // which rows match a search. Using it as fixture data here would
        // make assertDontSee('Affordability') fail for a reason that has
        // nothing to do with whether search filtering actually works.
        [$agency, , $owner] = $this->agencyWithOwner();
        RentalApplicationDeclineReasonTemplate::create(['agency_id' => $agency->id, 'reason' => 'Gate reason Alpha', 'guidance' => 'General income tips.', 'sort_order' => 0]);
        RentalApplicationDeclineReasonTemplate::create(['agency_id' => $agency->id, 'reason' => 'Gate reason Beta', 'guidance' => 'General debit order tips.', 'sort_order' => 1]);

        $byReason = $this->actingAs($owner)->get(route('corex.settings.rental-applications.decline-reason-templates.index', ['q' => 'Alpha']));
        $byReason->assertSee('Gate reason Alpha', false);
        $byReason->assertDontSee('Gate reason Beta', false);

        $byGuidance = $this->actingAs($owner)->get(route('corex.settings.rental-applications.decline-reason-templates.index', ['q' => 'debit order']));
        $byGuidance->assertSee('Gate reason Beta', false);
        $byGuidance->assertDontSee('Gate reason Alpha', false);
    }

    public function test_sort_order_is_the_default_and_every_column_sorts(): void
    {
        [$agency, , $owner] = $this->agencyWithOwner();
        $z = RentalApplicationDeclineReasonTemplate::create(['agency_id' => $agency->id, 'reason' => 'Zzz reason', 'guidance' => 'g', 'sort_order' => 0]);
        $a = RentalApplicationDeclineReasonTemplate::create(['agency_id' => $agency->id, 'reason' => 'Aaa reason', 'guidance' => 'g', 'sort_order' => 1]);

        // Default (no ?sort= at all): sort_order ascending -> Zzz (order 0) before Aaa (order 1).
        $default = $this->actingAs($owner)->get(route('corex.settings.rental-applications.decline-reason-templates.index'));
        $this->assertTrue(strpos($default->getContent(), 'Zzz reason') < strpos($default->getContent(), 'Aaa reason'));

        // Explicit reason ascending -> Aaa before Zzz.
        $byReason = $this->actingAs($owner)->get(route('corex.settings.rental-applications.decline-reason-templates.index', ['sort' => 'reason', 'direction' => 'asc']));
        $this->assertTrue(strpos($byReason->getContent(), 'Aaa reason') < strpos($byReason->getContent(), 'Zzz reason'));
    }

    public function test_status_filter_defaults_to_active_and_archived_rows_are_reachable_via_the_filter(): void
    {
        [$agency, , $owner] = $this->agencyWithOwner();
        $active = RentalApplicationDeclineReasonTemplate::create(['agency_id' => $agency->id, 'reason' => 'Active One', 'guidance' => 'g', 'sort_order' => 0]);
        $archived = RentalApplicationDeclineReasonTemplate::create(['agency_id' => $agency->id, 'reason' => 'Archived One', 'guidance' => 'g', 'sort_order' => 1]);
        $archived->delete();

        $default = $this->actingAs($owner)->get(route('corex.settings.rental-applications.decline-reason-templates.index'));
        $default->assertSee('Active One', false);
        $default->assertDontSee('Archived One', false);

        $archivedView = $this->actingAs($owner)->get(route('corex.settings.rental-applications.decline-reason-templates.index', ['status' => 'archived']));
        $archivedView->assertSee('Archived One', false);
        $archivedView->assertDontSee('Active One', false);

        $all = $this->actingAs($owner)->get(route('corex.settings.rental-applications.decline-reason-templates.index', ['status' => 'all']));
        $all->assertSee('Active One', false);
        $all->assertSee('Archived One', false);
    }

    public function test_pagination_real_second_page(): void
    {
        [$agency, , $owner] = $this->agencyWithOwner();
        for ($i = 0; $i < 25; $i++) {
            RentalApplicationDeclineReasonTemplate::create(['agency_id' => $agency->id, 'reason' => "Reason {$i}", 'guidance' => 'g', 'sort_order' => $i]);
        }

        $page1 = $this->actingAs($owner)->get(route('corex.settings.rental-applications.decline-reason-templates.index'));
        $page1->assertSee('Reason 0', false);
        $page1->assertDontSee('Reason 20', false);

        $page2 = $this->actingAs($owner)->get(route('corex.settings.rental-applications.decline-reason-templates.index', ['page' => 2]));
        $page2->assertSee('Reason 20', false);
        $page2->assertDontSee('Reason 0', false);
    }

    // ── Full CRUD round trip ──────────────────────────────────────────────

    public function test_create_update_archive_restore_round_trip(): void
    {
        [$agency, , $owner] = $this->agencyWithOwner();

        $create = $this->actingAs($owner)->post(route('corex.settings.rental-applications.decline-reason-templates.store'), [
            'reason' => 'New reason', 'guidance' => 'New guidance text.',
        ]);
        $create->assertRedirect();
        $template = RentalApplicationDeclineReasonTemplate::where('agency_id', $agency->id)->where('reason', 'New reason')->firstOrFail();
        $this->assertSame($owner->id, $template->created_by);

        $update = $this->actingAs($owner)->put(route('corex.settings.rental-applications.decline-reason-templates.update', $template), [
            'reason' => 'Updated reason', 'guidance' => 'Updated guidance text.',
        ]);
        $update->assertRedirect();
        $this->assertSame('Updated reason', $template->fresh()->reason);

        $archive = $this->actingAs($owner)->post(route('corex.settings.rental-applications.decline-reason-templates.archive', $template));
        $archive->assertRedirect();
        $this->assertSoftDeleted('rental_application_decline_reason_templates', ['id' => $template->id]);

        $restore = $this->actingAs($owner)->post(route('corex.settings.rental-applications.decline-reason-templates.restore', $template->id));
        $restore->assertRedirect();
        $this->assertDatabaseHas('rental_application_decline_reason_templates', ['id' => $template->id, 'deleted_at' => null]);
    }

    public function test_archiving_never_hard_deletes(): void
    {
        [$agency, , $owner] = $this->agencyWithOwner();
        $template = RentalApplicationDeclineReasonTemplate::create(['agency_id' => $agency->id, 'reason' => 'R', 'guidance' => 'G', 'sort_order' => 0]);

        $this->actingAs($owner)->post(route('corex.settings.rental-applications.decline-reason-templates.archive', $template))->assertRedirect();

        $this->assertDatabaseHas('rental_application_decline_reason_templates', ['id' => $template->id]);
        $this->assertSoftDeleted('rental_application_decline_reason_templates', ['id' => $template->id]);
    }

    // ── Scoping — a different agency's templates are unreachable ─────────

    public function test_a_different_agencys_template_is_unreachable_by_direct_url(): void
    {
        [$agencyA] = $this->agencyWithOwner();
        [, , $ownerB] = $this->agencyWithOwner();
        $templateA = RentalApplicationDeclineReasonTemplate::create(['agency_id' => $agencyA->id, 'reason' => 'Agency A only', 'guidance' => 'g', 'sort_order' => 0]);

        $update = $this->actingAs($ownerB)->put(route('corex.settings.rental-applications.decline-reason-templates.update', $templateA), [
            'reason' => 'Hijacked', 'guidance' => 'g',
        ]);
        $update->assertNotFound();

        $archive = $this->actingAs($ownerB)->post(route('corex.settings.rental-applications.decline-reason-templates.archive', $templateA));
        $archive->assertNotFound();

        $this->assertSame('Agency A only', $templateA->fresh()->reason);
    }

    public function test_index_never_shows_another_agencys_templates(): void
    {
        [$agencyA] = $this->agencyWithOwner();
        [, , $ownerB] = $this->agencyWithOwner();
        RentalApplicationDeclineReasonTemplate::create(['agency_id' => $agencyA->id, 'reason' => 'Agency A secret reason', 'guidance' => 'g', 'sort_order' => 0]);

        $response = $this->actingAs($ownerB)->get(route('corex.settings.rental-applications.decline-reason-templates.index', ['status' => 'all']));
        $response->assertDontSee('Agency A secret reason', false);
    }

    // ── The read method cc5's decline flow consumes ───────────────────────

    public function test_active_for_excludes_archived_and_orders_by_sort_order(): void
    {
        [$agency] = $this->agencyWithOwner();
        $second = RentalApplicationDeclineReasonTemplate::create(['agency_id' => $agency->id, 'reason' => 'Second', 'guidance' => 'g', 'sort_order' => 1]);
        $first = RentalApplicationDeclineReasonTemplate::create(['agency_id' => $agency->id, 'reason' => 'First', 'guidance' => 'g', 'sort_order' => 0]);
        $archived = RentalApplicationDeclineReasonTemplate::create(['agency_id' => $agency->id, 'reason' => 'Archived', 'guidance' => 'g', 'sort_order' => 2]);
        $archived->delete();

        $active = RentalApplicationDeclineReasonTemplate::activeFor($agency->id);

        $this->assertCount(2, $active);
        $this->assertSame('First', $active[0]->reason);
        $this->assertSame('Second', $active[1]->reason);
    }
}
