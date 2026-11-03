<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\Contact;
use App\Models\RoadToSold\RtsContactOutcome;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\RolePermission;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Services\PermissionService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/** road-to-sold.md 23.23 drop 3: the Contact step of the pitch popup - Phone call as the third tab, and every send on the one contact record. */
final class PitchContactStepTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private $tp;
    private int $propertyId;
    private Contact $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        Cache::flush();
        $this->setUpAgency();
        config(['road-to-sold-belt.require_channel' => true, 'road-to-sold-belt.enforce_owner_gate' => true]);
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);
        foreach (['agent', 'admin'] as $role) {
            RolePermission::updateOrCreate(['role' => $role, 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
            RolePermission::updateOrCreate(['role' => $role, 'permission_key' => 'outreach.compose', 'agency_id' => $this->agency->id]);
        }
        PermissionService::clearCache();

        $this->tp = $this->tp(['street_name' => 'Pitch Road', 'street_number' => '12']);
        $this->owner = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'first_name' => 'Pieter', 'last_name' => 'Owner', 'phone' => '0825512345', 'email' => 'pieter@example.test']);
        $this->propertyId = $this->property($this->agent->id, ['address' => '12 Pitch Road', 'suburb' => 'Margate', 'tracked_property_id' => $this->tp->id]);
        DB::table('contact_property')->insert(['contact_id' => $this->owner->id, 'property_id' => $this->propertyId, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
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

    private function ownerFound(): void
    {
        $this->tp->update(['owner_contact_id' => $this->owner->id, 'deeds_captured_at' => now()]);
    }

    private function composer(array $q = [], array $headers = [])
    {
        return $this->actingAs($this->agent)->get(route('seller-outreach.composer.show', ['contact' => $this->owner->id, 'property_id' => $this->propertyId] + $q), $headers);
    }

    public function test_the_phone_call_tab_lists_the_one_grouped_vocabulary_and_the_call_time(): void
    {
        $this->ownerFound();
        $h = $this->composer(['channel' => 'phone', 'embed' => 1])->assertOk()->getContent();

        $this->assertStringContainsString('data-qa="tab-phone"', $h, 'Phone call sits beside WhatsApp and Email');
        $this->assertStringContainsString('data-qa="phone-call"', $h);
        foreach (['Moves it forward', 'Try again later', 'Stops here (say why)'] as $g) {
            $this->assertStringContainsString($g, $h);
        }
        $this->assertStringContainsString('When was the call?', $h);
        $this->assertStringContainsString('Feedback note', $h);
        $this->assertStringContainsString('Log this call', $h);
        $this->assertStringContainsString('0825512345', $h, 'the number to dial');
        $this->assertStringNotContainsString('Message body', $h, 'nothing is composed for a call');
    }

    public function test_the_phone_tab_is_gated_on_the_owner_like_every_other_contact(): void
    {
        $h = $this->composer(['channel' => 'phone', 'embed' => 1])->assertOk()->getContent();

        $this->assertStringContainsString('data-qa="phone-gate"', $h);
        $this->assertStringNotContainsString('Log this call', $h);
    }

    public function test_inside_the_popup_the_contact_step_has_one_action_and_no_side_panels(): void
    {
        $this->ownerFound();
        $page = $this->composer()->assertOk()->getContent();
        $in = $this->composer(['embed' => 1])->assertOk()->getContent();

        $this->assertStringContainsString('Add to queue', $page);
        $this->assertStringContainsString('Live demand facts', $page);
        $this->assertMatchesRegularExpression('#<a href="[^"]*/contacts/\d+"\s+class="text-sm"[^>]*>\s*Cancel\s*</a>#', $page);

        $this->assertStringNotContainsString('Add to queue', $in, 'one primary action per tab');
        $this->assertDoesNotMatchRegularExpression('#<a href="[^"]*/contacts/\d+"\s+class="text-sm"[^>]*>\s*Cancel\s*</a>#', $in);
        $this->assertStringNotContainsString('Live demand facts', $in, 'the demand and tracking panels are the page\'s');
        $this->assertStringNotContainsString('Tracking', $in);
        $this->assertStringNotContainsString('Compose Seller Pitch', $in, 'the popup has its own title');
        $this->assertStringContainsString('Open WhatsApp &amp; record send', $in);
        $this->assertStringNotContainsString('data-tour="oc-template"', $in, 'the popup uses the default template: nothing to choose');
        $this->assertStringContainsString('data-tour="oc-template"', $page, 'the page still lets the agent pick one');
        $this->assertSame(1, substr_count($in, '12 Pitch Road'), 'the address is shown once');
    }

    public function test_a_confirmed_whatsapp_pitch_is_one_contact_on_the_belt_and_never_two(): void
    {
        $this->ownerFound();
        $send = $this->actingAs($this->agent)->postJson(route('seller-outreach.composer.submit', $this->owner), [
            'property_id' => $this->propertyId, 'channel' => 'whatsapp', 'body' => 'Hi {seller_name}. {tracking_link} Reply STOP to {opt_out_link}.',
        ])->assertOk();
        $sendId = (int) $send->json('send_id');
        $this->assertSame(0, RtsContactOutcome::query()->count(), 'sent but not yet confirmed: CoreX cannot tell, so nothing is recorded yet');

        $r = $this->actingAs($this->agent)->postJson(route('seller-outreach.composer.mark-sent', ['contact' => $this->owner->id, 'send' => $sendId]))->assertOk();
        $this->assertTrue($r->json('contact_logged'));
        $this->actingAs($this->agent)->postJson(route('seller-outreach.composer.mark-sent', ['contact' => $this->owner->id, 'send' => $sendId]))->assertOk();

        $rows = RtsContactOutcome::query()->get();
        $this->assertCount(1, $rows, 'confirming twice writes once');
        $this->assertSame('whatsapp', $rows[0]->channel);
        $this->assertSame('No reply', $rows[0]->outcome_label, 'the wording the agent would read for a written channel');
        $this->assertSame((int) $this->tp->id, (int) $rows[0]->tracked_property_id);
    }

    public function test_a_whatsapp_that_did_not_go_is_not_a_contact(): void
    {
        $this->ownerFound();
        $send = $this->actingAs($this->agent)->postJson(route('seller-outreach.composer.submit', $this->owner), [
            'property_id' => $this->propertyId, 'channel' => 'whatsapp', 'body' => 'Hi {seller_name}. {tracking_link} Reply STOP to {opt_out_link}.',
        ])->assertOk();
        $this->actingAs($this->agent)->postJson(route('seller-outreach.composer.not-sent', ['contact' => $this->owner->id, 'send' => (int) $send->json('send_id')]))->assertOk();

        $this->assertSame(0, RtsContactOutcome::query()->count());
    }

    public function test_the_phone_call_goes_through_the_one_outcome_route_as_json_with_its_own_time(): void
    {
        $this->ownerFound();
        $def = Def::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'spoke_call_back')->firstOrFail();
        $when = now()->subHours(2);

        $r = $this->actingAs($this->agent)->postJson(route('road-to-sold.outcomes.record'), [
            'tracked_property_id' => $this->tp->id, 'outcome_definition_id' => $def->id, 'channel' => 'phone',
            'occurred_at' => $when->format('Y-m-d\TH:i'), 'note' => 'Wants to talk after the weekend', 'next_action_at' => now()->addDays(3)->toDateString(),
        ])->assertOk();
        $this->assertTrue($r->json('ok'));

        $row = RtsContactOutcome::query()->firstOrFail();
        $this->assertSame('phone', $row->channel);
        $this->assertSame($when->format('Y-m-d H:i'), $row->occurred_at->format('Y-m-d H:i'), 'the time of the call, not the time it was typed');
        $this->assertSame('Wants to talk after the weekend', $row->note);
    }

    public function test_a_call_cannot_be_dated_in_the_future_and_a_closed_gate_answers_as_json(): void
    {
        $def = Def::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'no_answer')->firstOrFail();

        $gate = $this->actingAs($this->agent)->postJson(route('road-to-sold.outcomes.record'), ['tracked_property_id' => $this->tp->id, 'outcome_definition_id' => $def->id, 'channel' => 'phone'])->assertStatus(422);
        $this->assertFalse($gate->json('ok'));
        $this->assertStringContainsString('owner', strtolower((string) $gate->json('message')));

        $this->ownerFound();
        $future = $this->actingAs($this->agent)->postJson(route('road-to-sold.outcomes.record'), [
            'tracked_property_id' => $this->tp->id, 'outcome_definition_id' => $def->id, 'channel' => 'phone', 'occurred_at' => now()->addDay()->format('Y-m-d\TH:i'),
        ])->assertStatus(422);
        $this->assertStringContainsString('future', json_encode($future->json()));
        $this->assertSame(0, RtsContactOutcome::query()->count());
    }
}
