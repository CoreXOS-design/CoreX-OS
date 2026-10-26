<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\LeaseAgreementTerms;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalInspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Concerns\BuildsMobileRentalsWorld;
use Tests\TestCase;

/**
 * AT-508 P4 - the My Lease card (tenant + owner), the tenant's "urgent" flag, camera photos, and the queued-write rules on the client side.
 * .ai/specs/at-508-mobile-rentals.md section 4 P4, section 13 (Johan's rulings 1 and 3).
 */
final class MobileRentalsP4Test extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileRentalsWorld;

    private const CLIENT = '/api/v1/client/rentals';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        Storage::fake('local');
    }

    /** every key in the payload, dotted */
    private function keys(array $a, string $prefix = ''): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            $out[] = $prefix . $k;
            if (is_array($v)) {
                $out = array_merge($out, $this->keys($v, $prefix . $k . '.'));
            }
        }

        return $out;
    }

    // ── My Lease: tenant ─────────────────────────────────────────────────────────────────────

    public function test_the_tenants_my_lease_has_the_lease_facts_the_agent_phone_and_email_and_nothing_about_the_lessor(): void
    {
        $res = $this->asClient($this->tenantLogin)->getJson(self::CLIENT . '/my-lease')->assertOk();
        $card = $res->json('leases.0');

        $this->assertSame($this->lease->start_date->toDateString(), $card['lease']['start_date']);
        $this->assertSame($this->lease->end_date->toDateString(), $card['lease']['end_date']);
        $this->assertGreaterThan(250, $card['lease']['time_left']['days']);
        $this->assertStringContainsString('months left', $card['lease']['time_left']['text']);
        $this->assertSame('running', $card['status']['state']);
        $this->assertNull($card['notice_period'], 'not captured on this lease - null, never a guess');
        $this->assertNull($card['next_inspection']);

        // the managing agent: name, phone, email - no WhatsApp number
        $this->assertSame(['name', 'phone', 'email'], array_keys($card['agent']));
        $this->assertSame($this->agent->name, $card['agent']['name']);
        $this->assertSame('0821112222', $card['agent']['phone']);
        $this->assertSame('agent@cape.test', $card['agent']['email']);

        // Johan's rulings: nothing about the lessor, and no money
        $keys = implode(' ', $this->keys($res->json()));
        $this->assertDoesNotMatchRegularExpression('/owner|landlord|lessor|whatsapp|rent|deposit|payment|wallet/i', $keys);
        $this->assertStringNotContainsString('Lenny', $res->getContent());
    }

    public function test_a_confirmed_notice_period_and_the_next_inspection_show_and_a_landlord_notice_never_says_who_gave_it(): void
    {
        $terms = LeaseAgreementTerms::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'notice_period' => 2, 'notice_period_unit' => 'months']);
        $this->asClient($this->tenantLogin)->getJson(self::CLIENT . '/my-lease')->assertJsonPath('leases.0.notice_period', null);   // typed but not confirmed
        $terms->forceFill(['notice_terms_confirmed_at' => now()])->save();
        RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'property_id' => $this->property->id, 'type' => 'in', 'status' => 'draft',
            'created_by_user_id' => $this->agent->id, 'scheduled_for' => now()->addDays(5)->toDateString(), 'scheduled_time' => '10:30:00',
        ]);
        $this->lease->forceFill(['notice_date' => now()->toDateString(), 'notice_given_by' => 'landlord', 'move_out_date' => now()->addMonths(2)->toDateString()])->saveQuietly();

        $card = $this->asClient($this->tenantLogin)->getJson(self::CLIENT . '/my-lease')->assertOk()->json('leases.0');
        $this->assertSame(['value' => 2, 'unit' => 'months'], $card['notice_period']);
        $this->assertSame(now()->addDays(5)->toDateString(), $card['next_inspection']['date']);
        $this->assertSame('10:30', $card['next_inspection']['time']);
        $this->assertSame('notice_given', $card['status']['state']);
        $this->assertSame('Notice has been given', $card['status']['headline']);
        $this->assertSame('other_party', $card['notice']['given_by']);
    }

    public function test_an_owner_cannot_read_the_tenant_card_and_a_tenant_cannot_read_the_owner_card(): void
    {
        // a person with no tenancy gets an empty card list, never someone else's lease; a person with no property gets no property block
        $this->asClient($this->tenantLogin)->getJson(self::CLIENT . '/landlord/my-lease')->assertOk()->assertJsonPath('properties', []);
        $this->asClient($this->ownerLogin)->getJson(self::CLIENT . '/my-lease')->assertOk()->assertJsonPath('leases', []);
        // and a tab that was given a side is refused the other side's card outright
        $this->asClient($this->tenantLogin)->withHeaders(['X-Portal-Role' => 'tenant'])->getJson(self::CLIENT . '/landlord/my-lease')->assertStatus(403)->assertJsonPath('role_mismatch', true);
    }

    // ── My Lease: owner ──────────────────────────────────────────────────────────────────────

    public function test_the_owners_my_lease_is_one_block_per_property_with_the_tenants_name_and_a_vacant_property_has_no_lease(): void
    {
        $vacant = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => '3 Empty Lane', 'address' => '3 Empty Lane', 'status' => 'active', 'listing_type' => 'rental']);
        $this->property->contacts()->where('contacts.id', $this->owner->id)->exists();
        $vacant->contacts()->attach($this->owner->id, ['role' => 'landlord']);

        $res = $this->asClient($this->ownerLogin)->getJson(self::CLIENT . '/landlord/my-lease')->assertOk();
        $this->assertCount(2, $res->json('properties'));
        $let = collect($res->json('properties'))->firstWhere('property.id', $this->property->id);
        $empty = collect($res->json('properties'))->firstWhere('property.id', $vacant->id);

        $this->assertSame($this->lease->id, $let['lease']['id']);
        $this->assertStringContainsString('Tina', (string) $let['tenant_names'], 'the owner keeps seeing the tenant');
        $this->assertSame($this->agent->name, $let['agent']['name']);
        $this->assertNull($empty['lease']);
        $this->assertNull($empty['status']);
    }

    // ── urgent, captured_at, camera photo ────────────────────────────────────────────────────

    private function report(array $extra = [], ?string $key = null)
    {
        $payload = array_merge(['rental_fault_type_id' => $this->faultType->id, 'resolution' => 'still_a_problem', 'title' => 'Burst geyser', 'description' => 'Water everywhere'], $extra);
        $this->asClient($this->tenantLogin);

        return $key ? $this->withHeaders(['X-Client-Idempotency-Key' => $key])->post(self::CLIENT . "/properties/{$this->property->id}/fault-reports", $payload, ['Accept' => 'application/json'])
            : $this->postJson(self::CLIENT . "/properties/{$this->property->id}/fault-reports", $payload);
    }

    public function test_an_urgent_report_is_treated_as_an_emergency_and_the_agent_is_alerted_at_the_elevated_level(): void
    {
        $res = $this->report(['urgent' => true])->assertStatus(201);
        $this->assertTrue($res->json('fault_report.urgent'));
        $fault = RentalFaultReport::withoutGlobalScopes()->findOrFail($res->json('fault_report.id'));
        $this->assertTrue($fault->isOpenEmergency());
        $this->assertSame('routine', $fault->faultType->urgency, 'the catalogue type is untouched');

        $alert = \Illuminate\Support\Facades\DB::table('notifications')->where('notifiable_id', $this->agent->id)->get()
            ->first(fn ($n) => str_contains((string) $n->data, 'EMERGENCY fault reported'));
        $this->assertNotNull($alert, 'the agent got the emergency-titled alert');

        $calm = $this->report(['title' => 'Slow drain'])->assertStatus(201);
        $this->assertFalse($calm->json('fault_report.urgent'));
    }

    public function test_a_report_queued_offline_keeps_the_time_the_phone_took_it_never_a_future_one(): void
    {
        $then = now()->subHours(5)->startOfMinute();
        $id = $this->report(['captured_at' => $then->toIso8601String()])->assertStatus(201)->json('fault_report.id');
        $this->assertSame($then->timestamp, RentalFaultReport::withoutGlobalScopes()->find($id)->reported_at->timestamp);

        $id2 = $this->report(['captured_at' => now()->addDays(3)->toIso8601String(), 'title' => 'Another'])->assertStatus(201)->json('fault_report.id');
        $this->assertLessThanOrEqual(now()->timestamp, RentalFaultReport::withoutGlobalScopes()->find($id2)->reported_at->timestamp);
    }

    public function test_a_camera_photo_is_accepted_and_a_replayed_queued_report_makes_one_fault_and_answers_duplicate(): void
    {
        $key = 'b0b0b0b0-1111-4222-8333-444455556666';
        $first = $this->report(['photos' => [UploadedFile::fake()->image('camera.jpg', 640, 480)]], $key)->assertStatus(201);
        $this->assertSame(1, RentalFaultReport::withoutGlobalScopes()->find($first->json('fault_report.id'))->photos()->count());

        $again = $this->report(['photos' => [UploadedFile::fake()->image('camera.jpg', 640, 480)]], $key)->assertOk();
        $this->assertTrue($again->json('duplicate'));
        $this->assertSame($first->json('fault_report.id'), $again->json('fault_report.id'), 'the same record comes back');
        $this->assertSame(1, RentalFaultReport::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count());
    }

    public function test_a_queued_note_for_a_withdrawn_fault_is_told_it_is_gone_so_the_app_drops_it(): void
    {
        $id = $this->report()->assertStatus(201)->json('fault_report.id');
        $this->asClient($this->tenantLogin)->postJson(self::CLIENT . "/fault-reports/{$id}/withdraw", ['reason' => 'sorted it myself'])->assertSuccessful();

        $this->asClient($this->tenantLogin)->postJson(self::CLIENT . "/fault-reports/{$id}/notes", ['note' => 'still dripping'])
            ->assertStatus(410)->assertJsonPath('code', 'archived');
        $this->asClient($this->tenantLogin)->postJson(self::CLIENT . '/fault-reports/999999/notes', ['note' => 'x'])->assertStatus(404);
    }
}
