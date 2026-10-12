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

    public function test_every_contact_action_lives_in_one_quick_links_dropdown(): void
    {
        $buyer = $this->contact(['birthday' => '1985-04-02']);
        $html = $this->page($buyer, $this->admin);

        $this->assertSame(1, substr_count($html, 'data-contact-quick-links'), 'exactly one dropdown');
        $start  = strpos($html, 'data-contact-quick-links');
        $delete = strpos($html, 'Delete Contact', $start);
        $this->assertNotFalse($delete, 'Delete Contact is still on the page');

        // every action sits between the dropdown's start and the (separate) Delete button
        foreach (['Schedule Event', 'Send FICA form', 'Buyer Pipeline Profile', 'Viewing Pack', 'Create Listing', 'Remind me of birthday'] as $item) {
            $pos = strpos($html, $item, $start);
            $this->assertNotFalse($pos, "{$item} is in the dropdown");
            $this->assertLessThan($delete, $pos, "{$item} comes before Delete Contact, i.e. inside the menu");
        }
        $this->assertStringContainsString('Quick Links', $html);
        $this->assertStringNotContainsString('data-rental-application-quick', $html, 'the contact page no longer carries it');

        // status chips stay in the header
        $this->assertStringContainsString('data-preapproval-chip', $html);
    }

    public function test_the_fica_action_is_in_the_menu_and_the_header_pill_has_no_button_next_to_it(): void
    {
        $c = $this->contact();
        $html = $this->page($c);

        $menuStart = strpos($html, 'data-contact-quick-links');
        $sendAt    = strpos($html, 'data-fica-send', $menuStart);
        $this->assertNotFalse($sendAt, 'Send FICA form is in the Quick Links menu');
        // the header pill comes BEFORE the menu and nothing action-like sits between it and the menu start
        $pillAt = strpos($html, 'data-fica-quick-link="none"');
        $this->assertNotFalse($pillAt);
        $this->assertSame(false, strpos(substr($html, $pillAt, $menuStart - $pillAt), 'data-fica-send'), 'no Send button beside the header pill');
    }

    public function test_every_header_pill_is_the_same_size_font_and_colour(): void
    {
        $buyer = $this->contact(['preapproval_amount' => 900_000, 'preapproval_expires_at' => today()->addDays(9)]);
        $html = $this->page($buyer);

        preg_match_all('/<(?:a|span)\b[^>]*\bstyle="(display:inline-flex; align-items:center; gap:0\.375rem;[^"]*)"/', $html, $m);
        $this->assertGreaterThanOrEqual(4, count($m[1]), 'communication, FICA, pre-approval and wishlist pills');
        $this->assertCount(1, array_unique($m[1]), 'one single pill style for all of them: ' . implode(' | ', array_unique($m[1])));
        $this->assertStringContainsString('height:1.5rem', $m[1][0]);
        $this->assertStringContainsString('font-size:0.75rem', $m[1][0]);

        // the old mixed treatments are gone from the header: no solid coloured badge, no separate FICA/pre-approval badge class
        foreach (['data-fica-quick-link', 'data-preapproval-chip', 'data-wishlist-chip'] as $marker) {
            $pos = strpos($html, $marker);
            $tag = substr($html, strrpos(substr($html, 0, $pos), '<'), 400);
            $this->assertStringNotContainsString('ds-badge', strstr($tag, '>', true) ?: $tag, "{$marker} pill must not use a ds-badge colour class");
        }
    }

    public function test_a_buyer_on_the_pipeline_has_a_buyer_pipeline_profile_link_a_non_buyer_does_not(): void
    {
        $buyer = $this->contact(['buyer_state' => 'warm']);
        $html = $this->page($buyer);
        $this->assertStringContainsString('data-buyer-pipeline-profile', $html);
        $this->assertStringContainsString(route('command-center.buyers.show', $buyer), $html);
        $this->assertStringContainsString('Buyer Pipeline Profile', $html);
        $this->assertStringContainsString('Warm buyer', $html);

        $other = $this->contact(['is_buyer' => false]);
        $this->assertStringNotContainsString('data-buyer-pipeline-profile', $this->page($other));
    }

    private function logSends(Contact $c, int $n): void
    {
        $logger = app(\App\Services\Communications\OutboundProvisionalLogger::class);
        for ($i = 0; $i < $n; $i++) {
            $logger->log($c, \App\Models\Communications\Communication::CHANNEL_WHATSAPP, null, 'Hi ' . $i, $this->agent->id);
        }
    }

    public function test_recent_sends_is_a_closed_dropdown_that_scrolls_inside_and_offers_show_all(): void
    {
        $c = $this->contact();
        $this->logSends($c, 6);
        $html = $this->page($c);

        $this->assertStringContainsString('data-recent-sends', $html);
        $this->assertStringContainsString('Recent Sends', $html);
        $this->assertStringContainsString('(6)', $html, 'the count shows even while it is closed');
        // always closed on load
        $this->assertStringContainsString('open: false, all: false', $html);
        $this->assertStringContainsString('data-recent-sends-toggle', $html);
        // ~4 rows tall with its own scroll, and an option to show everything
        $this->assertStringContainsString("max-height:17.5rem;", $html);
        $this->assertStringContainsString('overflow-y-auto', $html);
        $this->assertStringContainsString('data-recent-sends-all', $html);
        $this->assertStringContainsString('Show all 6', $html);
    }

    public function test_recent_sends_with_four_or_fewer_has_no_show_all_and_with_none_is_absent(): void
    {
        $few = $this->contact();
        $this->logSends($few, 3);
        $html = $this->page($few);
        $this->assertStringContainsString('data-recent-sends-toggle', $html);
        $this->assertStringNotContainsString('data-recent-sends-all', $html);

        $this->assertStringNotContainsString('data-recent-sends', $this->page($this->contact()));
    }

    public function test_a_non_buyer_gets_a_shorter_dropdown_without_buyer_only_items(): void
    {
        $c = $this->contact(['is_buyer' => false]);
        $html = $this->page($c, $this->admin);

        $this->assertStringContainsString('data-contact-quick-links', $html);
        $this->assertStringContainsString('Schedule Event', $html);
        $this->assertStringNotContainsString(route('command-center.buyers.show', $c), $html);
        $this->assertStringNotContainsString('data-viewing-pack-quick', $html);
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

    public function test_rental_application_opens_pre_filled_from_a_contact_and_from_a_rental_property(): void
    {
        $buyer = $this->contact(['first_name' => 'Thabo', 'last_name' => 'Prefill']);
        $rental = $this->property(['listing_type' => 'rental', 'title' => '5 Rental Lane', 'mandate_type' => null, 'expiry_date' => null]);

        // the link is on the rental property's page (it was dropped from the contact page) for someone who may create applications
        $this->assertTrue($this->admin->hasPermission('rental_applications.create'));
        $this->assertStringNotContainsString('data-rental-application-quick', $this->page($buyer, $this->admin), 'dropped from the contact page (Johan)');
        $this->assertStringContainsString('data-rental-application-quick', $this->actingAs($this->admin)->get(route('corex.properties.show', $rental))->getContent());
        // ...but not on a property that is not a rental
        $sale = $this->property(['title' => '9 Sale Street']);
        $this->assertStringNotContainsString('data-rental-application-quick', $this->actingAs($this->admin)->get(route('corex.properties.show', $sale))->getContent());

        // the form opens with them picked
        $html = $this->actingAs($this->admin)->get(route('corex.rental-applications.create', ['contact_id' => $buyer->id, 'property_id' => $rental->id]))
            ->assertOk()->getContent();
        $html = str_replace('\\u0022', '"', $html);   // Js::from() ships the Alpine config with the quotes unicode-escaped
        $this->assertStringContainsString('Thabo Prefill', $html);
        $this->assertMatchesRegularExpression('/"contactId":' . $buyer->id . '\b/', $html);
        $this->assertMatchesRegularExpression('/"propertyId":' . $rental->id . '\b/', $html);
    }

    public function test_a_pre_filled_id_the_user_could_not_have_picked_is_ignored(): void
    {
        $sale = $this->property(['title' => '9 Sale Street']);                      // not a rental => not linkable
        $other = Agency::create(['name' => 'Far ' . uniqid(), 'slug' => 'far-' . uniqid()]);
        $farBranch = (int) DB::table('branches')->insertGetId(['agency_id' => $other->id, 'name' => 'Far', 'created_at' => now(), 'updated_at' => now()]);
        $foreignId = (int) DB::table('contacts')->insertGetId([
            'agency_id' => $other->id, 'branch_id' => $farBranch, 'first_name' => 'Far', 'last_name' => 'Away',
            'phone' => '0821234567', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->actingAs($this->admin)->get(route('corex.rental-applications.create', ['contact_id' => $foreignId, 'property_id' => $sale->id]))
            ->assertOk()->getContent();
        $html = str_replace('\\u0022', '"', $html);

        $this->assertStringNotContainsString('Far Away', $html, 'another agency\'s contact is never pre-filled');
        $this->assertStringNotContainsString('Uvongo', $html, 'a non-rental property is never pre-filled');
        $this->assertStringContainsString('"contactId":""', $html);
        $this->assertStringContainsString('"propertyId":""', $html);
    }
}
