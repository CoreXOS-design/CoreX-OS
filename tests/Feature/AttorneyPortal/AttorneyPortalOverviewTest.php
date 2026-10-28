<?php

namespace Tests\Feature\AttorneyPortal;

use App\Models\Agency;
use App\Models\AttorneyPortalAccess;
use App\Models\AttorneyPortalSetting;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderContact;
use App\Models\RolePermission;
use App\Models\Scopes\AgencyScope;
use App\Services\AttorneyPortal\AttorneyPortalAccessService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** attorney-portal.md 16 - Deals menu -> Attorney Portal: switch status, three plain lines, who has access, Send / Resend / Revoke. */
class AttorneyPortalOverviewTest extends TestCase
{
    use RefreshDatabase, AttorneyPortalFixtures;

    private function grant($agent, array $keys): void
    {
        foreach ($keys as $key) {
            RolePermission::create(['role' => $agent->role, 'permission_key' => $key, 'scope' => 'agency']);
        }
        PermissionService::clearCache();
    }

    private function page(array $q = [])
    {
        return $this->get(route('deals-v2.attorney-portal.index', $q));
    }

    public function test_it_needs_the_invite_permission_and_is_in_the_deals_menu_for_those_who_have_it(): void
    {
        $w = $this->world();
        $this->grant($w['agent'], ['deals_v2.view', 'sidebar.section.agents', 'view_deals']);
        $this->actingAs($w['agent']);
        $this->page()->assertForbidden();
        $this->assertStringNotContainsString('>Attorney Portal<', $this->get(route('deals-dr2.index'))->getContent(), 'no entry without the invite permission');

        $this->grant($w['agent'], ['deals_v2.invite_attorney']);
        $this->page()->assertOk()->assertSee('Attorney Portal');
        $html  = $this->get(route('deals-v2.attorney-portal.index'))->getContent();
        $parts = explode('<div class="corex-nav-panel-title">Deals</div>', $html, 2);
        $this->assertCount(2, $parts, 'the Deals panel is on the page');
        $panel = substr($parts[1], 0, (int) strpos($parts[1], 'corex-nav-panel-title">') ?: null);
        $this->assertMatchesRegularExpression('/<a href="[^"]*deals-v2\/attorney-portal"[^>]*>Attorney Portal<\/a>/', $panel, 'the entry sits in the Deals panel');
        $this->assertStringContainsString('corex-nav-subitem active', substr($panel, (int) strpos($panel, 'deals-v2/attorney-portal') - 200, 400), 'and is highlighted on its own page');
    }

    public function test_it_shows_the_switch_how_it_works_in_three_lines_and_the_settings_link_only_to_those_who_may_use_it(): void
    {
        $w = $this->world(false);
        $this->grant($w['agent'], ['deals_v2.invite_attorney']);
        $this->actingAs($w['agent']);

        $this->page()->assertOk()->assertSee('Switched off')->assertSee('Attorneys cannot sign in while this is off.')
            ->assertSee('1. You send a link.')->assertSee('2. They confirm it is them.')->assertSee('3. They see only their own deals.')
            ->assertDontSee('data-po-settings', false);

        AttorneyPortalSetting::where('agency_id', $w['agency']->id)->update(['attorney_portal_enabled' => true, 'access_grace_days' => 14]);
        $this->grant($w['agent'], ['attorney_portal.manage_settings']);
        $this->page()->assertSee('Switched on')->assertSee('Access ends 14 days after a deal is registered or cancelled.')
            ->assertSee(route('corex.settings.attorney-portal.edit'), false)->assertSee('data-po-settings', false);
    }

    public function test_it_lists_every_attorney_contact_with_deals_last_sign_in_and_link_status(): void
    {
        $w = $this->world();
        $this->grant($w['agent'], ['deals_v2.invite_attorney']);
        $this->actingAs($w['agent']);
        $this->deal($w, $this->transferTo($w, $w['a']));
        $this->deal($w, $this->transferTo($w, $w['a']));
        $this->deal($w, $this->transferTo($w, $w['b']));

        // A: invited and has signed in; B: not invited yet
        $svc = app(AttorneyPortalAccessService::class);
        $a = $svc->invite($w['a'], $w['agent'])['access'];
        $a->forceFill(['last_login_at' => now()->subHour()])->save();

        $res  = $this->page()->assertOk();
        $rows = collect($res->viewData('rows')->items())->keyBy('contact_id');
        $this->assertCount(2, $rows);
        $this->assertSame([2, 'live'], [$rows[$w['a']->id]['deals'], $rows[$w['a']->id]['status']]);
        $this->assertSame([1, 'none'], [$rows[$w['b']->id]['deals'], $rows[$w['b']->id]['status']]);
        $res->assertSee('Adv Botha')->assertSee('Adv Naidoo')->assertSee('BBB Inc')->assertSee('Link active')->assertSee('Not invited yet')
            ->assertSee('Send a new link')->assertSee('Send link')->assertSee('Remove access')
            ->assertSee(now()->subHour()->format('j M Y H:i'));

        // buttons post to the existing invite / revoke routes
        $res->assertSee(route('deals-v2.attorney-portal.invite', ['contact' => $w['b']->id]), false)
            ->assertSee(route('deals-v2.attorney-portal.revoke', ['access' => $a->id]), false);

        // after the revoke (through the very route the button uses) the row says so and offers a new link, not a second revoke
        $this->post(route('deals-v2.attorney-portal.revoke', $a->id))->assertRedirect();
        $res = $this->page()->assertSee('Access removed');
        $row = collect($res->viewData('rows')->items())->firstWhere('contact_id', $w['a']->id);
        $this->assertSame('revoked', $row['status']);
        $this->assertSame(0, substr_count($res->getContent(), 'data-po-revoke'), 'an access that is already removed offers no second Remove');
        $res->assertSee('Send a new link');
    }

