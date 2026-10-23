<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationDr2Dismissal;
use App\Models\Communications\CommunicationLink;
use App\Models\Deal;
use App\Models\DealV2\DealV2;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Communications\UnfiledDealEmailsQuery;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-454 (23 Oct 2026): the count badge on "Unfiled deal emails" must be exactly the number the screen lists.
 *
 * It used to count every email of the agency without a deal link (menu in corex-sidebar.blade.php), so it ignored
 * the deal-party match, the viewer's own/branch/all visibility, removed emails and unconfirmed links. Both now read
 * App\Services\Communications\UnfiledDealEmailsQuery.
 *
 * Input paths proven, each with the badge and the screen's own total side by side: a matching unfiled email
 * (counted); an email with no deal party on it (not); a removed email (not) and a restored one (counted again);
 * a provisional (unconfirmed) link (counted - nobody confirmed it) and a confirmed link (not); another agency's
 * email (not); a colleague's email under own scope (not) and under all scope (counted); nothing to file (no badge).
 */
final class UnfiledBadgeMatchesListTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private User $agent;
    private User $colleague;
    private Deal $deal;
    private int $dealV2Id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);

        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agencyId]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'view_deals', 'agency_id' => $this->agencyId]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'sidebar.section.agents', 'agency_id' => $this->agencyId]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'deals.view', 'scope' => 'own', 'agency_id' => $this->agencyId]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'dr2_unfiled_emails.view', 'scope' => 'own', 'agency_id' => $this->agencyId]);
        Role::clearCache();
        PermissionService::clearCache();

        $this->agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent', 'is_active' => true]);
        $this->colleague = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent', 'is_active' => true]);

        $this->dealV2Id = (int) DB::table('deals_v2')->insertGetId([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'bond', 'listing_agent_id' => $this->agent->id,
            'purchase_price' => 1_500_000, 'commission_amount' => 75_000, 'commission_vat' => 11_250,
            'offer_date' => '2026-03-01', 'branch_id' => $this->branchId, 'agency_id' => $this->agencyId,
            'created_by_id' => $this->agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->deal = Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 1_500_000, 'total_commission' => 86_250,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => random_int(1000, 9999), 'deal_type' => 'bond',
            'seller_name' => 'Test Seller', 'property_address' => '1 Test Rd',
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'deal_v2_id' => $this->dealV2Id,
        ]));
        $this->deal->agents()->attach($this->agent->id, ['side' => 'selling']);

        $contactId = (int) DB::table('contacts')->insertGetId([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'email' => 'party@attorneys.co.za',
            'first_name' => 'Test', 'last_name' => 'Party', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deal_contacts')->insert([
            'deal_id' => $this->deal->id, 'contact_id' => $contactId, 'role' => 'seller', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function comm(array $over = []): Communication
    {
        return Communication::create(array_merge([
            'agency_id' => $this->agencyId, 'channel' => Communication::CHANNEL_EMAIL, 'direction' => Communication::DIRECTION_INBOUND,
            'external_id' => Str::random(14), 'thread_key' => null, 'from_identifier' => 'party@attorneys.co.za',
            'subject' => 'Subject ' . Str::random(6), 'body_text' => 'Body', 'occurred_at' => now(), 'captured_at' => now(),
            'owner_user_id' => $this->agent->id, 'has_attachments' => false,
        ], $over));
    }

    private function link(Communication $c, bool $confirmed): void
    {
        CommunicationLink::create([
            'agency_id' => $this->agencyId, 'communication_id' => $c->id, 'linkable_type' => DealV2::class, 'linkable_id' => $this->dealV2Id,
            'link_method' => CommunicationLink::METHOD_MANUAL,
            'confirmed_by' => $confirmed ? $this->agent->id : null, 'confirmed_at' => $confirmed ? now() : null,
        ]);
    }

    private function dismiss(Communication $c): CommunicationDr2Dismissal
    {
        return CommunicationDr2Dismissal::create([
            'agency_id' => $this->agencyId, 'communication_id' => $c->id, 'reason' => CommunicationDr2Dismissal::REASON_NOT_DEAL_RELATED,
            'dismissed_by_user_id' => $this->agent->id, 'dismissed_at' => now(),
        ]);
    }

    /** [badge count, what the screen lists] for the same user, read independently. */
    private function badgeAndList(User $user): array
    {
        PermissionService::clearCache();
        $badge = app(UnfiledDealEmailsQuery::class)->countFor($user);
        $list = $this->actingAs($user)->get(route('deals-dr2.unfiled-emails.index'))->assertOk()->viewData('emails')->total();

        return [$badge, $list];
    }

    public function test_the_badge_equals_the_list_across_every_kind_of_email(): void
    {
        // counted
        $this->comm(['subject' => 'matches a deal party']);
        $provisional = $this->comm(['subject' => 'only a machine guess is linked']);
        $this->link($provisional, false);
        // not counted
        $this->comm(['subject' => 'no deal party on it', 'from_identifier' => 'stranger@nowhere.test']);
        $this->link($this->comm(['subject' => 'confirmed to the deal']), true);
        $this->dismiss($this->comm(['subject' => 'removed by an agent']));
        $this->comm(['subject' => 'a colleague\'s email', 'owner_user_id' => $this->colleague->id]);
        $otherAgency = (int) DB::table('agencies')->insertGetId(['name' => 'Other', 'slug' => 'o-' . Str::random(6), 'created_at' => now(), 'updated_at' => now()]);
        $this->comm(['subject' => 'another agency\'s email', 'agency_id' => $otherAgency]);

        [$badge, $list] = $this->badgeAndList($this->agent);

        $this->assertSame(2, $list, 'the screen lists the party match and the provisional link');
        $this->assertSame($list, $badge, 'the badge is that same number');
    }

    public function test_a_restored_email_counts_again_and_a_removed_one_stops_counting(): void
    {
        $c = $this->comm(['subject' => 'toggled']);
        $this->assertSame([1, 1], $this->badgeAndList($this->agent));

        $d = $this->dismiss($c);
        $this->assertSame([0, 0], $this->badgeAndList($this->agent));

        $d->update(['restored_at' => now(), 'restored_by_user_id' => $this->agent->id]);
        $this->assertSame([1, 1], $this->badgeAndList($this->agent));
    }

    public function test_a_wider_scope_widens_badge_and_list_together(): void
    {
        $this->comm(['subject' => 'mine']);
        $this->comm(['subject' => 'colleague\'s', 'owner_user_id' => $this->colleague->id]);
        $this->assertSame([1, 1], $this->badgeAndList($this->agent), 'own scope: only my email');

        RolePermission::where('role', 'agent')->where('permission_key', 'dr2_unfiled_emails.view')->update(['scope' => 'all']);
        Role::clearCache();

        $this->assertSame([2, 2], $this->badgeAndList($this->agent), 'all scope: both, badge and list alike');
    }

    public function test_the_menu_shows_the_number_and_hides_the_badge_when_nothing_is_to_file(): void
    {
        $html = $this->actingAs($this->agent)->get(route('deals-dr2.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Unfiled deal emails', $html, 'the menu entry is rendered for this agent');
        $this->assertDoesNotMatchRegularExpression('~Unfiled deal emails</span>\s*<span style="display:inline-block;min-width:18px~', $html);

        $this->comm();
        $this->comm();
        $this->comm(['from_identifier' => 'stranger@nowhere.test']); // old badge counted this one too
        PermissionService::clearCache();

        $html = $this->actingAs($this->agent)->get(route('deals-dr2.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~Unfiled deal emails</span>\s*<span style="display:inline-block;min-width:18px[^>]*>2</span>~', $html);
    }
}
