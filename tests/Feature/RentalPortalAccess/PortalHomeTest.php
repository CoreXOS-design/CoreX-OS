<?php

declare(strict_types=1);

namespace Tests\Feature\RentalPortalAccess;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseSetting;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalPortalSetting;
use App\Models\SignedDocumentDistributionLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * .ai/specs/rental-portal-access.md §20 — Johan, 8 Oct 2026: the portal home shows the managing agent's contact, the
 * inspection dates and where the lease stands, for the tenant and the owner; and the older inspections list hides anything
 * that has not been sent. Each only for the person it belongs to.
 */
final class PortalHomeTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = ['Origin' => 'http://localhost'];

    private Agency $agency;
    private Agency $other;
    private Branch $branch;
    private User $agent;        // the agent who captured the lease
    private User $propAgent;    // the property's own agent
    private User $gone;         // an agent who has left
    private Property $p1;
    private Property $p2;
    private Property $p3;       // owned by owner1, nobody renting it
    private Contact $tenant;
    private Contact $tenant2;
    private Contact $owner1;
    private Contact $owner2;
    private Lease $l1;
    private Lease $l2;
    private ?ClientUser $currentLogin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-08 09:00:00'));

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid(), 'phone' => '021 000 0000', 'email' => 'office@cape.test', 'address' => '1 Long Street, Cape Town']);
        $this->other = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point', 'code' => 'SP-' . $this->agency->id, 'is_active' => true, 'phone' => '021 111 1111', 'email' => 'seapoint@cape.test', 'address' => '9 Beach Road, Sea Point']);

        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Anele Agent', 'cell' => '082 111 2222', 'phone' => '021 222 3333', 'designation' => 'Property Practitioner', 'email' => 'anele.login@cape.test', 'display_email' => 'anele@cape.test', 'is_active' => true]);
        $this->propAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Pieter Prop', 'cell' => null, 'phone' => '021 444 5555', 'email' => 'pieter@cape.test', 'is_active' => true]);
        $this->gone = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Gone Gary', 'email' => 'gary@cape.test', 'is_active' => false]);

        $this->p1 = $this->property('Unit 33, 401 Margate Boulevard', $this->propAgent);
        $this->p2 = $this->property('7 Beach Road', $this->propAgent);
        $this->p3 = $this->property('12 Empty Lane', $this->propAgent);
        $this->tenant = $this->contact('Ayanda');
        $this->tenant2 = $this->contact('Other');
        $this->owner1 = $this->contact('Siyabonga');
        $this->owner2 = $this->contact('Owen');
        foreach ([[$this->owner1, $this->p1], [$this->owner1, $this->p3], [$this->owner2, $this->p2]] as [$o, $p]) {
            DB::table('contact_property')->insert(['contact_id' => $o->id, 'property_id' => $p->id, 'role' => 'landlord', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->l1 = $this->lease($this->p1, [$this->tenant], ['status' => 'active', 'start_date' => '2026-06-01', 'end_date' => '2027-05-31', 'signing_status' => 'signed', 'signed_at' => '2026-05-20 10:00:00', 'created_by_user_id' => $this->agent->id]);
        // The agent who captured this lease has left, so the property's own agent is the person to call.
        $this->l2 = $this->lease($this->p2, [$this->tenant2], ['status' => 'active', 'start_date' => '2026-03-01', 'end_date' => '2027-02-28', 'signing_status' => 'signed_on_paper', 'created_by_user_id' => $this->gone->id]);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────

    private function property(string $address, ?User $agent, ?Agency $agency = null, ?int $branchId = null): Property
    {
        $agency ??= $this->agency;
        // A fixture is built as staff, never while a portal person is signed in (the property observer expects a staff user).
        Auth::forgetGuards();
        $this->currentLogin = null;

        return Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branchId ?? ($agency->id === $this->agency->id ? $this->branch->id : null), 'agent_id' => $agent?->id,
            'title' => $address, 'status' => 'active', 'listing_type' => 'rental', 'address' => $address,
        ]);
    }

    private function contact(string $first, ?Agency $agency = null): Contact
    {
        $agency ??= $this->agency;
        $c = Contact::create(['agency_id' => $agency->id, 'first_name' => $first, 'last_name' => 'Test', 'email' => strtolower($first) . uniqid() . '@example.test']);
        DB::table('contacts')->where('id', $c->id)->update(['agency_id' => $agency->id]);

        return $c->fresh();
    }

    private function lease(Property $property, array $tenants, array $attrs): Lease
    {
        $lease = Lease::create($attrs + [
            'agency_id' => $property->agency_id, 'branch_id' => $this->branch->id, 'property_id' => $property->id,
            'rental_amount' => 8500, 'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);
        foreach ($tenants as $i => $t) {
            LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $t->id, 'is_primary' => $i === 0]);
        }

        return $lease;
    }

    private function inspection(Lease $lease, string $status, string $type, ?string $date = null, ?string $time = null, bool $sentCopy = false): RentalInspection
    {
        $inspection = RentalInspection::create([
            'agency_id' => $lease->agency_id, 'lease_id' => $lease->id, 'property_id' => $lease->property_id,
            'type' => $type, 'status' => $status, 'created_by_user_id' => $this->agent->id,
            'scheduled_for' => $date, 'scheduled_time' => $time,
            'completed_at' => $status === 'completed' ? now()->subDays(3) : null,
        ]);
        if ($sentCopy) {
            SignedDocumentDistributionLog::create([
                'agency_id' => $lease->agency_id, 'distributable_type' => RentalInspection::class, 'distributable_id' => $inspection->id,
                'channel' => 'email', 'mode' => 'manual', 'recipient_role' => 'tenant', 'recipient_email' => 'x@example.test', 'status' => 'sent',
            ]);
        }

        return $inspection;
    }

    private function asPortal(Contact $contact): ClientUser
    {
        $login = $contact->client_user_id ? ClientUser::find($contact->client_user_id) : null;
        if (! $login) {
            $login = ClientUser::create(['email' => $contact->email, 'current_agency_id' => $contact->agency_id, 'password' => 'x-secret-pass-1', 'activated_at' => now()]);
            $contact->forceFill(['client_user_id' => $login->id])->saveQuietly();
        }
        $this->currentLogin = $login;

        return $login;
    }

    private function portalGet(string $url)
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');
        if ($this->currentLogin) {
            Sanctum::actingAs($this->currentLogin, ['client']);
        }

        return $this->withHeaders(self::BROWSER + ($url === '/portal' ? [] : ['Accept' => 'application/json']))->get($url);
    }

    private function tenantHome(Contact $contact): array
    {
        $this->asPortal($contact);

        return $this->portalGet('/api/v1/client/rentals/overview')->assertOk()->json();
    }

    /** A brand-new tenant on a brand-new property with ONE lease of the given shape; returns that home block (or null). */
    private function tenancyOf(array $leaseAttrs, string $audience = 'tenant'): ?array
    {
        $property = $this->property('Test House ' . uniqid(), $this->propAgent);
        $tenant = $this->contact('T' . uniqid());
        $lease = $this->lease($property, [$tenant], $leaseAttrs + ['status' => 'active', 'signing_status' => 'signed_on_paper']);
        if ($audience === 'landlord') {
            $owner = $this->contact('O' . uniqid());
            DB::table('contact_property')->insert(['contact_id' => $owner->id, 'property_id' => $property->id, 'role' => 'landlord', 'created_at' => now(), 'updated_at' => now()]);
            $this->asPortal($owner);
            $json = $this->portalGet('/api/v1/client/rentals/landlord/overview')->assertOk()->json();
        } else {
            $json = $this->tenantHome($tenant);
        }

        return $json['homes'][0]['tenancy'] ?? null;
    }

    // ── 1. agent contact ─────────────────────────────────────────────────────────────────────

    public function test_the_home_shows_the_agent_on_the_lease_with_the_agency_and_branch_details(): void
    {
        $home = $this->tenantHome($this->tenant)['homes'][0];

        $this->assertStringStartsWith('Unit 33, 401 Margate Boulevard', $home['property']['address']);
        $agent = $home['contact']['agent'];
        $this->assertSame('Anele Agent', $agent['name']);
        $this->assertSame('0821112222', $agent['phone'], 'the cell number first (stored normalised)');
        $this->assertSame('anele@cape.test', $agent['email'], 'the outward-facing address, never the login');
        $this->assertSame('Property Practitioner', $agent['designation']);
        $this->assertSame('Cape Rentals', $home['contact']['office']['agency']);
        $this->assertSame('Sea Point', $home['contact']['office']['branch']);
        $this->assertSame('021 111 1111', $home['contact']['office']['phone'], 'the branch\'s own details first');
        $this->assertSame('seapoint@cape.test', $home['contact']['office']['email']);
        $this->assertSame('9 Beach Road, Sea Point', $home['contact']['office']['address']);
    }

    public function test_an_agent_who_has_left_is_skipped_for_the_propertys_agent_then_the_branch(): void
    {
        $home = $this->tenantHome($this->tenant2)['homes'][0];
        $this->assertSame('Pieter Prop', $home['contact']['agent']['name'], 'the lease creator is inactive → the property\'s agent');
        $this->assertSame('0214445555', $home['contact']['agent']['phone'], 'no cell → their office number');

        // Nobody qualifies any more → the branch is the contact.
        $this->propAgent->forceFill(['is_active' => false])->saveQuietly();
        $home = $this->tenantHome($this->tenant2)['homes'][0];
        $this->assertNull($home['contact']['agent']);
        $this->assertSame('Sea Point', $home['contact']['office']['branch']);
        $this->assertSame('021 111 1111', $home['contact']['office']['phone']);
    }

    public function test_each_side_sees_its_own_agent_the_tenant_the_tenants_agent_and_the_owner_the_owners_agent(): void
    {
        // leases.md §17 — Johan, 8 Oct 2026: the property's listing agent is the owner's agent; a different agent looks
        // after the tenant; the lease says so, and each portal link shows its own side.
        $maggie = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Maggie Tenantside', 'cell' => '083 555 6666', 'is_active' => true]);
        $this->l1->forceFill(['owner_agent_user_id' => $this->propAgent->id, 'tenant_agent_user_id' => $maggie->id])->saveQuietly();

        $this->assertSame('Maggie Tenantside', $this->tenantHome($this->tenant)['homes'][0]['contact']['agent']['name']);
        $this->assertSame('0835556666', $this->tenantHome($this->tenant)['homes'][0]['contact']['agent']['phone']);

        $this->asPortal($this->owner1);
        $homes = collect($this->portalGet('/api/v1/client/rentals/landlord/overview')->assertOk()->json('homes'))->keyBy('property.id');
        $this->assertSame('Pieter Prop', $homes[$this->p1->id]['contact']['agent']['name'], 'the owner is never told the tenant\'s agent');
        $this->assertStringNotContainsString('Maggie', json_encode($homes[$this->p1->id]));

        // The approver / creator fallback is gone: the lease's creator (Anele) is no longer anybody's contact.
        $this->assertStringNotContainsString('Anele', json_encode($this->tenantHome($this->tenant)));
    }

    public function test_a_side_whose_agent_has_left_falls_to_the_propertys_agent_then_the_branch(): void
    {
        $maggie = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Maggie Tenantside', 'is_active' => true]);
        $this->l1->forceFill(['owner_agent_user_id' => $this->agent->id, 'tenant_agent_user_id' => $maggie->id])->saveQuietly();
        $maggie->forceFill(['is_active' => false])->saveQuietly();
        $this->agent->delete();

        $this->assertSame('Pieter Prop', $this->tenantHome($this->tenant)['homes'][0]['contact']['agent']['name'], 'tenant\'s agent left → the property\'s agent');

        $this->asPortal($this->owner1);
        $homes = collect($this->portalGet('/api/v1/client/rentals/landlord/overview')->assertOk()->json('homes'))->keyBy('property.id');
        $this->assertSame('Pieter Prop', $homes[$this->p1->id]['contact']['agent']['name'], 'owner\'s agent removed → the property\'s agent');

        $this->propAgent->forceFill(['is_active' => false])->saveQuietly();
        $this->assertNull($this->tenantHome($this->tenant)['homes'][0]['contact']['agent']);
    }

    public function test_a_stored_agent_of_another_agency_is_never_shown_to_either_side(): void
    {
        $stranger = User::factory()->create(['agency_id' => $this->other->id, 'role' => 'agent', 'name' => 'Stranger Danger', 'cell' => '083 999 9999', 'is_active' => true]);
        $this->l1->forceFill(['owner_agent_user_id' => $stranger->id, 'tenant_agent_user_id' => $stranger->id])->saveQuietly();

        $home = $this->tenantHome($this->tenant)['homes'][0];
        $this->assertSame('Pieter Prop', $home['contact']['agent']['name'], 'skipped → the property\'s agent');
        $this->assertStringNotContainsString('Stranger', json_encode($home));
    }

    public function test_a_branch_without_its_own_details_falls_back_to_the_agency(): void
    {
        $this->branch->forceFill(['phone' => null, 'email' => null, 'address' => null])->saveQuietly();
        $this->agent->forceFill(['is_active' => false])->saveQuietly();
        $this->propAgent->forceFill(['is_active' => false])->saveQuietly();

        $office = $this->tenantHome($this->tenant)['homes'][0]['contact']['office'];
        $this->assertSame('021 000 0000', $office['phone']);
        $this->assertSame('office@cape.test', $office['email']);
        $this->assertSame('1 Long Street, Cape Town', $office['address']);
    }

    public function test_an_agent_of_another_agency_is_never_shown(): void
    {
        $stranger = User::factory()->create(['agency_id' => $this->other->id, 'role' => 'agent', 'name' => 'Stranger Danger', 'cell' => '083 999 9999', 'is_active' => true]);
        $this->l1->forceFill(['created_by_user_id' => $stranger->id])->saveQuietly();
        $this->p1->forceFill(['agent_id' => $stranger->id])->saveQuietly();

        $home = $this->tenantHome($this->tenant)['homes'][0];
        $this->assertNull($home['contact']['agent']);
        $this->assertStringNotContainsString('Stranger', json_encode($home));
        $this->assertStringNotContainsString('0839999999', json_encode($home));
    }

    // ── 3. lease end and renewal ─────────────────────────────────────────────────────────────

    public function test_the_lease_says_where_it_stands_in_plain_words(): void
    {
        $running = $this->tenancyOf(['start_date' => '2026-06-01', 'end_date' => '2027-05-31']);
        $this->assertSame('running', $running['state']);
        $this->assertSame('Lease running', $running['headline']);
        $this->assertSame('Ends 31 May 2027 · 235 days left', $running['detail']);
        $this->assertSame('2026-06-01', $running['start_date']);
        $this->assertSame('2027-05-31', $running['end_date']);
        $this->assertSame(30, $running['notice_period_days'], 'the default notice period');
        $this->assertFalse($running['in_renewal_window']);

        $window = $this->tenancyOf(['start_date' => '2025-12-01', 'end_date' => '2026-11-17']);
        $this->assertSame('renewal_window', $window['state']);
        $this->assertSame('Renewal window', $window['headline']);
        $this->assertSame('Ends 17 Nov 2026 · 40 days left', $window['detail']);
        $this->assertTrue($window['in_renewal_window']);

        $upcoming = $this->tenancyOf(['start_date' => '2026-11-01', 'end_date' => '2027-05-31']);
        $this->assertSame('upcoming', $upcoming['state']);
        $this->assertSame('Starts 1 Nov 2026', $upcoming['headline']);

        $m2m = $this->tenancyOf(['start_date' => '2025-06-01', 'end_date' => '2026-05-31', 'is_month_to_month' => true]);
        $this->assertSame('month_to_month', $m2m['state']);
        $this->assertSame('Month-to-month', $m2m['headline']);

        $ended = $this->tenancyOf(['status' => 'expired', 'start_date' => '2025-06-01', 'end_date' => '2026-05-31']);
        $this->assertSame('ended', $ended['state']);
        $this->assertSame('Lease ended', $ended['headline']);
    }

    public function test_a_renewed_lease_and_notice_given_are_shown_with_the_right_wording_for_each_side(): void
    {
        // Renewed: the next term is named.
        $property = $this->property('Renewal House', $this->propAgent);
        $tenant = $this->contact('Renew');
        $next = $this->lease($property, [$tenant], ['status' => 'draft', 'start_date' => '2027-06-01', 'end_date' => '2028-05-31', 'signing_status' => 'signed']);
        $this->lease($property, [$tenant], ['status' => 'active', 'start_date' => '2026-06-01', 'end_date' => '2027-05-31', 'signing_status' => 'signed', 'renewed_lease_id' => $next->id]);
        $renewed = $this->tenantHome($tenant)['homes'][0]['tenancy'];
        $this->assertSame('renewed', $renewed['state']);
        $this->assertSame('Lease renewed', $renewed['headline']);
        $this->assertSame('New term 1 Jun 2027 – 31 May 2028', $renewed['detail']);
        $this->assertSame(['start_date' => '2027-06-01', 'end_date' => '2028-05-31'], $renewed['renewal']);

        // Notice by the tenant: "You" for the tenant, "The tenant" for the owner.
        $attrs = ['start_date' => '2026-06-01', 'end_date' => '2027-05-31', 'notice_date' => '2026-10-01', 'notice_given_by' => 'tenant', 'move_out_date' => '2026-11-30'];
        $tenantSide = $this->tenancyOf($attrs);
        $this->assertSame('notice_given', $tenantSide['state']);
        $this->assertSame('You have given notice', $tenantSide['headline']);
        $this->assertSame('Notice given 1 Oct 2026 · moving out 30 Nov 2026', $tenantSide['detail']);
        $this->assertSame('The tenant has given notice', $this->tenancyOf($attrs, 'landlord')['headline']);

        // Notice by the landlord, the other way round.
        $attrs['notice_given_by'] = 'landlord';
        $this->assertSame('The landlord has given notice', $this->tenancyOf($attrs)['headline']);
        $this->assertSame('Notice has been given by you', $this->tenancyOf($attrs, 'landlord')['headline']);
    }

    public function test_the_notice_period_and_renewal_window_are_the_agencys_own_settings(): void
    {
        LeaseSetting::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'tenant_notice_period_days' => 60, 'expiry_notice_window_days' => 100]);

        $t = $this->tenancyOf(['start_date' => '2026-06-01', 'end_date' => '2027-01-10']); // 94 days left
        $this->assertSame(60, $t['notice_period_days']);
        $this->assertSame('renewal_window', $t['state'], 'inside this agency\'s 100-day window');
    }

    public function test_a_draft_a_cancelled_or_an_archived_lease_is_not_a_home(): void
    {
        $property = $this->property('Draft House', $this->propAgent);
        $tenant = $this->contact('Draft');
        $this->lease($property, [$tenant], ['status' => 'draft', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'signing_status' => 'out_for_signing']);
        $this->lease($property, [$tenant], ['status' => 'cancelled', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $archived = $this->lease($property, [$tenant], ['status' => 'expired', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31']);
        $archived->delete();

        $this->assertSame([], $this->tenantHome($tenant)['homes']);
    }

    // ── 2. inspection dates ──────────────────────────────────────────────────────────────────

    public function test_upcoming_dates_and_past_sent_inspections_are_listed_and_nothing_unsent(): void
    {
        $up1 = $this->inspection($this->l1, 'draft', RentalInspection::TYPE_INTERIM, '2026-10-20', '09:30:00');
        $up2 = $this->inspection($this->l1, 'in_progress', RentalInspection::TYPE_AD_HOC, '2026-10-08'); // today
        $done = $this->inspection($this->l1, 'completed', RentalInspection::TYPE_IN);
        $sent = $this->inspection($this->l1, 'awaiting_signature', RentalInspection::TYPE_OUT, '2026-09-01', null, true);

        $hiddenPastSlot = $this->inspection($this->l1, 'draft', RentalInspection::TYPE_AD_HOC, '2026-10-01');
        $hiddenSigning = $this->inspection($this->l1, 'awaiting_signature', RentalInspection::TYPE_INTERIM, '2026-10-05');
        $hiddenCancelled = $this->inspection($this->l1, 'cancelled', RentalInspection::TYPE_INTERIM, '2026-10-25');
        $hiddenNoDate = $this->inspection($this->l1, 'draft', RentalInspection::TYPE_AD_HOC);
        $hiddenSigningFuture = $this->inspection($this->l1, 'awaiting_signature', RentalInspection::TYPE_INTERIM, '2026-10-30');

        $i = $this->tenantHome($this->tenant)['homes'][0]['inspections'];

        $this->assertSame([$up2->id, $up1->id], array_column($i['upcoming'], 'id'), 'booked dates, soonest first');
        $this->assertSame('Interim inspection', $i['upcoming'][1]['type_label']);
        $this->assertSame('2026-10-20', $i['upcoming'][1]['date']);
        $this->assertSame('09:30', $i['upcoming'][1]['time']);
        $this->assertSame('Scheduled', $i['upcoming'][1]['status_label']);

        $this->assertEqualsCanonicalizing([$done->id, $sent->id], array_column($i['past'], 'id'));
        $byId = collect($i['past'])->keyBy('id');
        $this->assertSame('Completed', $byId[$done->id]['status_label']);
        $this->assertSame('Report sent', $byId[$sent->id]['status_label'], 'a copy was emailed before the inspection was marked complete');
        $this->assertSame(2, $i['past_total']);

        $shown = array_merge(array_column($i['upcoming'], 'id'), array_column($i['past'], 'id'));
        foreach ([$hiddenPastSlot, $hiddenSigning, $hiddenCancelled, $hiddenNoDate, $hiddenSigningFuture] as $h) {
            $this->assertNotContains($h->id, $shown);
        }

        $flat = json_encode($i);
        $this->assertStringNotContainsString('url', $flat, 'no report link on the home — the report is opened from Documents');
        $this->assertStringNotContainsString('draft', $flat);
        $this->assertStringNotContainsString('in_progress', $flat);
    }

    public function test_only_the_newest_past_inspections_are_listed_and_the_rest_counted(): void
    {
        for ($n = 1; $n <= 12; $n++) {
            $i = $this->inspection($this->l1, 'completed', RentalInspection::TYPE_INTERIM);
            DB::table('rental_inspections')->where('id', $i->id)->update(['completed_at' => now()->subDays(20 + $n)]);
        }
        $i = $this->tenantHome($this->tenant)['homes'][0]['inspections'];

        $this->assertCount(10, $i['past']);
        $this->assertSame(12, $i['past_total']);
        $dates = array_column($i['past'], 'date');
        $sorted = $dates;
        rsort($sorted);
        $this->assertSame($sorted, $dates, 'newest first');
    }

    public function test_the_inspections_list_endpoint_returns_only_sent_inspections_and_booked_dates(): void
    {
        $done = $this->inspection($this->l1, 'completed', RentalInspection::TYPE_IN);
        $sent = $this->inspection($this->l1, 'awaiting_signature', RentalInspection::TYPE_OUT, null, null, true);
        $booked = $this->inspection($this->l1, 'draft', RentalInspection::TYPE_INTERIM, '2026-11-02', '14:00:00');
        $draft = $this->inspection($this->l1, 'draft', RentalInspection::TYPE_AD_HOC);
        $signing = $this->inspection($this->l1, 'awaiting_signature', RentalInspection::TYPE_INTERIM);
        $progress = $this->inspection($this->l1, 'in_progress', RentalInspection::TYPE_INTERIM);
        $cancelled = $this->inspection($this->l1, 'cancelled', RentalInspection::TYPE_INTERIM, '2026-11-05');

        $expected = collect([$done, $sent, $booked])->pluck('id')->sort()->values()->all();

        $this->asPortal($this->tenant);
        $json = $this->portalGet('/api/v1/client/rentals/inspections')->assertOk()->json('inspections');
        $this->assertSame($expected, collect($json)->pluck('id')->sort()->values()->all(), 'tenant list');
        $row = collect($json)->firstWhere('id', $booked->id);
        $this->assertSame('scheduled', $row['status'], 'a booked date never exposes its draft state');
        $this->assertSame('2026-11-02', $row['date']);
        $this->assertSame('14:00', $row['time']);
        foreach (['id', 'type', 'status', 'completed_at'] as $key) {
            $this->assertArrayHasKey($key, $row, 'the keys the mobile app already reads');
        }
        $this->assertNotContains($draft->id, collect($json)->pluck('id')->all());
        $this->assertNotContains($signing->id, collect($json)->pluck('id')->all());
        $this->assertNotContains($progress->id, collect($json)->pluck('id')->all());
        $this->assertNotContains($cancelled->id, collect($json)->pluck('id')->all());

        $this->asPortal($this->owner1);
        $owner = $this->portalGet('/api/v1/client/rentals/landlord/inspections')->assertOk()->json('inspections');
        $this->assertSame($expected, collect($owner)->pluck('id')->sort()->values()->all(), 'owner list — same rule');
    }

    // ── landlord home ────────────────────────────────────────────────────────────────────────

    public function test_the_owner_home_has_one_block_per_property_with_agent_lease_and_inspections(): void
    {
        $booked = $this->inspection($this->l1, 'draft', RentalInspection::TYPE_INTERIM, '2026-10-22');
        $this->asPortal($this->owner1);
        $json = $this->portalGet('/api/v1/client/rentals/landlord/overview')->assertOk()->json();

        $this->assertSame('landlord', $json['role']);
        $this->assertSame(0, $json['decisions_waiting']);
        $homes = collect($json['homes'])->keyBy('property.id');
        $this->assertSame([$this->p1->id, $this->p3->id], $homes->keys()->sort()->values()->all());

        $h1 = $homes[$this->p1->id];
        // leases.md §17 — the OWNER's portal shows the owner's agent (the property's agent by default); the tenant's
        // portal shows the tenant's agent (Anele, who captured the lease). See LeaseAgentsTest for every combination.
        $this->assertSame('Pieter Prop', $h1['contact']['agent']['name']);
        $this->assertSame('running', $h1['tenancy']['state']);
        $this->assertSame('Ayanda Test', $h1['tenant_names']);
        $this->assertSame([$booked->id], array_column($h1['inspections']['upcoming'], 'id'));

        $h3 = $homes[$this->p3->id];
        $this->assertNull($h3['tenancy'], 'a property nobody is renting');
        $this->assertNull($h3['lease_id']);
        $this->assertSame('Pieter Prop', $h3['contact']['agent']['name'], 'no lease → the property\'s agent');
        $this->assertSame([], $h3['inspections']['upcoming']);
    }

    // ── nobody sees anybody else's ───────────────────────────────────────────────────────────

    public function test_nobody_sees_another_partys_home_or_inspection_dates(): void
    {
        $mine = $this->inspection($this->l1, 'draft', RentalInspection::TYPE_INTERIM, '2026-10-22');
        $theirs = $this->inspection($this->l2, 'draft', RentalInspection::TYPE_INTERIM, '2026-10-23');
        $theirsDone = $this->inspection($this->l2, 'completed', RentalInspection::TYPE_IN);

        // tenant ↔ tenant
        $t2 = $this->tenantHome($this->tenant2);
        $this->assertSame([$this->p2->id], array_column(array_column($t2['homes'], 'property'), 'id'));
        $this->assertSame([$theirs->id], array_column($t2['homes'][0]['inspections']['upcoming'], 'id'));
        $this->assertNotContains($mine->id, array_column($t2['homes'][0]['inspections']['upcoming'], 'id'));
        $listed = $this->portalGet('/api/v1/client/rentals/inspections')->json('inspections');
        $this->assertNotContains($mine->id, array_column($listed, 'id'));

        // owner ↔ owner
        $this->asPortal($this->owner2);
        $o2 = $this->portalGet('/api/v1/client/rentals/landlord/overview')->assertOk()->json();
        $this->assertSame([$this->p2->id], array_column(array_column($o2['homes'], 'property'), 'id'));
        $this->assertStringNotContainsString('Ayanda', json_encode($o2));
        $ownerList = array_column($this->portalGet('/api/v1/client/rentals/landlord/inspections')->json('inspections'), 'id');
        $this->assertNotContains($mine->id, $ownerList);
        $this->assertEqualsCanonicalizing([$theirs->id, $theirsDone->id], $ownerList, 'owner 2 sees exactly their own property\'s dates and sent inspections');

        // tenant ↔ owner: a tenant who owns nothing gets an empty owner home, an owner who rents nothing an empty tenant home
        $this->asPortal($this->tenant);
        $this->assertSame([], $this->portalGet('/api/v1/client/rentals/landlord/overview')->assertOk()->json('homes'));
        $this->asPortal($this->owner1);
        $this->assertSame([], $this->portalGet('/api/v1/client/rentals/overview')->assertOk()->json('homes'));
    }

    public function test_another_agencys_records_never_reach_the_home(): void
    {
        $branchB = Branch::create(['agency_id' => $this->other->id, 'name' => 'Other Branch', 'code' => 'OB-' . $this->other->id, 'is_active' => true]);
        $agentB = User::factory()->create(['agency_id' => $this->other->id, 'branch_id' => $branchB->id, 'role' => 'agent', 'name' => 'Bella Agent', 'is_active' => true]);
        $propB = $this->property('99 Foreign Street', $agentB, $this->other, $branchB->id);
        $tenantB = $this->contact('Bella', $this->other);
        $leaseB = Lease::create(['agency_id' => $this->other->id, 'branch_id' => $branchB->id, 'property_id' => $propB->id, 'status' => 'active', 'rental_amount' => 5000, 'source' => 'manual', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'signing_status' => 'signed_on_paper']);
        LeaseTenant::create(['lease_id' => $leaseB->id, 'contact_id' => $tenantB->id, 'is_primary' => true]);
        $this->inspection($leaseB, 'draft', RentalInspection::TYPE_INTERIM, '2026-10-30');

        // Bella sees her own agency's office and nothing of Cape Rentals.
        $home = $this->tenantHome($tenantB)['homes'][0];
        $this->assertSame('99 Foreign Street', explode(',', $home['property']['address'])[0]);
        $this->assertSame('Other Agency', $home['contact']['office']['agency']);
        $this->assertSame('Bella Agent', $home['contact']['agent']['name']);
        $this->assertStringNotContainsString('Cape', json_encode($home));
        $this->assertStringNotContainsString('Anele', json_encode($home));

        // And Ayanda sees nothing of Bella's.
        $this->assertStringNotContainsString('Foreign', json_encode($this->tenantHome($this->tenant)));
    }

    public function test_no_session_and_a_staff_session_alone_are_refused(): void
    {
        foreach (['/api/v1/client/rentals/overview', '/api/v1/client/rentals/landlord/overview'] as $url) {
            $this->withHeaders(self::BROWSER)->getJson($url)->assertStatus(401);
        }

        $this->withSession([Auth::guard('web')->getName() => $this->agent->id]);
        Auth::forgetGuards();
        $this->withHeaders(self::BROWSER)->getJson('/api/v1/client/rentals/overview')->assertStatus(401);
    }

    public function test_the_portal_switch_closes_the_home_for_that_audience(): void
    {
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['tenant_portal_enabled' => false]);
        $this->asPortal($this->tenant);
        $this->portalGet('/api/v1/client/rentals/overview')->assertStatus(403);

        $this->asPortal($this->owner1);
        $this->portalGet('/api/v1/client/rentals/landlord/overview')->assertOk();

        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['landlord_portal_enabled' => false]);
        $this->portalGet('/api/v1/client/rentals/landlord/overview')->assertStatus(403);
    }

    public function test_the_portal_page_opens_on_a_home_tab_for_both_audiences(): void
    {
        $html = $this->portalGet('/portal')->assertOk()->getContent();

        $this->assertGreaterThanOrEqual(2, substr_count($html, 'data-portal-home>'), 'the same Home panel for the tenant and the owner');
        $this->assertStringContainsString("tenantTab: 'home'", $html);
        $this->assertStringContainsString("landlordTab: 'home'", $html);
        $this->assertStringContainsString('/overview', $html);
        $this->assertStringContainsString('data-portal-home-contact', $html);
        $this->assertStringContainsString('data-portal-home-lease', $html);
        $this->assertStringContainsString('data-portal-home-inspections', $html);
    }
}
