<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\Contact;
use App\Models\ProspectingClaim;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RolePermission;
use App\Services\PermissionService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Johan, 5 Nov 2026: "Road to Sold can only block Road to Sold processes, not the rest." The owner / deed gate stops a pitch ONLY when it was opened as a
 * step of the agent's own Road to Sold record (the popup names it as rts_prospect). From the property page, a contact, MIC, a CMA import or by hand it never
 * fires - even when the property has an open Road to Sold claim and no owner on the tracked property (road-to-sold.md 23.6.5).
 */
final class ComposerRoadToSoldGateOnlyTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private $tp;
    private int $propertyId;
    private int $listingId;
    private Contact $seller;
    private RtsProspect $prospect;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        Cache::flush();
        $this->setUpAgency();
        config(['road-to-sold-belt.require_channel' => true, 'road-to-sold-belt.enforce_owner_gate' => true]);
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);
        foreach (['agent', 'admin'] as $role) {
            foreach (['access_road_to_sold', 'outreach.compose'] as $perm) {
                RolePermission::updateOrCreate(['role' => $role, 'permission_key' => $perm, 'agency_id' => $this->agency->id]);
            }
        }
        PermissionService::clearCache();

        // the live case: a claimed Road to Sold record, a seller linked on the PROPERTY page (contact_property), nothing on the tracked property's owner
        $this->tp = $this->tp(['street_name' => 'Rambler Road', 'street_number' => '616']);
        $this->listingId = $this->listing(['address' => '616 Rambler Road', 'suburb' => 'Margate'], $this->tp);
        ProspectingClaim::create(['agency_id' => $this->agency->id, 'prospecting_listing_id' => $this->listingId, 'user_id' => $this->agent->id, 'status' => 'claimed', 'claimed_at' => now(), 'last_updated_at' => now()]);
        $this->prospect = RtsProspect::query()->where('tracked_property_id', $this->tp->id)->firstOrFail();

        $this->propertyId = $this->property($this->agent->id, ['address' => '616 Rambler Road', 'suburb' => 'Margate', 'tracked_property_id' => $this->tp->id]);
        $this->prospect->forceFill(['property_id' => $this->propertyId])->save();
        $this->seller = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'first_name' => 'Nicolette', 'last_name' => 'Seller', 'phone' => '0825512345', 'email' => 'nicolette@example.test']);
        DB::table('contact_property')->insert(['contact_id' => $this->seller->id, 'property_id' => $this->propertyId, 'role' => 'seller', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_outreach_templates')->insert([
            'agency_id' => $this->agency->id, 'name' => 'General Marketing', 'channel' => 'whatsapp', 'body' => 'Hi {seller_name}. {tracking_link} Reply STOP to {opt_out_link}.',
            'is_active' => true, 'is_default_for_channel' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function send(array $extra = [])
    {
        return $this->actingAs($this->agent)->postJson(route('seller-outreach.composer.submit', $this->seller), [
            'property_id' => $this->propertyId, 'channel' => 'whatsapp', 'body' => 'Hi {seller_name}. {tracking_link} Reply STOP to {opt_out_link}.',
        ] + $extra);
    }

    private function queue(array $extra = [])
    {
        return $this->actingAs($this->agent)->postJson(route('seller-outreach.composer.queue', $this->seller), [
            'property_id' => $this->propertyId, 'channel' => 'whatsapp', 'body' => 'Hi {seller_name}. {tracking_link} Reply STOP to {opt_out_link}.',
        ] + $extra);
    }

    public function test_a_send_from_the_property_page_or_a_contact_is_never_gated_even_with_an_open_claim_and_no_owner(): void
    {
        $this->assertNull($this->tp->fresh()->owner_contact_id, 'the fixture: the tracked property has no owner');

        $r = $this->send()->assertOk();

        $this->assertNotNull($r->json('send_id'), 'the pitch went out');
        $this->assertNull($r->json('find_owner'));
    }

    public function test_adding_to_the_queue_from_the_property_page_is_not_gated_either(): void
    {
        $r = $this->queue();

        $this->assertNotSame(422, $r->status(), (string) $r->getContent());
        $this->assertNull($r->json('find_owner'));
    }

    public function test_a_stale_or_foreign_prospect_id_does_not_make_the_pitch_a_road_to_sold_step(): void
    {
        $other = $this->tp(['street_name' => 'Elsewhere Road', 'street_number' => '9']);
        $otherListing = $this->listing(['address' => '9 Elsewhere Road', 'suburb' => 'Margate'], $other);
        ProspectingClaim::create(['agency_id' => $this->agency->id, 'prospecting_listing_id' => $otherListing, 'user_id' => $this->mate->id, 'status' => 'claimed', 'claimed_at' => now(), 'last_updated_at' => now()]);
        $mates = RtsProspect::query()->where('tracked_property_id', $other->id)->firstOrFail();

        $this->send(['rts_prospect' => $mates->id])->assertOk();     // a colleague's record
        $this->send(['rts_prospect' => 999999])->assertOk();         // no such record
    }

    public function test_from_the_road_to_sold_step_with_no_owner_it_is_refused_with_the_link_to_find_the_owner(): void
    {
        $r = $this->send(['rts_prospect' => $this->prospect->id, 'embed' => 1, 'rts_stage' => 'claimed'])->assertStatus(422);

        $this->assertTrue($r->json('find_owner'));
        $this->assertStringContainsString('Find the owner first, then contact them.', $r->json('message'));
        $this->assertStringContainsString('the owner contact', $r->json('message'), 'it says what is still needed');
        $url = $r->json('find_owner_url');
        $this->assertStringContainsString('rts_prospect=' . $this->prospect->id, $url, 'the link keeps the record the agent is working');
        $this->assertStringContainsString('/outreach/compose', $url, 'it is the Find owner screen');
        $this->assertSame(0, DB::table('seller_outreach_sends')->count(), 'nothing was sent');

        $q = $this->queue(['rts_prospect' => $this->prospect->id])->assertStatus(422);
        $this->assertStringContainsString('rts_prospect=' . $this->prospect->id, (string) $q->json('find_owner_url'));
    }

    public function test_from_the_road_to_sold_step_with_the_owner_found_it_is_allowed(): void
    {
        $this->tp->update(['owner_contact_id' => $this->seller->id, 'deeds_captured_at' => now()]);

        $r = $this->send(['rts_prospect' => $this->prospect->id, 'embed' => 1])->assertOk();

        $this->assertNotNull($r->json('send_id'));
    }

    public function test_the_composer_page_carries_the_road_to_sold_context_into_its_send_and_shows_the_gate_link(): void
    {
        $h = $this->actingAs($this->agent)->get(route('seller-outreach.composer.show', ['contact' => $this->seller->id, 'property_id' => $this->propertyId, 'rts_prospect' => $this->prospect->id, 'embed' => 1]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('rtsFields()', $h, 'the send posts the Road to Sold context');
        $this->assertStringContainsString('data-qa="rts-gate"', $h);
        $this->assertStringContainsString('Find the owner', $h);
    }
}
