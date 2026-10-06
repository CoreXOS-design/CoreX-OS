<?php

declare(strict_types=1);

namespace Tests\Feature\RentalJobCards;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalCatalogueItem;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use App\Services\Property\ContactPropertyLinker;
use App\Services\Rentals\RentalJobCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * AT-442 job card QA1-walk follow-up fixes — .ai/specs/rental-work-orders.md §14.
 *
 * #1/#3 — lease_id WINS and derives the property on both the work-order and
 * the job-card create/store paths; a mismatched lease_id/property_id pair
 * can never attach a job card/work order to another property's lease.
 * #2 — the create screen's searchable property picker is scoped and finds
 * a property beyond any old hard cap.
 * #5 — a free-text line can choose Labour or Part; a catalogue item keeps
 * its own type regardless of what was posted alongside it.
 * #6 — the job card show screen is handed the property's no-approval
 * spend threshold.
 */
final class RentalJobCardAt442FollowUpTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private Property $property;
    private Property $otherProperty;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agency = Agency::create(['name' => 'AT442 Follow-up Agency', 'slug' => 'at442-followup-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Uvongo', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '1 Kenmuir Road', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->otherProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => 'Another property entirely', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        // Agency::create() does not fire AgencyCreated in tests — seed
        // explicitly, same convention every other per-agency list's tests use.
        RentalCatalogueItemType::seedDefaultsFor($this->agency->id);
        RentalCatalogueUnit::seedDefaultsFor($this->agency->id);
    }

    private function lease(Property $property, array $attrs = []): Lease
    {
        return Lease::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now()->subDays(10),
            'created_by_user_id' => $this->admin->id,
        ], $attrs));
    }

    // ── #1/#3 — lease_id wins and derives the property ──────────────────

    public function test_create_screen_derives_property_from_lease_id_and_ignores_a_mismatched_property_param(): void
    {
        $ownLease = $this->lease($this->property);
        $otherLease = $this->lease($this->otherProperty);

        // Johan's exact QA1 repro: ?lease_id=<a lease on a DIFFERENT property>.
        $response = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.create', ['lease_id' => $otherLease->id]));

        $response->assertOk();
        $response->assertSee($this->otherProperty->buildDisplayAddress(), false);
        $response->assertDontSee($this->property->buildDisplayAddress(), false);
        // own lease untouched by this request
        $this->assertSame($this->property->id, $ownLease->property_id);
    }

    /**
     * Updated 2026-10-05 after merging origin/QA1 (7cd2848d5) — the Staging
     * audit (L2) independently added `Rule::exists('leases','id')->where
     * ('property_id', $propertyId)` to THIS controller's store() validation,
     * which now REJECTS a mismatched lease/property pair outright (422/
     * redirect-with-errors) before my own "lease wins, correct and proceed"
     * block ever runs — a stricter guard than mine, same goal. My block is
     * harmless dead code here now (kept per instruction — see the spec note
     * on this merge); RentalJobCardController::store() has no such
     * validation rule, so the identical guard there (tested below) is still
     * the one actually doing the work.
     */
    public function test_store_rejects_a_property_id_that_does_not_match_the_posted_lease(): void
    {
        $otherLease = $this->lease($this->otherProperty);

        // The exact corruption from the QA1 walk: lease_id resolves to a
        // DIFFERENT property than the property_id also posted.
        $response = $this->actingAs($this->admin)->post(route('corex.rental-work-orders.store'), [
            'property_id' => $this->property->id,
            'lease_id' => $otherLease->id,
            'assignment_type' => 'internal',
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED,
            'title' => 'ZZ TEST job card walk - leaking tap',
            'description' => 'Leaking tap.',
        ]);

        $response->assertSessionHasErrors('lease_id');
        $this->assertNull(\App\Models\RentalJobCard::firstWhere('title', 'ZZ TEST job card walk - leaking tap'));
    }

    public function test_job_card_controller_store_applies_the_same_lease_wins_guard(): void
    {
        $otherLease = $this->lease($this->otherProperty);

        $response = $this->actingAs($this->admin)->post(route('corex.rental-job-cards.store'), [
            'property_id' => $this->property->id,
            'lease_id' => $otherLease->id,
            'title' => 'Fix the geyser',
        ]);

        $response->assertRedirect();
        $jobCard = \App\Models\RentalJobCard::firstWhere('title', 'Fix the geyser');
        $this->assertNotNull($jobCard);
        $this->assertSame($this->otherProperty->id, $jobCard->property_id);
        $this->assertSame($otherLease->id, $jobCard->lease_id);
    }

    public function test_a_lease_outside_the_users_scope_does_not_leak_into_the_create_screen(): void
    {
        $foreignAgency = Agency::create(['name' => 'Foreign', 'slug' => 'foreign-' . uniqid()]);
        $foreignBranch = Branch::forceCreate(['name' => 'Elsewhere', 'agency_id' => $foreignAgency->id]);
        $foreignAdmin = User::factory()->create(['agency_id' => $foreignAgency->id, 'branch_id' => $foreignBranch->id, 'role' => 'admin']);
        $foreignProperty = Property::forceCreate([
            'agency_id' => $foreignAgency->id, 'agent_id' => $foreignAdmin->id, 'branch_id' => $foreignBranch->id,
            'title' => 'Foreign property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $foreignLease = $this->lease($foreignProperty, ['agency_id' => $foreignAgency->id, 'branch_id' => $foreignBranch->id, 'created_by_user_id' => $foreignAdmin->id]);

        $response = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.create', ['lease_id' => $foreignLease->id]));

        $response->assertOk();
        $response->assertDontSee($foreignProperty->buildDisplayAddress(), false);
    }

    // ── #2 — searchable property picker ──────────────────────────────────

    public function test_search_properties_finds_a_rental_property_by_address_scoped_to_the_agency(): void
    {
        $response = $this->actingAs($this->admin)->getJson(
            route('corex.rental-work-orders.search-properties', ['q' => 'Kenmuir'])
        );

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertTrue($ids->contains($this->property->id));
    }

    public function test_search_properties_never_returns_a_property_from_another_agency(): void
    {
        $foreignAgency = Agency::create(['name' => 'Foreign2', 'slug' => 'foreign2-' . uniqid()]);
        $foreignBranch = Branch::forceCreate(['name' => 'Elsewhere', 'agency_id' => $foreignAgency->id]);
        $foreignAdmin = User::factory()->create(['agency_id' => $foreignAgency->id, 'branch_id' => $foreignBranch->id, 'role' => 'admin']);
        Property::forceCreate([
            'agency_id' => $foreignAgency->id, 'agent_id' => $foreignAdmin->id, 'branch_id' => $foreignBranch->id,
            'title' => 'Kenmuir Lookalike', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        $response = $this->actingAs($this->admin)->getJson(
            route('corex.rental-work-orders.search-properties', ['q' => 'Kenmuir'])
        );

        $response->assertOk();
        $labels = collect($response->json())->pluck('label');
        $this->assertFalse($labels->contains('Kenmuir Lookalike'));
    }

    // ── #5 — Labour/Part choice for a free-text line ─────────────────────

    public function test_free_text_line_can_be_saved_as_part_not_always_labour(): void
    {
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);

        $line = app(RentalJobCardService::class)->addLine($jobCard, [
            'description' => 'Replacement tap washer', 'type' => RentalCatalogueItem::TYPE_PART, 'quantity' => 1,
        ], $this->admin);

        $this->assertSame(RentalCatalogueItem::TYPE_PART, $line->type);
    }

    public function test_free_text_line_with_no_type_posted_still_defaults_to_labour(): void
    {
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);

        $line = app(RentalJobCardService::class)->addLine($jobCard, [
            'description' => 'Call-out', 'quantity' => 1,
        ], $this->admin);

        $this->assertSame(RentalCatalogueItem::TYPE_LABOUR, $line->type);
    }

    public function test_picking_a_catalogue_item_pre_fills_its_own_type_when_none_is_explicitly_posted(): void
    {
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);
        $partItem = RentalCatalogueItem::create([
            'agency_id' => $this->agency->id,
            'rental_catalogue_item_type_id' => RentalCatalogueItemType::where('agency_id', $this->agency->id)->where('kind', 'part')->firstOrFail()->id,
            'code' => 'GEYSER-EL', 'description' => 'Geyser element',
            'rental_catalogue_unit_id' => RentalCatalogueUnit::where('agency_id', $this->agency->id)->where('name', 'Each')->firstOrFail()->id,
            'default_price' => 450, 'sort_order' => 1,
            'created_by_user_id' => $this->admin->id,
        ]);

        $line = app(RentalJobCardService::class)->addLine($jobCard, [
            'rental_catalogue_item_id' => $partItem->id,
            'quantity' => 1,
        ], $this->admin);

        $this->assertSame(RentalCatalogueItem::TYPE_PART, $line->type);
    }

    /**
     * 2026-10-05, Johan QA1 finding B — DELIBERATE REVERSAL of this test's
     * own prior name/assertion ("a catalogue item keeps its own type even
     * if a different type is posted alongside it"). Johan's own build
     * brief this round: "the Type select must be changeable" — picking an
     * item now pre-fills Type from the item's own kind (the test above),
     * but the field stays fully editable and an explicit choice is
     * RESPECTED, not silently overridden. The old behaviour (server always
     * forces the catalogue item's kind, ignoring whatever was posted) is
     * exactly what let the add-line row ship with Type permanently
     * disabled and defaulting to the first option (Labour) regardless of
     * the item picked — which IS the bug Johan reported.
     */
    public function test_an_explicitly_posted_type_wins_over_the_catalogue_items_own_type(): void
    {
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);
        $partItem = RentalCatalogueItem::create([
            'agency_id' => $this->agency->id,
            'rental_catalogue_item_type_id' => RentalCatalogueItemType::where('agency_id', $this->agency->id)->where('kind', 'part')->firstOrFail()->id,
            'code' => 'GEYSER-EL', 'description' => 'Geyser element',
            'rental_catalogue_unit_id' => RentalCatalogueUnit::where('agency_id', $this->agency->id)->where('name', 'Each')->firstOrFail()->id,
            'default_price' => 450, 'sort_order' => 1,
            'created_by_user_id' => $this->admin->id,
        ]);

        $line = app(RentalJobCardService::class)->addLine($jobCard, [
            'rental_catalogue_item_id' => $partItem->id,
            'type' => RentalCatalogueItem::TYPE_LABOUR,
            'quantity' => 1,
        ], $this->admin);

        $this->assertSame(RentalCatalogueItem::TYPE_LABOUR, $line->type);
    }

    // ── §4 — catalogue item picker pre-fill + snapshot (2026-10-05, Johan QA1 findings A/B/4) ──

    public function test_picking_a_catalogue_item_pre_fills_description_unit_and_price(): void
    {
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);
        $item = RentalCatalogueItem::create([
            'agency_id' => $this->agency->id,
            'rental_catalogue_item_type_id' => RentalCatalogueItemType::where('agency_id', $this->agency->id)->where('kind', 'part')->firstOrFail()->id,
            'code' => 'PLUMB-01', 'description' => 'Plumbing parts',
            'rental_catalogue_unit_id' => RentalCatalogueUnit::where('agency_id', $this->agency->id)->where('name', 'Each')->firstOrFail()->id,
            'default_price' => 120, 'sort_order' => 1,
            'created_by_user_id' => $this->admin->id,
        ]);

        $line = app(RentalJobCardService::class)->addLine($jobCard, [
            'rental_catalogue_item_id' => $item->id, 'quantity' => 1,
        ], $this->admin);

        $this->assertSame('Plumbing parts', $line->description);
        $this->assertSame('Each', $line->unit);
        $this->assertSame('120.00', (string) $line->unit_price);
        $this->assertSame(RentalCatalogueItem::TYPE_PART, $line->type);
    }

    public function test_an_explicit_description_override_still_wins_over_the_catalogue_items_own(): void
    {
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);
        $item = RentalCatalogueItem::create([
            'agency_id' => $this->agency->id,
            'rental_catalogue_item_type_id' => RentalCatalogueItemType::where('agency_id', $this->agency->id)->where('kind', 'part')->firstOrFail()->id,
            'code' => 'PLUMB-01', 'description' => 'Plumbing parts',
            'rental_catalogue_unit_id' => RentalCatalogueUnit::where('agency_id', $this->agency->id)->where('name', 'Each')->firstOrFail()->id,
            'default_price' => 120, 'sort_order' => 1,
            'created_by_user_id' => $this->admin->id,
        ]);

        $line = app(RentalJobCardService::class)->addLine($jobCard, [
            'rental_catalogue_item_id' => $item->id, 'description' => 'Plumbing parts, edited', 'quantity' => 1,
        ], $this->admin);

        $this->assertSame('Plumbing parts, edited', $line->description);
    }

    public function test_a_line_snapshots_the_catalogue_items_code_at_add_time(): void
    {
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);
        $item = RentalCatalogueItem::create([
            'agency_id' => $this->agency->id,
            'rental_catalogue_item_type_id' => RentalCatalogueItemType::where('agency_id', $this->agency->id)->where('kind', 'part')->firstOrFail()->id,
            'code' => 'SNAP-01', 'description' => 'Snapshot test item',
            'rental_catalogue_unit_id' => RentalCatalogueUnit::where('agency_id', $this->agency->id)->where('name', 'Each')->firstOrFail()->id,
            'default_price' => 50, 'sort_order' => 1,
            'created_by_user_id' => $this->admin->id,
        ]);

        $line = app(RentalJobCardService::class)->addLine($jobCard, [
            'rental_catalogue_item_id' => $item->id, 'quantity' => 1,
        ], $this->admin);

        $this->assertSame('SNAP-01', $line->code);

        // A later rename/archive of the catalogue item never changes the
        // already-saved line's own snapshot.
        $item->update(['code' => 'RENAMED-01', 'description' => 'Renamed item']);
        $item->archive();

        $line->refresh();
        $this->assertSame('SNAP-01', $line->code);
        $this->assertSame('Snapshot test item', $line->description);
    }

    public function test_a_free_text_line_has_no_code(): void
    {
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);

        $line = app(RentalJobCardService::class)->addLine($jobCard, [
            'description' => 'Hand-typed line', 'quantity' => 1,
        ], $this->admin);

        $this->assertNull($line->code);
    }

    // ── #6 — no-approval limit shown next to the total ───────────────────

    public function test_show_screen_is_handed_the_propertys_no_approval_threshold(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'no_approval_spend_threshold' => 2000, 'capture_prices_on_job_cards' => true]);
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);
        app(RentalJobCardService::class)->addLine($jobCard, ['description' => 'Small fix', 'quantity' => 1, 'unit_price' => 350], $this->admin);

        $response = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $jobCard));

        $response->assertOk();
        $response->assertSee('2,000.00', false);
        $response->assertSee('Within', false);
    }

    public function test_show_screen_flags_a_total_over_the_threshold(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'no_approval_spend_threshold' => 100, 'capture_prices_on_job_cards' => true]);
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);
        app(RentalJobCardService::class)->addLine($jobCard, ['description' => 'Big fix', 'quantity' => 1, 'unit_price' => 350], $this->admin);

        $response = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $jobCard));

        $response->assertOk();
        $response->assertSee('Over', false);
    }

    // ── #8 — an owner quote must never be addressed to a tenant ──────────

    private function contact(string $first, string $last): \App\Models\Contact
    {
        return \App\Models\Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => $first, 'last_name' => $last,
            'email' => strtolower($first . '.' . $last) . '-' . uniqid() . '@example.test',
        ]);
    }

    public function test_property_with_only_a_tenant_contact_resolves_no_landlord(): void
    {
        $tenant = $this->contact('Andre', 'Roets');
        ContactPropertyLinker::link($tenant->id, $this->property->id, 'tenant');

        $this->assertNull($this->property->landlordContact());
        $this->assertNotNull($this->property->sellerOwnerContact()); // the broader, display-only lookup is untouched
    }

    public function test_sending_a_quote_with_only_a_tenant_linked_is_blocked_and_sends_no_mail(): void
    {
        Mail::fake();
        $tenant = $this->contact('Andre', 'Roets');
        ContactPropertyLinker::link($tenant->id, $this->property->id, 'tenant');

        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);
        app(RentalJobCardService::class)->addLine($jobCard, ['description' => 'Fix', 'quantity' => 1, 'unit_price' => 100], $this->admin);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $jobCard))
            ->assertSessionHasErrors('rental_job_card');

        $jobCard->refresh();
        $this->assertSame(\App\Models\RentalJobCard::STATUS_DRAFT, $jobCard->status); // never sent
        $this->assertTrue($jobCard->quotes()->doesntExist());
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    public function test_show_screen_offers_link_landlord_when_none_is_linked(): void
    {
        $tenant = $this->contact('Andre', 'Roets');
        ContactPropertyLinker::link($tenant->id, $this->property->id, 'tenant');
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);

        $response = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $jobCard));

        $response->assertOk();
        $response->assertSee('No landlord linked', false);
        $response->assertSee('Link landlord', false);
    }

    public function test_sending_a_quote_with_a_real_landlord_linked_still_works(): void
    {
        Mail::fake();
        $landlord = $this->contact('Jane', 'Owner');
        ContactPropertyLinker::link($landlord->id, $this->property->id, 'landlord');

        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);
        app(RentalJobCardService::class)->addLine($jobCard, ['description' => 'Fix', 'quantity' => 1, 'unit_price' => 100], $this->admin);

        $fake = new class extends \App\Services\Rentals\RentalMailDispatcher {
            public array $sent = [];

            public function __construct() {}

            public function send(?string $recipientEmail, \App\Mail\Signatures\BaseSignatureMail $mail): void
            {
                $this->sent[] = [$recipientEmail, $mail];
            }
        };
        $this->app->instance(\App\Services\Rentals\RentalMailDispatcher::class, $fake);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $jobCard))->assertRedirect();

        $jobCard->refresh();
        $this->assertNotSame(\App\Models\RentalJobCard::STATUS_DRAFT, $jobCard->status);
        // BUILD 2 (§17.16) — the quote reaches the landlord through the agency mailbox path, never a plain queued Mailable.
        Mail::assertNothingQueued();
        $this->assertCount(1, $fake->sent);
        $this->assertInstanceOf(\App\Mail\Rentals\RentalOwnerQuoteMail::class, $fake->sent[0][1]);
        $this->assertSame($landlord->email, $fake->sent[0][0]);
    }

    public function test_an_outside_supplier_work_order_owner_notification_never_mails_a_tenant(): void
    {
        Mail::fake();
        $tenant = $this->contact('Andre', 'Roets');
        ContactPropertyLinker::link($tenant->id, $this->property->id, 'tenant');

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.store'), [
            'property_id' => $this->property->id,
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED,
            'title' => 'Outside supplier job', 'description' => 'Needs a plumber.',
        ])->assertRedirect();

        // notifyOwner() is skipped entirely — no landlord to mail, and it
        // must never fall back to the tenant.
        Mail::assertNothingQueued();
    }
}