    public function test_the_buttons_explain_why_a_link_cannot_be_sent(): void
    {
        $w = $this->world(false);
        $this->grant($w['agent'], ['deals_v2.invite_attorney']);
        $this->actingAs($w['agent']);
        $this->page()->assertSee('Switch the attorney portal on first.');

        AttorneyPortalSetting::where('agency_id', $w['agency']->id)->update(['attorney_portal_enabled' => true]);
        AgencyServiceProviderContact::withoutGlobalScopes()->where('id', $w['b']->id)->update(['email' => null]);
        $this->page()->assertSee('This contact has no email address yet.')->assertSee('No email address yet');
    }

    public function test_search_filter_sort_and_pages(): void
    {
        $w = $this->world();
        $this->grant($w['agent'], ['deals_v2.invite_attorney']);
        $this->actingAs($w['agent']);
        foreach (range(1, 26) as $i) {
            AgencyServiceProviderContact::create(['agency_id' => $w['agency']->id, 'service_provider_id' => $w['firm']->id, 'attorney_name' => sprintf('Zed Person %02d', $i), 'email' => "z$i@bbb.co.za", 'is_active' => true, 'created_by_id' => $w['agent']->id]);
        }
        app(AttorneyPortalAccessService::class)->invite($w['a'], $w['agent']);

        $all = $this->page()->assertOk();
        $this->assertSame(28, $all->viewData('rows')->total());
        $all->assertSee('Showing 1&ndash;25 of 28', false)->assertSee('Page 1 of 2');
        $this->page(['page' => 2])->assertSee('Showing 26&ndash;28 of 28', false);

        $this->assertSame(1, $this->page(['q' => 'botha'])->viewData('rows')->total());
        $this->assertSame(28, $this->page(['q' => 'bbb'])->viewData('rows')->total(), 'the firm name is searchable');
        $this->assertSame(1, $this->page(['status' => 'live'])->viewData('rows')->total());
        $this->assertSame(27, $this->page(['status' => 'none'])->viewData('rows')->total());
        $this->assertSame('Zed Person 26', $this->page(['sort' => 'name', 'dir' => 'desc'])->viewData('rows')->first()['name']);
        $this->assertSame('Adv Botha', $this->page(['sort' => 'status'])->viewData('rows')->first()['name'], 'live links first');
        foreach (['name', 'firm', 'deals', 'login', 'status'] as $col) {
            $this->page(['sort' => $col, 'dir' => 'asc'])->assertOk();
        }
        $this->page(['q' => 'nobody-like-this'])->assertSee('No attorneys match');
    }

    public function test_an_agency_with_no_attorney_contacts_sees_the_empty_state_and_never_another_agencys_people(): void
    {
        $w = $this->world();
        $other = Agency::create(['name' => 'Other Realty', 'slug' => 'or-' . uniqid()]);
        $firm  = AgencyServiceProvider::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $other->id, 'name' => 'Their Firm', 'specialty' => 'transfer_attorney', 'email' => 'x@their.co.za', 'is_active' => true, 'created_by_id' => $w['agent']->id]);
        AgencyServiceProviderContact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $other->id, 'service_provider_id' => $firm->id, 'attorney_name' => 'Their Attorney', 'email' => 'their@their.co.za', 'is_active' => true, 'created_by_id' => $w['agent']->id]);
        $this->grant($w['agent'], ['deals_v2.invite_attorney']);
        $this->actingAs($w['agent']);

        $this->page()->assertOk()->assertSee('Adv Botha')->assertDontSee('Their Attorney')->assertDontSee('Their Firm');

        AgencyServiceProviderContact::withoutGlobalScopes()->where('agency_id', $w['agency']->id)->delete();
        $this->page()->assertSee('No attorney contacts yet')->assertSee('Supplier Directory');
    }
}
