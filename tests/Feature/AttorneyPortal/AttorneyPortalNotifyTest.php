<?php

namespace Tests\Feature\AttorneyPortal;

use App\Mail\AttorneyPortalNewDocumentsMail;
use App\Mail\OtpMail;
use App\Models\AttorneyPortalAccess;
use App\Models\AttorneyPortalLog;
use App\Models\AttorneyPortalSetting;
use App\Models\Deal;
use App\Models\RolePermission;
use App\Services\AttorneyPortal\AttorneyPortalAccessService;
use App\Services\AttorneyPortal\AttorneyPortalNotifier;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** .ai/specs/attorney-portal.md 15 - the daily "new documents" note, its signed link, and the in-panel contact chooser. */
class AttorneyPortalNotifyTest extends TestCase
{
    use RefreshDatabase, AttorneyPortalFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A deal with contact A as transfer attorney, A invited, OTP + mandate in the matrix. Time frozen at 09:00 on a fixed day. */
    private function scene(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-11-02 09:00:00'));
        $w = $this->world();
        $d = $this->deal($w, $this->transferTo($w, $w['a']), ['address' => '12 Marine Dr']);
        foreach (['otp', 'mandate', 'fica'] as $slug) {
            $this->matrix($w['agency']->id, $slug, ['transfer_attorney']);
        }
        $access = app(AttorneyPortalAccessService::class)->invite($w['a'], $w['agent'])['access'];
        Mail::fake();   // forget the invite mail; only the digest is under test

        return [$w, $d, $access];
    }

    private function later(string $at): void
    {
        Carbon::setTestNow(Carbon::parse($at));
    }

    public function test_new_documents_in_the_attorneys_set_get_one_plain_note_per_deal_per_day(): void
    {
        [$w, $d, $access] = $this->scene();
        $this->later('2026-11-02 10:00:00');
        $this->filedDoc($d, 'otp', 'deal');
        $this->filedDoc($d, 'mandate', 'property');
        $this->filedDoc($d, 'fica', 'contact', $d['buyer']);

        $r = app(AttorneyPortalNotifier::class)->run();
        $this->assertSame(1, $r['sent']);

        Mail::assertSent(AttorneyPortalNewDocumentsMail::class, function ($m) {
            $html = $m->render();
            $this->assertTrue($m->hasTo('botha@bbb.co.za'));
            $this->assertSame('New documents for 12 Marine Dr', $m->envelope()->subject);
            $this->assertEmpty($m->attachments, 'never an attachment');
            $this->assertEmpty($m->rawAttachments);
            $this->assertStringContainsString('OTP (Offer to Purchase)', $html);   // the KIND of document ...
            $this->assertStringNotContainsString('.pdf', $html);                   // ... never a file name
            $this->assertStringContainsString('/attorney/open/', $html);
            $this->assertStringContainsString('never attach', $html);
            return true;
        });
        $this->assertDatabaseHas('attorney_portal_logs', ['event' => 'notified', 'deal_id' => $d['deal']->id]);

        // a second run the same day, even with another new document, says nothing more
        $this->later('2026-11-02 15:00:00');
        $this->filedDoc($d, 'otp', 'deal');
        $this->assertSame(0, app(AttorneyPortalNotifier::class)->run()['sent']);
        Mail::assertSent(AttorneyPortalNewDocumentsMail::class, 1);

        // the next day it covers only what is new since, and a quiet day sends nothing
        $this->later('2026-11-03 08:00:00');
        $this->assertSame(1, app(AttorneyPortalNotifier::class)->run()['sent']);
        $this->later('2026-11-04 08:00:00');
        $this->assertSame(0, app(AttorneyPortalNotifier::class)->run()['sent']);
        Mail::assertSent(AttorneyPortalNewDocumentsMail::class, 2);
    }

    public function test_nothing_is_sent_for_documents_that_existed_before_the_access_or_are_not_in_their_set(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-02 08:00:00'));
        $w = $this->world();
        $d = $this->deal($w, $this->transferTo($w, $w['a']));
        $this->matrix($w['agency']->id, 'otp', ['transfer_attorney']);
        $this->filedDoc($d, 'otp', 'deal');                       // filed before the invite
        $this->later('2026-11-02 09:00:00');
        app(AttorneyPortalAccessService::class)->invite($w['a'], $w['agent']);
        Mail::fake();

        $this->later('2026-11-02 10:00:00');
        $this->filedDoc($d, 'inspection_report', 'deal');         // not in the matrix for this role
        $colleague = $this->deal($w, $this->transferTo($w, $w['b']));
        $this->filedDoc($colleague, 'otp', 'deal');              // someone else's deal

        $this->assertSame(0, app(AttorneyPortalNotifier::class)->run()['sent']);
        Mail::assertNothingSent();
    }

