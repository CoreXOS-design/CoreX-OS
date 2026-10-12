<?php

declare(strict_types=1);

namespace Tests\Feature\QuickLinks;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Quick Links Wave 2, first slice (.ai/specs/quick-links.md §5.1 / §5.2): buyer chips + Viewing Pack on the contact,
 * mandate chip + Book viewing on the property, pre-approval chip on the contacts list. Real pages over HTTP, seeded
 * with real grants (else PermissionService falls back to allow-all and the gating assertions mean nothing).
 */
final class BuyerAndPropertyQuickLinksTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();
        $roles = [
            ['name' => 'super_admin', 'label' => 'System Owner',  'is_owner' => 1, 'can_be_deleted' => 0, 'sort_order' => 1],
            ['name' => 'admin',       'label' => 'Administrator', 'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 2],
            ['name' => 'agent',       'label' => 'Agent',         'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 4],
        ];
        foreach ($roles as &$r) {
            $r += ['agency_id' => null, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('roles')->insert($roles);
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        Role::clearCache();
        PermissionService::clearCache();

        $this->agency = Agency::create(['name' => 'QL2 ' . uniqid(), 'slug' => 'ql2-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $mk = fn (string $role) => User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role, 'is_active' => true,
        ]);
        $this->admin = $mk('admin');
        $this->agent = $mk('agent');
    }

    protected function tearDown(): void
    {
        Role::clearCache();
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function contact(array $extra = []): Contact
    {
        return Contact::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'created_by_user_id' => $this->agent->id, 'first_name' => 'Buyer', 'last_name' => 'B' . uniqid(),
            'phone' => '082' . random_int(1000000, 9999999), 'is_buyer' => true,
        ], $extra));
    }

    private function page(Contact $c, ?User $as = null): string
    {
        return $this->actingAs($as ?? $this->agent)->get(route('corex.contacts.show', $c))->assertOk()->getContent();
    }

    public function test_a_buyer_with_no_pre_approval_and_no_wishlist_shows_both_gaps(): void
    {
        $html = $this->page($this->contact());

        $this->assertStringContainsString('data-preapproval-chip="none"', $html);
        $this->assertStringContainsString('No pre-approval', $html);
        $this->assertStringContainsString('data-wishlist-chip="none"', $html);
        $this->assertStringContainsString('No wishlist', $html);
        $this->assertStringContainsString('#preapproval', $html, 'the chip leads to the Financial Position panel');
        $this->assertStringContainsString('id="preapproval"', $html);
    }

    public function test_a_valid_pre_approval_shows_green_with_the_amount(): void
    {
        $c = $this->contact(['preapproval_amount' => 1_200_000, 'preapproval_expires_at' => today()->addDays(30)]);
        $html = $this->page($c);

        $this->assertStringContainsString('data-preapproval-chip="valid"', $html);
        $this->assertStringContainsString('Pre-approved R 1.2m', $html);
    }

    public function test_an_expired_pre_approval_shows_amber(): void
    {
        $c = $this->contact(['preapproval_amount' => 900_000, 'preapproval_expires_at' => today()->subDays(3)]);
        $this->assertStringContainsString('data-preapproval-chip="expired"', $this->page($c));
    }

    public function test_a_contact_who_is_not_a_buyer_gets_neither_chip_nor_the_viewing_pack_button(): void
    {
        $html = $this->page($this->contact(['is_buyer' => false]));

        $this->assertStringNotContainsString('data-preapproval-chip', $html);
        $this->assertStringNotContainsString('data-wishlist-chip', $html);
        $this->assertStringNotContainsString('data-viewing-pack-quick', $html);
    }

    public function test_a_buyer_gets_a_one_click_viewing_pack_that_posts_the_existing_store_route(): void
    {
        $c = $this->contact();
        $html = $this->page($c);

        $this->assertStringContainsString('data-viewing-pack-quick', $html);
        $this->assertStringContainsString('action="' . route('corex.viewing-packs.store') . '"', $html);
        $this->assertTrue($this->agent->hasPermission('viewing_packs.create'), 'precondition: default agent may create packs');

        $this->actingAs($this->agent)->post(route('corex.viewing-packs.store'), ['contact_id' => $c->id])->assertRedirect();
        $this->assertSame(1, DB::table('viewing_packs')->where('contact_id', $c->id)->count());
    }

    public function test_the_viewing_pack_button_is_hidden_from_someone_without_the_permission(): void
    {
        $c = $this->contact();
        DB::table('role_permissions')->where('agency_id', $this->agency->id)->where('role', 'agent')
            ->where('permission_key', 'viewing_packs.create')->delete();
        PermissionService::clearCache();

        $this->assertStringNotContainsString('data-viewing-pack-quick', $this->page($c));
    }

    public function test_the_contacts_list_shows_the_pre_approval_chip_for_buyers_only(): void
    {
        $this->contact(['preapproval_amount' => 750_000, 'preapproval_expires_at' => today()->addDays(10)]);
        $this->contact(['is_buyer' => false, 'first_name' => 'Seller']);

        $html = $this->actingAs($this->agent)->get(route('corex.contacts.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-preapproval-chip="valid"'));
        $this->assertStringContainsString('Pre-approved R 750k', $html);
        $this->assertSame(1, substr_count($html, 'data-preapproval-chip='), 'the non-buyer row has none');
    }

    private function property(array $extra = []): Property
    {
        return Property::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => '12 Test Road', 'status' => 'active', 'listing_type' => 'sale', 'property_type' => 'house',
            'suburb' => 'Uvongo', 'city' => 'Margate', 'province' => 'KwaZulu-Natal', 'price' => 1_950_000,
            'mandate_type' => 'Sole', 'expiry_date' => today()->addDays(40),
        ], $extra));
    }

    public function test_the_property_header_shows_the_mandate_chip_and_a_book_viewing_link(): void
    {
        $p = $this->property();
        $html = $this->actingAs($this->admin)->get(route('corex.properties.show', $p))->assertOk()->getContent();

        $this->assertStringContainsString('data-mandate-chip="ok"', $html);
        $this->assertStringContainsString('Sole · 40 days left', $html);
        $this->assertStringContainsString('data-book-viewing-quick', $html);
        $this->assertStringContainsString('prefill_class=viewing', $html);
        $this->assertStringContainsString('prefill_properties=', $html);
    }

    public function test_the_mandate_chip_turns_amber_inside_the_agencys_warning_window_and_red_when_expired(): void
    {
        $soon = $this->property(['expiry_date' => today()->addDays(3)]);
        $this->assertStringContainsString('data-mandate-chip="expiring"', $this->actingAs($this->admin)->get(route('corex.properties.show', $soon))->getContent());

        $gone = $this->property(['expiry_date' => today()->subDays(2), 'title' => '1 Old Road']);
        $this->assertStringContainsString('data-mandate-chip="expired"', $this->actingAs($this->admin)->get(route('corex.properties.show', $gone))->getContent());
    }

    public function test_a_property_with_no_mandate_shows_no_chip(): void
    {
        $p = $this->property(['mandate_type' => null, 'expiry_date' => null]);
        $this->assertStringNotContainsString('data-mandate-chip', $this->actingAs($this->admin)->get(route('corex.properties.show', $p))->getContent());
    }
}
