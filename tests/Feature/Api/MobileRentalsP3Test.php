<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Models\Document;
use App\Models\Lease;
use App\Models\LeaseNotice;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\BuildsMobileRentalsWorld;
use Tests\TestCase;

/**
 * AT-508 P3 - the staff lease list, the lease file and the notices waiting for review under /api/v1/mobile. Every endpoint proves: success,
 * permission denied, another agency's record is a 404, a same-agency record outside the user's own/branch ceiling is a 403 (and absent from lists),
 * validation 422, and for the lists search, sort, filter, pagination and the plain empty-state sentence. .ai/specs/at-508-mobile-rentals.md sections 4, 6, 7.
 */
final class MobileRentalsP3Test extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileRentalsWorld;

    private const M = '/api/v1/mobile';

    private const KEYS = ['leases.view', 'leases.renew', 'rental_notices.review', 'properties.view', 'access_properties'];

    private User $junior;    // same agency, OWN scope
    private User $viewer;    // same agency, no lease / notice permission
    private User $outsider;  // another agency, full power there

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();

        $this->grantScope($this->agency, 'agent', 'all', self::KEYS);
        $this->grantScope($this->agency, 'junior', 'own', self::KEYS);
        $this->grantScope($this->agency, 'viewer', 'all', ['properties.view', 'access_properties', 'rental_fault_reports.view']);
        $this->junior = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'junior', 'is_active' => true]);
        $this->viewer = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'viewer', 'is_active' => true]);

        $otherBranch = Branch::create(['agency_id' => $this->other->id, 'name' => 'Other main', 'code' => 'O-' . $this->other->id, 'is_active' => true]);
        $this->grantScope($this->other, 'agent', 'all', self::KEYS);
        $this->outsider = User::factory()->create(['agency_id' => $this->other->id, 'branch_id' => $otherBranch->id, 'role' => 'agent', 'is_active' => true]);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** a rental property with its own lease, tenant and (optionally) landlord */
    private function tenancy(string $address, array $leaseOver = [], string $tenantFirst = 'Thandi', ?string $landlordFirst = null): Lease
    {
        $property = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => $address, 'address' => $address, 'status' => 'active', 'listing_type' => 'rental']);
        $lease = Lease::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id, 'status' => 'active', 'rental_amount' => 7000, 'deposit_amount' => 7000,
            'start_date' => now()->subMonths(6)->toDateString(), 'end_date' => now()->addMonths(6)->toDateString(), 'lease_type' => 'residential', 'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ], $leaseOver));
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $this->contact($tenantFirst)->id, 'is_primary' => true]);
        if ($landlordFirst) {
            $property->contacts()->attach($this->contact($landlordFirst)->id, ['role' => 'landlord']);
        }

        return $lease;
    }

    private function notice(Lease $lease, array $over = []): LeaseNotice
    {
        return LeaseNotice::create(array_merge([
            'agency_id' => $this->agency->id, 'lease_id' => $lease->id, 'given_by' => 'tenant', 'via' => 'portal', 'given_by_contact_id' => $lease->tenants()->first()->contact_id,
            'notice_date' => now()->subDays(3)->toDateString(), 'intended_end_date' => now()->addDays(40)->toDateString(), 'reason' => 'Moving to Durban', 'status' => LeaseNotice::STATUS_RECEIVED,
        ], $over));
    }

    private function ids($res): array
    {
        return array_column($res->json('data'), 'id');
    }

    // ── leases: list ─────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_lease_list_searches_sorts_filters_and_pages(): void
    {
        $soon = $this->tenancy('10 Beach Rd', ['end_date' => now()->addDays(20)->toDateString(), 'start_date' => now()->subMonths(11)->toDateString()], 'Zola', 'Greta');
        $later = $this->tenancy('20 Hill St', ['end_date' => now()->addMonths(3)->toDateString(), 'start_date' => now()->subMonths(1)->toDateString()], 'Yusuf', 'Hendrik');
        $ended = $this->tenancy('30 Dune Ave', ['status' => 'expired', 'end_date' => now()->subMonths(2)->toDateString(), 'start_date' => now()->subMonths(14)->toDateString()], 'Xavier');
        $base = $this->lease;   // 12 Sea Road, ends in ~10 months, tenant Tina, landlord Lenny

        $list = fn (string $qs = '') => $this->asStaff($this->agent)->getJson(self::M . '/leases' . $qs)->assertOk();

        // default: end date, soonest first
        $this->assertSame([$ended->id, $soon->id, $later->id, $base->id], $this->ids($list()));
        $this->assertSame(array_reverse([$ended->id, $soon->id, $later->id, $base->id]), $this->ids($list('?direction=desc')));
        $this->assertSame([$ended->id, $soon->id, $base->id, $later->id], $this->ids($list('?sort=start_date')), 'oldest start first');
        $this->assertSame([$soon->id, $base->id, $later->id, $ended->id], $this->ids($list('?sort=property')), 'by property title');
        $this->assertSame([$soon->id, $later->id, $base->id, $ended->id], $this->ids($list('?sort=status')), 'active before expired, then by end date');

        // search: address, tenant, owner, reference
        $this->assertSame([$soon->id], $this->ids($list('?q=Beach')));
        $this->assertSame([$later->id], $this->ids($list('?q=Yusuf')));
        $this->assertSame([$soon->id], $this->ids($list('?q=Greta')), 'owner');
        $this->assertSame([$ended->id], $this->ids($list('?q=L-' . $ended->id)), 'reference');
        $this->assertSame([$ended->id], $this->ids($list('?q=' . $ended->id)));

        // filters
        $this->assertSame([$ended->id], $this->ids($list('?status=expired')));
        $this->assertSame([$soon->id], $this->ids($list('?expiring_within_days=30')), 'only an ACTIVE lease ending inside the window');
        $this->assertSame([$soon->id, $later->id], $this->ids($list('?expiring_within_days=120')));
        $this->assertSame([$soon->id, $later->id], $this->ids($list('?date_from=' . now()->addDays(10)->toDateString() . '&date_to=' . now()->addMonths(4)->toDateString())));

        $row = collect($list()->json('data'))->firstWhere('id', $soon->id);
        $this->assertSame(['id', 'reference', 'status', 'status_label', 'agreement_label', 'property', 'start_date', 'end_date', 'is_month_to_month', 'days_left', 'tenants', 'owners', 'has_notice', 'move_out_date'], array_keys($row));
        $this->assertSame('Zola Test', $row['tenants'][0]['name']);
        $this->assertSame('Greta Test', $row['owners'][0]['name']);
        $this->assertEqualsWithDelta(20, $row['days_left'], 1);
        $this->assertArrayNotHasKey('rental_amount', $row, 'no rent or deposit in the app');
    }

    public function test_the_lease_list_validates_pages_and_says_why_it_is_empty(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $this->tenancy("Unit {$i} Test Court", ['end_date' => now()->addDays(100 + $i)->toDateString()]);
        }
        $p1 = $this->asStaff($this->agent)->getJson(self::M . '/leases')->assertOk();
        $this->assertCount(25, $p1->json('data'));
        $this->assertSame(['page' => 1, 'per_page' => 25, 'total' => 27, 'last_page' => 2, 'empty_reason' => null], $p1->json('meta'));
        $this->assertCount(2, $this->asStaff($this->agent)->getJson(self::M . '/leases?page=2')->json('data'));

        $none = $this->asStaff($this->agent)->getJson(self::M . '/leases?q=zzzz-nothing')->assertOk();
        $this->assertSame([], $none->json('data'));
        $this->assertNotEmpty($none->json('meta.empty_reason'));

        $this->asStaff($this->agent)->getJson(self::M . '/leases?sort=sideways')->assertStatus(422);
        $this->asStaff($this->agent)->getJson(self::M . '/leases?status=nonsense')->assertStatus(422);
        $this->asStaff($this->agent)->getJson(self::M . '/leases?expiring_within_days=0')->assertStatus(422);
        $this->asStaff($this->agent)->getJson(self::M . '/leases?date_from=not-a-date')->assertStatus(422);
    }

    public function test_the_lease_list_is_guarded_and_scoped_at_the_query(): void
    {
        $theirs = $this->tenancy('77 Other St');                                        // created by the agent
        $mine = $this->tenancy('88 Junior St', ['created_by_user_id' => $this->junior->id]);

        $this->asStaff($this->viewer)->getJson(self::M . '/leases')->assertForbidden();
        $this->assertSame([$mine->id], $this->ids($this->asStaff($this->junior)->getJson(self::M . '/leases')->assertOk()), 'own scope: only what the junior is on');
        $this->assertSame([], $this->ids($this->asStaff($this->outsider)->getJson(self::M . '/leases')->assertOk()), 'another agency sees none of it');
        $this->assertContains($theirs->id, $this->ids($this->asStaff($this->agent)->getJson(self::M . '/leases')));
    }

    // ── leases: the file ─────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_lease_file_carries_dates_parties_signing_documents_and_notice_state(): void
    {
        $this->lease->forceFill([
            'signing_status' => Lease::SIGNING_SIGNED_ON_PAPER, 'owner_agent_user_id' => $this->agent->id, 'notice_date' => now()->subDays(2)->toDateString(),
            'notice_given_by' => 'tenant', 'move_out_date' => now()->addDays(30)->toDateString(), 'notice_outcome' => 'readvertise',
        ])->save();
        $this->notice($this->lease);
        Document::create(['agency_id' => $this->agency->id, 'original_name' => 'signed-lease.pdf', 'storage_path' => 'x/signed-lease.pdf', 'disk' => 'local', 'mime_type' => 'application/pdf', 'size' => 1234, 'source_type' => 'lease', 'source_id' => $this->lease->id]);

        $res = $this->asStaff($this->agent)->getJson(self::M . "/leases/{$this->lease->id}")->assertOk();

        $res->assertJsonPath('id', $this->lease->id)->assertJsonPath('reference', 'L-' . $this->lease->id)->assertJsonPath('status', 'active')
            ->assertJsonPath('property.address', '12 Sea Road')->assertJsonPath('tenants.0.name', 'Tina Test')->assertJsonPath('owners.0.name', 'Lenny Test')
            ->assertJsonPath('owner_agent.id', $this->agent->id)
            ->assertJsonPath('signing.status', 'signed_on_paper')->assertJsonPath('signing.label', 'Signed on paper')
            ->assertJsonPath('notice.active', true)->assertJsonPath('notice.given_by', 'tenant')->assertJsonPath('notice.outcome', 'readvertise')
            ->assertJsonPath('notice.notices_to_review', 1)
            ->assertJsonPath('documents.0.name', 'signed-lease.pdf')->assertJsonPath('documents.0.kind', 'signed_agreement')
            ->assertJsonPath('can.renew', true)->assertJsonPath('can.review_notices', true);
        $this->assertSame(['application', 'approved', 'lease_signed', 'in_inspection', 'tenancy', 'renewal_notice', 'out_inspection'], array_column($res->json('lifecycle'), 'key'));
        $this->assertSame(['faults', 'work_orders', 'inspections'], array_keys($res->json('open_items')));
        $this->assertArrayHasKey('next_step', $res->json());
        $this->assertArrayHasKey('renewal', $res->json());
        foreach (['rental_amount', 'deposit_amount'] as $money) {
            $this->assertArrayNotHasKey($money, $res->json(), 'no rent or deposit in the app');
        }
    }

    public function test_the_lease_file_is_guarded(): void
    {
        $id = $this->lease->id;
        $this->asStaff($this->viewer)->getJson(self::M . "/leases/{$id}")->assertForbidden();                    // permission
        $this->asStaff($this->outsider)->getJson(self::M . "/leases/{$id}")->assertNotFound();                   // another agency
        $this->asStaff($this->junior)->getJson(self::M . "/leases/{$id}")->assertForbidden();                    // same agency, not their lease
        $this->asStaff($this->agent)->getJson(self::M . '/leases/999999')->assertNotFound();

        // the lease's own agents are its people: an own-scope agent set as the tenant's agent can open it
        $this->lease->forceFill(['tenant_agent_user_id' => $this->junior->id])->save();
        $this->asStaff($this->junior)->getJson(self::M . "/leases/{$id}")->assertOk();

        // an archived lease is not in the app
        $this->lease->delete();
        $this->asStaff($this->agent)->getJson(self::M . "/leases/{$id}")->assertNotFound();
    }

    // ── notices to review ────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_notices_list_defaults_to_what_waits_for_review_oldest_first(): void
    {
        $a = $this->tenancy('1 Alpha Rd', [], 'Alma');
        $b = $this->tenancy('2 Bravo Rd', [], 'Bruno');
        $c = $this->tenancy('3 Charlie Rd', [], 'Carla');
        $d = $this->tenancy('4 Delta Rd', [], 'Dumi');
        $n1 = $this->notice($a, ['notice_date' => now()->subDays(10)->toDateString(), 'reason' => 'New job in Cape Town']);
        $n2 = $this->notice($b, ['notice_date' => now()->subDays(2)->toDateString(), 'given_by' => 'landlord', 'reason' => 'Selling the house']);
        $n3 = $this->notice($c, ['notice_date' => now()->subDays(6)->toDateString()]);
        $done = $this->notice($d, ['status' => LeaseNotice::STATUS_ACCEPTED, 'accepted_end_date' => now()->addDays(30)->toDateString(), 'notice_date' => now()->subDays(20)->toDateString()]);
        $gone = $this->notice($a, ['notice_date' => now()->subDays(30)->toDateString()]);
        $gone->delete();

        $list = fn (string $qs = '') => $this->asStaff($this->agent)->getJson(self::M . '/rental-notices' . $qs)->assertOk();

        $this->assertSame([$n1->id, $n3->id, $n2->id], $this->ids($list()), 'to review, oldest first; archived and decided notices are not in it');
        $this->assertSame([$n2->id, $n3->id, $n1->id], $this->ids($list('?direction=desc')));
        $this->assertSame([$done->id], $this->ids($list('?status=accepted')));
        $this->assertSame([$done->id, $n1->id, $n3->id, $n2->id], $this->ids($list('?status=all')));
        $this->assertSame([$n1->id, $n3->id, $n2->id, $done->id], $this->ids($list('?status=all&sort=status&direction=desc')), 'status sort');

        // search: property, tenant, who gave it, the reason
        $this->assertSame([$n1->id], $this->ids($list('?q=Alpha')));
        $this->assertSame([$n3->id], $this->ids($list('?q=Carla')));
        $this->assertSame([$n1->id], $this->ids($list('?q=Cape+Town')));
        $this->assertSame([$n2->id], $this->ids($list('?q=Selling')));
        // date range (the notice date)
        $this->assertSame([$n3->id, $n2->id], $this->ids($list('?date_from=' . now()->subDays(7)->toDateString())));
        $this->assertSame([$n1->id], $this->ids($list('?date_to=' . now()->subDays(8)->toDateString())));

        $row = collect($list()->json('data'))->firstWhere('id', $n2->id);
        $this->assertSame(['id', 'status', 'status_label', 'given_by', 'given_by_label', 'given_by_name', 'via', 'notice_date', 'intended_end_date', 'accepted_end_date', 'reason', 'has_attachment', 'decided_at', 'age_days', 'lease_id', 'property', 'tenant_names'], array_keys($row));
        $this->assertSame('landlord', $row['given_by']);
        $this->assertSame('Needs review', $row['status_label']);
        $this->assertSame($b->id, $row['lease_id']);
        $this->assertSame(2, $row['age_days']);
    }

    public function test_the_notices_list_validates_pages_and_says_why_it_is_empty(): void
    {
        $this->asStaff($this->agent)->getJson(self::M . '/rental-notices?status=nonsense')->assertStatus(422);
        $this->asStaff($this->agent)->getJson(self::M . '/rental-notices?sort=sideways')->assertStatus(422);

        $empty = $this->asStaff($this->agent)->getJson(self::M . '/rental-notices')->assertOk();
        $this->assertSame([], $empty->json('data'));
        $this->assertSame('No notices are waiting for your review.', $empty->json('meta.empty_reason'));
        $filtered = $this->asStaff($this->agent)->getJson(self::M . '/rental-notices?q=zzz')->assertOk();
        $this->assertSame('No notices match. Clear the search or filters to see more.', $filtered->json('meta.empty_reason'));

        for ($i = 0; $i < 26; $i++) {
            $this->notice($this->lease, ['notice_date' => now()->subDays(100 - $i)->toDateString()]);
        }
        $p1 = $this->asStaff($this->agent)->getJson(self::M . '/rental-notices')->assertOk();
        $this->assertCount(25, $p1->json('data'));
        $this->assertSame(['page' => 1, 'per_page' => 25, 'total' => 26, 'last_page' => 2, 'empty_reason' => null], $p1->json('meta'));
        $this->assertCount(1, $this->asStaff($this->agent)->getJson(self::M . '/rental-notices?page=2')->json('data'));
    }

    public function test_notices_are_exactly_as_visible_as_their_lease(): void
    {
        $others = $this->tenancy('55 Elsewhere Rd');
        $mine = $this->tenancy('66 Junior Rd', ['created_by_user_id' => $this->junior->id]);
        $nOthers = $this->notice($others);
        $nMine = $this->notice($mine);

        $this->asStaff($this->viewer)->getJson(self::M . '/rental-notices')->assertForbidden();                   // no rental_notices.review
        $this->assertSame([$nMine->id], $this->ids($this->asStaff($this->junior)->getJson(self::M . '/rental-notices')->assertOk()));
        $this->assertSame([], $this->ids($this->asStaff($this->outsider)->getJson(self::M . '/rental-notices')->assertOk()), 'another agency sees none of it');
        $this->assertEqualsCanonicalizing([$nOthers->id, $nMine->id], $this->ids($this->asStaff($this->agent)->getJson(self::M . '/rental-notices')));
    }

    public function test_every_p3_route_is_named_and_listed(): void
    {
        $names = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->map->getName()->filter()->all();
        foreach (['v1.mobile.leases.index', 'v1.mobile.leases.show', 'v1.mobile.rental-notices.index'] as $name) {
            $this->assertContains($name, $names);
        }
    }
}