    public function test_a_paper_linked_to_the_property_later_counts_from_when_it_was_linked(): void
    {
        [$w, $d, $access] = $this->scene();
        $this->later('2026-11-02 08:00:00');                       // the mandate document existed long before the access ...
        Carbon::setTestNow(Carbon::parse('2026-10-01 08:00:00'));
        $mandate = $this->filedDoc($d, 'mandate', 'upload');
        $this->later('2026-11-02 11:00:00');                       // ... and was only linked to the property today
        $mandate->properties()->syncWithoutDetaching([$d['property']->id]);

        $this->assertSame(1, app(AttorneyPortalNotifier::class)->run()['sent']);
    }

    public function test_the_agency_can_switch_it_off_and_a_switched_off_portal_or_revoked_access_sends_nothing(): void
    {
        [$w, $d, $access] = $this->scene();
        $this->later('2026-11-02 10:00:00');
        $this->filedDoc($d, 'otp', 'deal');

        AttorneyPortalSetting::where('agency_id', $w['agency']->id)->update(['attorney_portal_notify' => 'off']);
        $this->assertSame(0, app(AttorneyPortalNotifier::class)->run()['sent']);

        AttorneyPortalSetting::where('agency_id', $w['agency']->id)->update(['attorney_portal_notify' => null]);   // null = the default, daily
        AttorneyPortalSetting::where('agency_id', $w['agency']->id)->update(['attorney_portal_enabled' => false]);
        $this->assertSame(0, app(AttorneyPortalNotifier::class)->run()['sent']);

        AttorneyPortalSetting::where('agency_id', $w['agency']->id)->update(['attorney_portal_enabled' => true]);
        app(AttorneyPortalAccessService::class)->revoke($access->fresh(), $w['agent']);
        $this->assertSame(0, app(AttorneyPortalNotifier::class)->run()['sent']);
        Mail::assertNothingSent();

        // the default really is daily
        $this->assertSame('daily', AttorneyPortalSetting::forAgency(999999)->notifyMode());
    }

    public function test_the_dry_run_command_counts_without_sending(): void
    {
        [$w, $d] = $this->scene();
        $this->later('2026-11-02 10:00:00');
        $this->filedDoc($d, 'otp', 'deal');

        $this->artisan('attorney-portal:notify-new-documents', ['--dry-run' => true])->expectsOutputToContain('would send 1')->assertSuccessful();
        Mail::assertNothingSent();
        $this->artisan('attorney-portal:notify-new-documents')->expectsOutputToContain('sent 1')->assertSuccessful();
        Mail::assertSent(AttorneyPortalNewDocumentsMail::class, 1);
    }

    public function test_the_signed_link_asks_for_the_code_then_lands_on_the_deal(): void
    {
        [$w, $d, $access] = $this->scene();
        $this->later('2026-11-02 10:00:00');
        $this->filedDoc($d, 'otp', 'deal');
        app(AttorneyPortalNotifier::class)->run();
        $url = null;
        Mail::assertSent(AttorneyPortalNewDocumentsMail::class, function ($m) use (&$url) { $url = $m->url; return true; });

        // a tampered or unsigned address is refused
        $this->get(preg_replace('/signature=[^&]+/', 'signature=nope', $url))->assertForbidden();
        $this->get(route('attorney.open', ['access' => $access->id, 'deal' => $d['deal']->id]))->assertForbidden();

        $this->get($url)->assertOk()->assertSee('Email me a code');
        $this->post(URL::temporarySignedRoute('attorney.open.code', now()->addHour(), ['access' => $access->id, 'deal' => $d['deal']->id]))->assertRedirect();
        $code = null;
        Mail::assertSent(OtpMail::class, function (OtpMail $m) use (&$code) { $code = $m->code; return true; });

        $resp = $this->post(URL::temporarySignedRoute('attorney.open.verify', now()->addHour(), ['access' => $access->id, 'deal' => $d['deal']->id]), ['code' => $code]);
        $resp->assertRedirect(route('attorney.deal', $d['deal']->id));
        $cookie = $resp->getCookie(config('attorney-portal.cookie'), false)->getValue();
        $this->withUnencryptedCookies([config('attorney-portal.cookie') => $cookie])->get(route('attorney.deal', $d['deal']->id))->assertOk()->assertSee('12 Marine Dr');
        // signed in already: the same email link goes straight to the deal
        $this->withUnencryptedCookies([config('attorney-portal.cookie') => $cookie])->get($url)->assertRedirect(route('attorney.deal', $d['deal']->id));
    }

