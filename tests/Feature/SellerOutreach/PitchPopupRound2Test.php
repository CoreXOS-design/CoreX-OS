<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\Contact;
use App\Models\ProspectingClaim;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RolePermission;
use App\Services\PermissionService;
use App\Services\Prospecting\ProspectingIntelligenceService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\PitchPathService;
use App\Services\RoadToSold\SettingsService;
use App\Services\SellerOutreach\PitchBeltResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/** road-to-sold.md 23.23 drop 5: one gate for the row and the popup, the deed picker when the deed is missing, the pills' screens, the trimmed Contact step. */
final class PitchPopupRound2Test extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private $tp;            // the file the agent's record is on: owner linked, deed on record
    private $dupTp;         // a duplicate file the CoreX property points at: no owner
    private int $propertyId;
    private Contact $owner;
    private int $listingId;
    private RtsProspect $prospect;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
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

        $this->tp = $this->tp(['street_name' => 'Marine Drive', 'street_number' => '73']);
        $this->dupTp = $this->tp(['street_name' => 'Marine Dr', 'street_number' => '73']);
        $this->owner = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'first_name' => 'Pieter', 'last_name' => 'Owner', 'phone' => '0825512345', 'email' => 'pieter@example.test']);
        $this->propertyId = $this->property($this->agent->id, ['address' => '73 Marine Dr', 'suburb' => 'Uvongo Beach', 'tracked_property_id' => $this->dupTp->id]);
        DB::table('contact_property')->insert(['contact_id' => $this->owner->id, 'property_id' => $this->propertyId, 'role' => 'seller', 'created_at' => now(), 'updated_at' => now()]);
        $this->tp->update(['owner_contact_id' => $this->owner->id, 'deeds_captured_at' => now(), 'promoted_to_property_id' => $this->propertyId]);

        $this->listingId = $this->listing([], $this->tp);
        DB::table('prospecting_listings')->where('id', $this->listingId)->update(['matched_property_id' => $this->propertyId]);
        ProspectingClaim::create(['agency_id' => $this->agency->id, 'prospecting_listing_id' => $this->listingId, 'user_id' => $this->agent->id, 'status' => 'claimed', 'claimed_at' => now(), 'last_updated_at' => now()]);
        $this->prospect = RtsProspect::query()->where('tracked_property_id', $this->tp->id)->firstOrFail();
        $this->prospect->forceFill(['property_id' => $this->propertyId])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function composer(array $q = [])
    {
        return $this->actingAs($this->agent)->get(route('seller-outreach.composer.show', ['contact' => $this->owner->id, 'property_id' => $this->propertyId, 'embed' => 1] + $q));
    }

    public function test_the_property_pointing_at_a_duplicate_file_does_not_change_the_gate_answer(): void
    {
        $property = \App\Models\Property::withoutGlobalScopes()->find($this->propertyId);
        $belt = app(PitchBeltResolver::class);

        $this->assertSame((int) $this->tp->id, $belt->trackedFor($this->agent, $property), "the agent's own record wins over what the property row points at");
        $this->assertSame((int) $this->tp->id, $belt->trackedFor($this->agent, $property, (int) $this->prospect->id));
        $this->assertSame((int) $this->dupTp->id, $belt->trackedFor($this->makeAgent(), $property), 'someone with no record of their own falls back to the property file');

        $h = $this->composer(['channel' => 'phone', 'rts_prospect' => $this->prospect->id])->assertOk()->getContent();
        $this->assertStringNotContainsString('data-qa="phone-gate"', $h, 'the row lets the agent log a call, so the popup does too');
        $this->assertStringContainsString('Log this call', $h);
        $this->assertStringNotContainsString('Find the owner first', $h);
    }

    public function test_a_send_is_gated_by_the_same_file(): void
    {
        $r = $this->actingAs($this->agent)->postJson(route('seller-outreach.composer.submit', $this->owner), [
            'property_id' => $this->propertyId, 'channel' => 'whatsapp', 'body' => 'Hi {seller_name}. {tracking_link} Reply STOP to {opt_out_link}.',
        ]);

        $this->assertNotSame(422, $r->status(), 'not refused by the gate: ' . $r->getContent());
    }

    public function test_an_owner_with_no_deed_goes_to_the_deed_picker_not_the_pitch_page(): void
    {
        $this->tp->update(['deeds_captured_at' => null]);
        $next = app(PitchPathService::class)->next($this->prospect->fresh(), $this->agent, []);

        $this->assertSame('find_owner', $next['step']);
        $this->assertSame(['the deed record'], $next['missing']);
        $this->assertStringContainsString('/outreach/compose', $next['url']);
        $this->assertStringNotContainsString('/contacts/', $next['url']);
        $this->assertStringContainsString('need=deed', $next['url']);

        $page = $this->actingAs($this->agent)->get($next['url'])->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="need-deed"', $page);
        $this->assertStringContainsString('Link a deed', $page, 'the live deed picker is there');

        $this->tp->update(['deeds_captured_at' => now()]);
        $ready = app(PitchPathService::class)->next($this->prospect->fresh(), $this->agent, []);
        $this->assertSame('pitch', $ready['step'], 'owner and deed on record: the pitch');
    }

    public function test_the_phone_tab_names_the_deed_and_leads_back_to_the_owner_step(): void
    {
        $this->tp->update(['deeds_captured_at' => null]);
        $h = $this->composer(['channel' => 'phone', 'rts_prospect' => $this->prospect->id])->assertOk()->getContent();

        $this->assertStringContainsString('data-qa="phone-gate"', $h);
        $this->assertStringContainsString('Still missing: the deed record.', $h);
        $this->assertStringContainsString('data-qa="phone-gate-go"', $h);
        $this->assertStringContainsString("corexPitchGo('owner', 'deed')", $h);
    }

    public function test_the_popup_asks_which_screens_exist_and_unavailable_ones_come_back_null(): void
    {
        $r = $this->actingAs($this->agent)->getJson(route('seller-outreach.entry.pitch-steps', ['prospect' => $this->prospect->id]))->assertOk()->json('steps');

        $this->assertStringContainsString('_pitch_card=' . $this->listingId, (string) $r['property']);
        $this->assertStringContainsString('/outreach/compose', (string) $r['owner']);
        $this->assertStringContainsString('/contacts/' . $this->owner->id . '/outreach/compose', (string) $r['contact']);
        $this->assertStringContainsString('pitch_book=1', (string) $r['evaluation']);

        DB::table('prospecting_listings')->where('id', $this->listingId)->update(['matched_property_id' => null]);
        $bare = $this->actingAs($this->agent)->getJson(route('seller-outreach.entry.pitch-steps', ['listing' => $this->listingId]))->assertOk()->json('steps');
        $this->assertArrayNotHasKey('pitch', $bare, 'there is no Pitch step any more (23.30)');

        $other = $this->makeAgent();
        $theirs = $this->actingAs($other)->getJson(route('seller-outreach.entry.pitch-steps', ['prospect' => $this->prospect->id]))->assertOk()->json('steps');
        $this->assertNull($theirs['contact'], "another agent's record is never read");
    }

    public function test_the_contact_step_has_no_picker_shows_the_message_as_it_will_read_and_edits_on_request(): void
    {
        $second = $this->property($this->agent->id, ['address' => 'Unit 7, Stafford Close', 'suburb' => 'Uvongo Beach', 'price' => 750000]);
        DB::table('contact_property')->insert(['contact_id' => $this->owner->id, 'property_id' => $second, 'role' => 'seller', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_outreach_templates')->insert([
            'agency_id' => $this->agency->id, 'name' => 'General Marketing', 'channel' => 'whatsapp', 'body' => 'Hi {seller_name}. Manage your preferences: {opt_out_link}',
            'is_active' => true, 'is_default_for_channel' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $in = $this->composer()->assertOk()->getContent();
        $page = $this->actingAs($this->agent)->get(route('seller-outreach.composer.show', ['contact' => $this->owner->id, 'property_id' => $this->propertyId]))->assertOk()->getContent();

        $this->assertStringNotContainsString('Property this pitch is about', $in, 'the property is fixed inside the popup');
        $this->assertStringNotContainsString('Unit 7, Stafford Close', $in);
        $this->assertStringContainsString('Property this pitch is about', $page, 'the page still offers the choice');
        $this->assertStringContainsString('x-data="{ editing: false }"', $in);
        $this->assertStringContainsString('data-qa="edit-message"', $in);
        $this->assertStringContainsString('data-qa="message-preview"', $in);
        $this->assertStringContainsString('x-data="{ editing: true }"', $page);
        $this->assertSame(1, substr_count($in, '73 Marine Dr'), 'the address once');
    }

    public function test_the_cheap_buyer_count_is_exactly_the_snapshots_figure(): void
    {
        foreach ([$this->buyer($this->agent->id), $this->buyer($this->agent->id, 'warm')] as $b) {
            \App\Models\ContactMatch::withoutGlobalScopes()->forceCreate(['contact_id' => $b->id, 'agency_id' => $this->agency->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
        $svc = app(ProspectingIntelligenceService::class);
        $f = ['agency_id' => $this->agency->id, 'listing_type' => 'sale'];

        $this->assertSame(2, $svc->activeBuyerCount($f), 'it counts real buyers, not zero twice');
        $this->assertSame($svc->snapshot($f)->activeBuyers, $svc->activeBuyerCount($f));
    }
}
