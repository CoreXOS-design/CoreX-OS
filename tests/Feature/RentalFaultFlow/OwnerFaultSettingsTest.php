<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Mail\Rentals\RentalOwnerFaultSettingsMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalPropertyFaultSetting as Row;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\Rentals\RentalOwnerFaultSettings;
use App\Services\Rentals\RentalOwnerFaultSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22 - "How I want faults handled": the OWNER's per-property settings. The resolver for every status,
 * the carry-over (no email), the agent's capture with evidence, the owner's confirm / change, the signed "I do not agree" link, and scope.
 */
final class OwnerFaultSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private User $ownerAgent;
    private User $tenantAgent;
    private Property $property;
    private Lease $lease;
    private Contact $landlord;
    /** @var list<array{0:int,1:string}> every alert the dispatcher was asked to fire: [user id, event key] */
    private array $alerts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');

        $this->agency = Agency::create(['name' => 'Owner Settings Agency', 'slug' => 'ofs-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'name' => 'Agnes Agent']);
        $this->ownerAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Olga OwnerAgent']);
        $this->tenantAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Tom TenantAgent']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'OFS Unit', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000, 'start_date' => now()->subMonth(), 'is_month_to_month' => true,
            'lease_type' => 'residential', 'source' => 'manual', 'owner_agent_user_id' => $this->ownerAgent->id, 'tenant_agent_user_id' => $this->tenantAgent->id,
        ]);
        $this->landlord = Contact::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Lenny', 'last_name' => 'Landlord', 'email' => 'lenny+' . uniqid() . '@example.invalid',
        ]);
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);

        $self = $this;
        $mock = \Mockery::mock(NotificationDispatcher::class);
        $mock->shouldReceive('fire')->andReturnUsing(function ($user, $key) use ($self) {
            $self->alerts[] = [$user->id, $key];

            return true;
        });
        $this->app->instance(NotificationDispatcher::class, $mock);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function values(array $over = []): array
    {
        return array_merge(['repair_limit' => 1500, 'emergency_limit' => 5000, 'callout_mode' => 'yes', 'callout_limit' => 750], $over);
    }

    private function capture(array $over = [], ?Property $p = null): Row
    {
        return app(RentalOwnerFaultSettingsService::class)->capture($p ?? $this->property, $this->agent, $this->values($over), 'phone_call', now()->subDay(), 'Owner phoned and agreed.');
    }

    private function row(?Property $p = null): ?Row
    {
        return Row::withoutGlobalScopes()->where('property_id', ($p ?? $this->property)->id)->first();
    }

    private function changes(?Property $p = null): \Illuminate\Support\Collection
    {
        return DB::table('rental_property_fault_setting_changes')->where('property_id', ($p ?? $this->property)->id)->orderBy('id')->get();
    }

    private function resolver(?Property $p = null): RentalOwnerFaultSettings
    {
        return RentalOwnerFaultSettings::for(($p ?? $this->property)->fresh());
    }

    private function portal(): void
    {
        $login = ClientUser::firstOrCreate(['email' => $this->landlord->email], ['current_agency_id' => $this->agency->id]);
        $this->landlord->forceFill(['client_user_id' => $login->id])->saveQuietly();
        Sanctum::actingAs($login, ['client']);
    }

    private function otherAgencyProperty(): Property
    {
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'oa-' . uniqid()]);
        $b = Branch::create(['agency_id' => $other->id, 'name' => 'Main', 'code' => 'O-' . $other->id, 'is_active' => true]);
        $u = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $b->id, 'role' => 'admin']);

        $p = Property::forceCreate(['agency_id' => $other->id, 'agent_id' => $u->id, 'branch_id' => $b->id, 'title' => 'Other Unit', 'status' => 'active', 'listing_type' => 'rental']);
        DB::table('properties')->where('id', $p->id)->update(['agency_id' => $other->id]);   // BelongsToAgency stamps the ACTING user's agency on create

        return $p->fresh();
    }

    // ── 1. the resolver: every status ────────────────────────────────────────

    public function test_not_set_means_must_ask_for_everything_and_the_agency_default_is_only_a_proposal(): void
    {
        $r = $this->resolver();
        $this->assertSame('not_set', $r->status());
        $this->assertFalse($r->hasStandingAuthority());
        $this->assertNull($r->repairLimit());
        $this->assertNull($r->emergencyLimit());
        $this->assertNull($r->calloutAllowedUpTo());
        $this->assertSame(500.0, $r->proposed()['repair_limit'], "the agency's default is offered as a suggestion only");
        $this->assertNull($this->row(), 'reading creates nothing');
    }

    public function test_captured_by_agent_counts_as_standing_authority_with_its_values(): void
    {
        $this->capture();
        $r = $this->resolver();
        $this->assertSame('captured_by_agent', $r->status());
        $this->assertTrue($r->hasStandingAuthority());
        $this->assertSame([1500.0, 5000.0, 750.0], [$r->repairLimit(), $r->emergencyLimit(), $r->calloutAllowedUpTo()]);
    }

    public function test_confirmed_by_owner_counts_and_no_callout_means_must_ask(): void
    {
        $this->capture();
        app(RentalOwnerFaultSettingsService::class)->ownerConfirm($this->property, $this->landlord, $this->values(['callout_mode' => 'no', 'callout_limit' => null]));
        $r = $this->resolver();
        $this->assertSame('confirmed_by_owner', $r->status());
        $this->assertSame([1500.0, 5000.0], [$r->repairLimit(), $r->emergencyLimit()]);
        $this->assertNull($r->calloutAllowedUpTo(), 'the owner said No: a call-out always goes to the owner');
        $this->assertNotNull($r->confirmedAt());
    }

    public function test_a_stored_zero_is_a_real_value_and_a_missing_value_is_must_ask(): void
    {
        $this->capture(['repair_limit' => 0, 'emergency_limit' => null]);
        $r = $this->resolver();
        $this->assertSame(0.0, $r->repairLimit(), 'R0 = the agent may authorise nothing without asking - a value');
        $this->assertNull($r->emergencyLimit(), 'no value stored = must ask');
    }

    public function test_a_dispute_makes_everything_must_ask_until_the_owner_confirms_or_the_agent_recaptures(): void
    {
        $row = $this->capture();
        $svc = app(RentalOwnerFaultSettingsService::class);
        $this->assertSame('recorded', $svc->dispute($this->property, $this->landlord, (int) $row->version, 'Too high'));
        $r = $this->resolver();
        $this->assertSame('disputed', $r->status());
        $this->assertFalse($r->hasStandingAuthority());
        $this->assertSame([null, null, null], [$r->repairLimit(), $r->emergencyLimit(), $r->calloutAllowedUpTo()]);
        $this->assertSame(1500.0, $r->values()['repair_limit'], 'the stored values are still there for the screens');

        // way 1: the owner confirms their own
        $svc->ownerConfirm($this->property, $this->landlord, $this->values(['repair_limit' => 800]));
        $this->assertSame([800.0, 'confirmed_by_owner'], [$this->resolver()->repairLimit(), $this->resolver()->status()]);

        // way 2: dispute again, then the agent re-captures with new evidence
        $svc->dispute($this->property, $this->landlord, (int) $this->row()->version, null);
        $this->assertSame('disputed', $this->resolver()->status());
        $svc->capture($this->property, $this->agent, $this->values(['repair_limit' => 900]), 'email', now(), 'Owner emailed the new amounts.');
        $r = $this->resolver();
        $this->assertSame(['captured_by_agent', 900.0], [$r->status(), $r->repairLimit()]);
        $this->assertNull($this->row()->disputed_at, 'a re-capture clears the dispute');
    }

    public function test_the_resolver_hands_cc5s_first_responders_through(): void
    {
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $this->resolver()->firstResponders());
        $this->assertTrue($this->resolver()->firstResponders()->isEmpty());
    }

    // ── 2. the carry-over ────────────────────────────────────────────────────

    public function test_the_existing_limit_is_carried_over_as_captured_by_agent_and_no_owner_is_emailed(): void
    {
        $this->property->forceFill(['rental_no_approval_spend_threshold' => 1200, 'rental_work_terms_updated_at' => now()->subDays(3), 'rental_work_terms_updated_by_user_id' => $this->agent->id])->saveQuietly();
        $untouched = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => 'No limit', 'status' => 'active', 'listing_type' => 'rental']);

        $migration = require base_path('database/migrations/2026_10_27_100000_owner_fault_handling_settings.php');
        $migration->up();
        $migration->up();   // safe to run twice

        $row = $this->row();
        $this->assertSame(['captured_by_agent', 1200.0, 'carried_over', 'Carried over from the existing work terms', 1], [$row->status, (float) $row->repair_limit, $row->capture_basis, $row->capture_note, (int) $row->version]);
        $this->assertNull($row->emergency_limit);
        $this->assertNull($row->callout_mode);
        $this->assertNull($this->row($untouched), 'a property with no limit stays "not set"');
        $this->assertSame(['carried_over'], $this->changes()->pluck('action')->all(), 'one history line, however often it ran');
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        // the old column is untouched
        $this->assertSame(1200.0, (float) $this->property->fresh()->rental_no_approval_spend_threshold);
        // and it counts: captured, with only the repair limit
        $r = $this->resolver();
        $this->assertSame([1200.0, null, null], [$r->repairLimit(), $r->emergencyLimit(), $r->calloutAllowedUpTo()]);
    }

    public function test_the_property_banner_says_the_owner_has_not_confirmed_and_send_to_owner_emails_them(): void
    {
        $this->property->forceFill(['rental_no_approval_spend_threshold' => 1200])->saveQuietly();
        (require base_path('database/migrations/2026_10_27_100000_owner_fault_handling_settings.php'))->up();

        $html = $this->actingAs($this->agent)->get(route('corex.properties.show', $this->property) . '?tab=rental')->assertOk()->getContent();
        $this->assertStringContainsString('data-owner-not-confirmed-banner', $html);
        $this->assertStringContainsString('Owner has not confirmed these yet.', $html);
        $this->assertStringContainsString('Send to owner to confirm', $html);
        $this->assertStringContainsString('carried over from the existing work terms', $html);

        $this->actingAs($this->agent)->post(route('corex.properties.rental-owner-fault-settings.send', $this->property))->assertRedirect()->assertSessionHas('success');
        Mail::assertQueued(RentalOwnerFaultSettingsMail::class, fn ($m) => $m->hasTo($this->landlord->email));
        $this->assertSame(['carried_over', 'sent_to_owner'], $this->changes()->pluck('action')->all());
        $this->assertNotNull($this->row()->last_sent_to_owner_at);
    }

    // ── 3. the agent captures for the owner ──────────────────────────────────

    private function agentCapture(array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->agent)->post(route('corex.properties.rental-owner-fault-settings.capture', $this->property), array_merge([
            'repair_limit' => '1500', 'emergency_limit' => '5000', 'callout_mode' => 'yes', 'callout_limit' => '750',
            'basis' => 'phone_call', 'occurred_at' => now()->subDay()->toDateString(), 'note' => 'Owner phoned and agreed to these amounts.',
        ], $over));
    }

    public function test_an_agent_capture_stores_values_evidence_and_history_and_emails_the_owner_at_once(): void
    {
        $this->agentCapture(['evidence' => UploadedFile::fake()->create('owner-email.pdf', 40, 'application/pdf')])->assertRedirect()->assertSessionHas('success')->assertSessionHasNoErrors();

        $row = $this->row();
        $this->assertSame(['captured_by_agent', 1500.0, 5000.0, 'yes', 750.0, 'phone_call', $this->agent->id, 1], [$row->status, (float) $row->repair_limit, (float) $row->emergency_limit, $row->callout_mode, (float) $row->callout_limit, $row->capture_basis, $row->captured_by_user_id, (int) $row->version]);
        $this->assertSame('Owner phoned and agreed to these amounts.', $row->capture_note);
        $this->assertSame('owner-email.pdf', $row->capture_file_name);
        Storage::disk('local')->assertExists($row->capture_file_path);
        $this->assertSame(['captured'], $this->changes()->pluck('action')->all());
        $this->assertSame($this->agent->id, $this->changes()->first()->user_id);

        Mail::assertQueued(RentalOwnerFaultSettingsMail::class, 1);
        Mail::assertQueued(RentalOwnerFaultSettingsMail::class, function (RentalOwnerFaultSettingsMail $m) {
            $text = preg_replace('/\s+/', ' ', strip_tags($m->render()));
            $this->assertTrue($m->hasTo($this->landlord->email));
            $this->assertStringContainsString('up to R1 500', $text);
            $this->assertStringContainsString('up to R5 000', $text);
            $this->assertStringContainsString('yes, up to R750', $text);
            $this->assertStringContainsString('Agnes Agent recorded this', $text, 'who captured it');
            $this->assertStringContainsString('you told us by phone call on', $text, 'the evidence basis');
            $this->assertStringContainsString('Owner phoned and agreed to these amounts.', $text);
            $html = $m->render();
            $this->assertSame(1, preg_match_all('/data-open-my-settings/', $html));
            $this->assertSame(1, preg_match_all('/data-i-do-not-agree/', $html));
            $this->assertStringContainsString('Open my settings', $html);
            $this->assertStringContainsString('I do not agree with this', $html);
            $this->assertSame(2, preg_match_all('/<td width="50%"/', $html), 'two equal cells');
            $this->assertStringContainsString('as=owner', $m->openUrl);
            $this->assertStringContainsString('handling=' . $this->property->id, $m->openUrl);
            $this->assertStringContainsString('signature=', $m->disagreeUrl);
            $this->assertStringContainsString('v=1', $m->disagreeUrl);

            return true;
        });
    }

    public function test_an_agent_capture_needs_evidence_a_note_and_sensible_values(): void
    {
        foreach ([['note' => ''], ['basis' => ''], ['basis' => 'carried_over'], ['occurred_at' => now()->addDay()->toDateString()], ['occurred_at' => ''],
            ['callout_mode' => 'yes', 'callout_limit' => ''], ['callout_mode' => ''], ['repair_limit' => ''], ['emergency_limit' => '-5']] as $bad) {
            $this->agentCapture($bad)->assertSessionHasErrors();
        }
        $this->assertNull($this->row(), 'nothing saved by any refused attempt');
        Mail::assertNothingQueued();

        // No -> the amount is not needed and is not kept
        $this->agentCapture(['callout_mode' => 'no', 'callout_limit' => '999'])->assertSessionHasNoErrors();
        $this->assertSame(['no', null], [$this->row()->callout_mode, $this->row()->callout_limit]);
        // R0 is allowed: the owner wants to be asked every time
        $this->agentCapture(['repair_limit' => '0', 'emergency_limit' => '0'])->assertSessionHasNoErrors();
        $this->assertSame(0.0, $this->resolver()->repairLimit());
    }

    public function test_capture_is_for_permitted_agents_on_their_own_agencys_settled_rentals_only(): void
    {
        $theirs = $this->otherAgencyProperty();
        $this->actingAs($this->agent)->post(route('corex.properties.rental-owner-fault-settings.capture', $theirs), [
            'repair_limit' => 1, 'emergency_limit' => 1, 'callout_mode' => 'no', 'basis' => 'email', 'occurred_at' => now()->toDateString(), 'note' => 'nope',
        ])->assertNotFound();   // another agency's property does not exist for this agent
        $this->assertSame(0, Row::withoutGlobalScopes()->count(), 'and nothing is written');

        $sale = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => 'For sale', 'status' => 'active', 'listing_type' => 'sale']);
        $this->actingAs($this->agent)->post(route('corex.properties.rental-owner-fault-settings.capture', $sale), [
            'repair_limit' => 1, 'emergency_limit' => 1, 'callout_mode' => 'no', 'basis' => 'email', 'occurred_at' => now()->toDateString(), 'note' => 'nope',
        ])->assertForbidden();
        $this->assertNull($this->row($sale));
        Mail::assertNothingQueued();
    }

    // ── 4. the owner, in the portal ──────────────────────────────────────────

    public function test_the_owner_sees_the_suggestion_when_nothing_is_recorded_and_confirms_their_own(): void
    {
        $this->portal();
        $url = '/api/v1/client/rentals/landlord/properties/' . $this->property->id . '/fault-settings';
        $d = $this->getJson($url)->assertOk()->json('fault_settings');
        $this->assertEquals(['not_set', true, 500], [$d['status'], $d['suggested'], $d['values']['repair_limit']]);
        $this->assertStringContainsString('ask you before every decision', $d['status_label']);

        $r = $this->postJson($url, ['repair_limit' => 2000, 'emergency_limit' => 6000, 'callout_mode' => 'no'])->assertOk();
        $this->assertSame('confirmed', $r->json('result'));
        $this->assertSame('confirmed_by_owner', $r->json('fault_settings.status'));
        $row = $this->row();
        $this->assertSame([$this->landlord->id, 'confirmed_by_owner'], [$row->confirmed_by_contact_id, $row->status]);
        $this->assertSame([2000.0, 6000.0, null], [$this->resolver()->repairLimit(), $this->resolver()->emergencyLimit(), $this->resolver()->calloutAllowedUpTo()]);
        $this->assertSame(['confirmed'], $this->changes()->pluck('action')->all());
        $this->assertStringContainsString('Lenny Landlord (owner) confirmed', $r->json('fault_settings.history.0.text'));
    }

    public function test_the_owner_confirming_what_the_agent_recorded_is_a_confirmation_and_changing_it_is_a_change(): void
    {
        $this->capture();
        $this->portal();
        $url = '/api/v1/client/rentals/landlord/properties/' . $this->property->id . '/fault-settings';

        $d = $this->getJson($url)->json('fault_settings');
        $this->assertEquals(['captured_by_agent', false, 1500], [$d['status'], $d['suggested'], $d['values']['repair_limit']]);
        $this->assertStringContainsString('Agnes Agent recorded this', $d['captured_line']);
        $this->assertStringContainsString('you told us by phone call', $d['captured_line']);

        $this->assertSame('confirmed', $this->postJson($url, $this->values())->json('result'));
        // (the portal's one-press ledger answers an exact repeat with the first answer: a NEW press carries its own submission key, as the page's does)
        $this->assertSame('unchanged', $this->postJson($url, $this->values(), ['X-Submission-Key' => 'again-' . uniqid()])->json('result'), 'pressing it again changes nothing and logs nothing');
        $this->assertSame('changed', $this->postJson($url, $this->values(['repair_limit' => 900]))->json('result'));
        $this->assertSame(['captured', 'confirmed', 'owner_changed'], $this->changes()->pluck('action')->all());
        $this->assertSame(900.0, $this->resolver()->repairLimit());
        $last = $this->changes()->last();
        $this->assertEquals([1500, 900], [json_decode($last->old_values, true)['repair_limit'], json_decode($last->new_values, true)['repair_limit']]);
    }

    public function test_the_owners_save_is_validated_in_plain_words_and_scoped_to_their_own_properties(): void
    {
        $theirs = $this->otherAgencyProperty();
        $mine = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => 'Somebody else', 'status' => 'active', 'listing_type' => 'rental']);
        $this->portal();
        $url = '/api/v1/client/rentals/landlord/properties/' . $this->property->id . '/fault-settings';
        $this->postJson($url, ['emergency_limit' => 1, 'callout_mode' => 'no'])->assertStatus(422)->assertJsonValidationErrors('repair_limit');
        $e = $this->postJson($url, ['repair_limit' => 1, 'emergency_limit' => 1, 'callout_mode' => 'yes'])->assertStatus(422);
        $this->assertSame('Enter the call-out amount you allow, or choose No.', $e->json('errors.callout_limit.0'));
        $this->assertNull($this->row());

        $this->getJson('/api/v1/client/rentals/landlord/properties/' . $theirs->id . '/fault-settings')->assertNotFound();
        $this->postJson('/api/v1/client/rentals/landlord/properties/' . $theirs->id . '/fault-settings', $this->values())->assertNotFound();
        // a property of this agency that is not THIS owner's
        $this->getJson('/api/v1/client/rentals/landlord/properties/' . $mine->id . '/fault-settings')->assertNotFound();
        $this->assertNull($this->row($mine));
    }

    public function test_the_ask_for_a_change_link_tells_both_lease_agents_and_changes_nothing(): void
    {
        $this->portal();
        $this->postJson('/api/v1/client/rentals/landlord/properties/' . $this->property->id . '/fault-settings/change-request', ['note' => 'Please use my brother first'])->assertOk();

        $this->assertEqualsCanonicalizing([[$this->ownerAgent->id, 'rental_fault_settings.change_requested'], [$this->tenantAgent->id, 'rental_fault_settings.change_requested']], $this->alerts);
        $this->assertSame(['change_requested'], $this->changes()->pluck('action')->all());
        $this->assertStringContainsString('Please use my brother first', DB::table('rental_property_fault_setting_changes')->value('note'));
        $this->assertSame('not_set', $this->resolver()->status(), 'asking for a change does not change anything by itself');
    }

    // ── 5. "I do not agree with this" ────────────────────────────────────────

    private function disagreeUrl(Row $row, ?Contact $who = null): string
    {
        return URL::temporarySignedRoute('rentals.fault-settings.dispute', now()->addDays(5), ['property' => $row->property_id, 'contact' => ($who ?? $this->landlord)->id, 'v' => (int) $row->version]);
    }

    public function test_the_disagree_link_only_asks_on_a_get_and_records_on_the_owners_own_press(): void
    {
        $row = $this->capture();
        $url = $this->disagreeUrl($row);

        // a mail scanner opening the link records nothing
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('data-dispute-page="ask"', $html);
        $this->assertStringContainsString('data-confirm-dispute', $html);
        $this->assertSame('captured_by_agent', $this->row()->status);
        $this->assertSame([], $this->alerts);

        $done = $this->post($url, ['reason' => 'R1 500 is too much'])->assertOk()->getContent();
        $this->assertStringContainsString('data-dispute-page="recorded"', $done);
        $row = $this->row();
        $this->assertSame(['disputed', $this->landlord->id, 'R1 500 is too much'], [$row->status, $row->disputed_by_contact_id, $row->dispute_note]);
        $this->assertNotNull($row->disputed_at);
        $this->assertSame(['captured', 'disputed'], $this->changes()->pluck('action')->all());
        $this->assertEqualsCanonicalizing([[$this->ownerAgent->id, 'rental_fault_settings.disputed'], [$this->tenantAgent->id, 'rental_fault_settings.disputed']], $this->alerts, 'BOTH lease agents are told');
        $this->assertSame('disputed', $this->resolver()->status());
        $this->assertNull($this->resolver()->repairLimit());
        // ... and both agents are EMAILED as well (in-app alone depends on each agent's own switches)
        Mail::assertQueued(\App\Mail\Rentals\RentalFaultSettingsDisputedMail::class, 2);
        foreach ([$this->ownerAgent, $this->tenantAgent] as $a) {
            Mail::assertQueued(\App\Mail\Rentals\RentalFaultSettingsDisputedMail::class, fn ($m) => $m->hasTo($a->email) && str_contains($m->body, 'R1 500 is too much'));
        }

        // pressing it again says so and tells nobody twice
        $this->alerts = [];
        $this->assertStringContainsString('data-dispute-page="already"', $this->post($url)->getContent());
        $this->assertSame([], $this->alerts);
        $this->assertCount(2, $this->changes());
    }

    public function test_an_old_link_after_a_new_capture_is_told_the_settings_changed_and_records_nothing(): void
    {
        $row = $this->capture();
        $old = $this->disagreeUrl($row);
        $this->capture(['repair_limit' => 2500]);   // version 2

        $this->assertStringContainsString('data-dispute-page="stale"', $this->get($old)->getContent());
        $this->assertStringContainsString('data-dispute-page="stale"', $this->post($old)->getContent());
        $this->assertSame('captured_by_agent', $this->row()->status);
        $this->assertSame([], $this->alerts);
        $this->assertSame(2500.0, $this->resolver()->repairLimit());
    }

    public function test_the_disagree_link_cannot_be_forged_or_used_for_somebody_elses_property(): void
    {
        $row = $this->capture();
        $unsigned = route('rentals.fault-settings.dispute', ['property' => $row->property_id, 'contact' => $this->landlord->id, 'v' => 1]);
        $this->get($unsigned)->assertForbidden();
        $this->post($unsigned)->assertForbidden();
        $tampered = str_replace('v=1', 'v=2', $this->disagreeUrl($row));
        $this->get($tampered)->assertForbidden();

        $stranger = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'first_name' => 'Sam', 'last_name' => 'Stranger', 'email' => 'sam+' . uniqid() . '@example.invalid']);
        $this->get($this->disagreeUrl($row, $stranger))->assertNotFound();
        $this->assertSame('captured_by_agent', $this->row()->status);
    }

    // ── 6. the agent's screen, in every status ───────────────────────────────

    public function test_the_agent_screen_shows_each_status_its_values_and_the_history(): void
    {
        $page = fn () => $this->actingAs($this->agent)->get(route('corex.properties.show', $this->property) . '?tab=rental')->assertOk()->getContent();

        $html = $page();
        $this->assertStringContainsString('data-status="not_set"', $html);
        $this->assertStringContainsString('Not set yet', $html);
        $this->assertStringContainsString('Suggested by the agency: R500', $html);
        $this->assertStringContainsString('Save for the owner and tell them', $html);
        $this->assertStringContainsString('How the owner told us', $html);
        $this->assertStringNotContainsString('data-owner-not-confirmed-banner', $html);

        $this->capture();
        $html = $page();
        $this->assertStringContainsString('data-status="captured_by_agent"', $html);
        $this->assertStringContainsString('data-owner-not-confirmed-banner', $html);
        $this->assertStringContainsString('R1 500', $html);
        $this->assertStringContainsString('Agnes Agent recorded these for the owner', $html);

        app(RentalOwnerFaultSettingsService::class)->dispute($this->property, $this->landlord, (int) $this->row()->version, 'No thanks');
        $html = $page();
        $this->assertStringContainsString('data-owner-disputed-banner', $html);
        $this->assertStringContainsString('The owner does not agree with these settings', $html);
        $this->assertStringContainsString('No thanks', $html);

        app(RentalOwnerFaultSettingsService::class)->ownerConfirm($this->property, $this->landlord, $this->values());
        $html = $page();
        $this->assertStringContainsString('data-status="confirmed_by_owner"', $html);
        $this->assertStringContainsString('Confirmed by the owner on', $html);
        $this->assertStringNotContainsString('data-owner-not-confirmed-banner', $html);
        $this->assertStringNotContainsString('data-owner-disputed-banner', $html);
    }

    public function test_the_owner_portal_page_carries_the_section_and_the_deep_link_target(): void
    {
        $html = $this->get('/portal')->assertOk()->getContent();
        $this->assertStringContainsString('How I want faults handled', $html);
        $this->assertStringContainsString('data-open-handling', $html);
        $this->assertStringContainsString('data-confirm-handling', $html);
        $this->assertStringContainsString("num('handling')", $html);
        $this->assertContains('handling', \App\Support\PortalLink::TARGETS);
    }

    // ── 7. the agent panel is short, aligned, and says things once (Johan's 12 Oct walk of property 6069) ──

    public function test_the_panel_is_trimmed_aligned_and_says_not_set_for_a_missing_value_and_carried_over_once(): void
    {
        $this->property->forceFill(['rental_no_approval_spend_threshold' => 1200])->saveQuietly();
        (require base_path('database/migrations/2026_10_27_100000_owner_fault_handling_settings.php'))->up();

        $html = $this->actingAs($this->agent)->get(route('corex.properties.show', $this->property) . '?tab=rental')->assertOk()->getContent();
        $flat = preg_replace('/\s+/', ' ', strip_tags($html));

        $this->assertStringContainsString("The owner's own choices. Until confirmed, every decision goes to the owner.", $flat);
        $this->assertStringNotContainsString("These are the OWNER's decisions", $flat);
        $this->assertStringNotContainsString('does not count as a standing', $flat);

        // the three value rows are label / value pairs in ONE grid, and a missing answer reads "not set" (never blank)
        $this->assertSame(1, preg_match('/<div class="grid text-xs" style="grid-template-columns: minmax\(0, 1fr\) auto;[^"]*" data-owner-fault-values>(.*?)<\/div>\s*(?:@|<p|<details|<div)/s', $html) ? 1 : 0, 'one grid');
        $this->assertSame(1, preg_match('/data-v-repair>R1 200<\/div>/', $html));
        $this->assertSame(1, preg_match('/data-v-emergency>not set<\/div>/', $html));
        $this->assertSame(1, preg_match('/data-v-callout>not set<\/div>/', $html), 'the call-out line says "not set" like the emergency line');

        // "carried over" is said ONCE in the evidence line - the note is not repeated beside it
        $evidence = preg_replace('/\s+/', ' ', strip_tags(preg_match('/<p[^>]*data-owner-fault-evidence>(.*?)<\/p>/s', $html, $m) ? $m[1] : ''));
        $this->assertSame(1, substr_count(strtolower($evidence), 'carried over'), $evidence);
        $this->assertStringNotContainsString('"Carried over', $evidence);

        // the two buttons have one size
        $this->assertSame(1, preg_match_all('/data-send-to-owner/', $html));
        $this->assertSame(2, preg_match_all('/style="width: 16rem; max-width: 100%;" data-(?:send-to-owner|save-owner-fault-settings)/', $html), 'both buttons 16rem wide');
    }
}