    public function test_a_revoked_access_makes_the_email_link_a_404(): void
    {
        [$w, $d, $access] = $this->scene();
        app(AttorneyPortalAccessService::class)->revoke($access->fresh(), $w['agent']);
        $url = URL::temporarySignedRoute('attorney.open', now()->addDay(), ['access' => $access->id, 'deal' => $d['deal']->id]);
        $this->get($url)->assertNotFound();
    }

    // ── the panel's "Choose the attorney contact" ──────────────────────────

    /** The agent is on the deal (the deal-access check lets an own-scope agent edit only their own deals). */
    private function onDeal(array $w, array $d): void
    {
        \DB::table('deal_user')->insert(['deal_id' => $d['deal']->id, 'user_id' => $w['agent']->id, 'side' => 'listing', 'created_at' => now(), 'updated_at' => now()]);
        RolePermission::create(['role' => $w['agent']->role, 'permission_key' => 'deals.view', 'scope' => 'own']);
        PermissionService::clearCache();
    }

    private function grant($agent, array $keys): void
    {
        foreach ($keys as $key) {
            RolePermission::create(['role' => $agent->role, 'permission_key' => $key, 'scope' => 'agency']);
        }
        PermissionService::clearCache();
    }

    public function test_a_firm_without_a_person_shows_the_next_action_with_the_control_right_there(): void
    {
        $w = $this->world();
        $d = $this->deal($w, ['transfer_firm' => $w['firm']->id]);       // firm chosen, no person
        $this->grant($w['agent'], ['deals_v2.invite_attorney', 'create_deals']);
        $this->actingAs($w['agent']);

        $html = view('dr2._attorney-portal-access', ['deal' => $d['deal']->fresh()])->render();
        $this->assertStringContainsString('Choose the attorney contact for this deal', $html);
        $this->assertStringContainsString('data-choose-attorney-contact', $html);
        $this->assertStringContainsString('Adv Botha', $html);
        $this->assertStringContainsString('Adv Naidoo', $html);
        $this->assertStringContainsString('Use this contact', $html);
    }

    public function test_choosing_the_contact_sets_the_deal_and_unlocks_the_send_button(): void
    {
        $w = $this->world();
        $d = $this->deal($w, ['transfer_firm' => $w['firm']->id]);
        $this->grant($w['agent'], ['deals_v2.invite_attorney', 'create_deals']);
        $this->onDeal($w, $d);
        $this->actingAs($w['agent']);

        $this->post(route('deals-dr2.pipeline.attorney-contact', $d['deal']), ['role' => 'transfer_attorney', 'contact_id' => $w['b']->id])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame($w['b']->id, (int) Deal::withoutGlobalScopes()->find($d['deal']->id)->attorney_contact_id);

        $html = view('dr2._attorney-portal-access', ['deal' => Deal::withoutGlobalScopes()->find($d['deal']->id)])->render();
        $this->assertStringNotContainsString('Choose the attorney contact', $html);
        $this->assertStringContainsString('Send portal link', $html);
        $this->assertStringContainsString('Adv Naidoo', $html);
    }

    public function test_the_chooser_only_accepts_an_active_contact_of_that_deals_firm(): void
    {
        $w = $this->world();
        $d = $this->deal($w, ['transfer_firm' => $w['firm']->id]);
        $this->grant($w['agent'], ['deals_v2.invite_attorney', 'create_deals']);
        $this->onDeal($w, $d);
        $this->actingAs($w['agent']);

        $other = \App\Models\DealV2\AgencyServiceProvider::create(['agency_id' => $w['agency']->id, 'name' => 'Other Firm', 'specialty' => 'transfer_attorney', 'is_active' => true, 'created_by_id' => $w['agent']->id]);
        $stranger = \App\Models\DealV2\AgencyServiceProviderContact::create(['agency_id' => $w['agency']->id, 'service_provider_id' => $other->id, 'attorney_name' => 'Not Ours', 'email' => 'x@y.co.za', 'is_active' => true, 'created_by_id' => $w['agent']->id]);
        $w['b']->forceFill(['is_active' => false])->save();

        $this->post(route('deals-dr2.pipeline.attorney-contact', $d['deal']), ['role' => 'transfer_attorney', 'contact_id' => $stranger->id])->assertSessionHasErrors('portal');
        $this->post(route('deals-dr2.pipeline.attorney-contact', $d['deal']), ['role' => 'transfer_attorney', 'contact_id' => $w['b']->id])->assertSessionHasErrors('portal');
        $this->post(route('deals-dr2.pipeline.attorney-contact', $d['deal']), ['role' => 'bond_attorney', 'contact_id' => $w['a']->id])->assertSessionHasErrors('portal');   // no bond firm on the deal
        $this->assertNull(Deal::withoutGlobalScopes()->find($d['deal']->id)->attorney_contact_id);
    }

    public function test_the_chooser_needs_permission_to_edit_the_deal_and_says_so_when_you_lack_it(): void
    {
        $w = $this->world();
        $d = $this->deal($w, ['transfer_firm' => $w['firm']->id]);
        $this->grant($w['agent'], ['deals_v2.invite_attorney']);        // may invite, may NOT edit deals
        $this->actingAs($w['agent']);

        $this->post(route('deals-dr2.pipeline.attorney-contact', $d['deal']), ['role' => 'transfer_attorney', 'contact_id' => $w['a']->id])->assertForbidden();
        $html = view('dr2._attorney-portal-access', ['deal' => $d['deal']->fresh()])->render();
        $this->assertStringContainsString('Choose the attorney contact for this deal', $html);
        $this->assertStringContainsString("You can't edit this deal", $html);
        $this->assertStringNotContainsString('data-choose-attorney-contact', $html);
    }

    public function test_a_firm_with_no_contacts_points_to_the_supplier_directory(): void
    {
        $w = $this->world();
        $empty = \App\Models\DealV2\AgencyServiceProvider::create(['agency_id' => $w['agency']->id, 'name' => 'Lonely Inc', 'specialty' => 'transfer_attorney', 'is_active' => true, 'created_by_id' => $w['agent']->id]);
        $d = $this->deal($w, ['transfer_firm' => $empty->id]);
        $this->grant($w['agent'], ['deals_v2.invite_attorney', 'create_deals']);
        $this->actingAs($w['agent']);

        $html = view('dr2._attorney-portal-access', ['deal' => $d['deal']->fresh()])->render();
        $this->assertStringContainsString('Lonely Inc has no contacts yet', $html);
        $this->assertStringContainsString(route('deals-v2.suppliers.index'), $html);
    }

    public function test_the_notify_choice_saves_from_the_settings_page_and_is_in_the_wizard(): void
    {
        $w = $this->world(false);
        $this->grant($w['agent'], ['attorney_portal.manage_settings']);
        $this->actingAs($w['agent']);

        $this->get(route('corex.settings.attorney-portal.edit'))->assertOk()->assertSee('Email attorneys when new documents are filed');
        $this->post(route('corex.settings.attorney-portal.update'), ['attorney_portal_notify' => 'off'])->assertRedirect();
        $this->assertSame('off', AttorneyPortalSetting::forAgency($w['agency']->id)->notifyMode());
        $this->assertFalse(AttorneyPortalSetting::forAgency($w['agency']->id)->isEnabled(), 'a post carrying only the notify choice leaves the switch alone');
        $this->post(route('corex.settings.attorney-portal.update'), ['attorney_portal_notify' => 'weekly'])->assertSessionHasErrors('attorney_portal_notify');

        $c = collect(config('agency-onboarding-copy.properties.controls'))->firstWhere('key', 'attorney_portal_notify');
        $this->assertSame('daily', $c['default']);
        $this->assertNotEmpty($c['explain']);
        $this->assertNotEmpty($c['affects']);
    }
}
