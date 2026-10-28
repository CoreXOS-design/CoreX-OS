<?php

namespace Tests\Feature\AttorneyPortal;

use App\Mail\AttorneyPortalInviteMail;
use App\Models\AttorneyPortalAccess;
use App\Models\AttorneyPortalLog;
use App\Models\AttorneyPortalSetting;
use App\Services\AttorneyPortal\AttorneyPortalAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** .ai/specs/attorney-portal.md §4, §7, §8, §13 - the real link + emailed-code sign-in and what an attorney can reach over HTTP. */
class AttorneyPortalHttpTest extends TestCase
{
    use RefreshDatabase, AttorneyPortalFixtures;

    private function jar(string $cookie): array
    {
        return [config('attorney-portal.cookie') => $cookie];
    }

    public function test_attorney_a_sees_only_their_deals_and_colleague_b_sees_a_different_set(): void
    {
        $w = $this->world();
        $dealA = $this->deal($w, $this->transferTo($w, $w['a']), ['address' => '1 Alpha Road']);
        $dealB = $this->deal($w, $this->transferTo($w, $w['b']), ['address' => '2 Beta Road']);

        [, $cookieA] = $this->signIn($w['a'], $w['agent']);
        $list = $this->withUnencryptedCookies($this->jar($cookieA))->get(route('attorney.deals'));
        $list->assertOk()->assertSee('1 Alpha Road')->assertDontSee('2 Beta Road');
        $this->withUnencryptedCookies($this->jar($cookieA))->get(route('attorney.deal', $dealA['deal']->id))->assertOk()->assertSee('1 Alpha Road');
        // A guessing B's deal id learns nothing: 404, and the attempt is on the log
        $this->withUnencryptedCookies($this->jar($cookieA))->get(route('attorney.deal', $dealB['deal']->id))->assertNotFound();
        $this->assertDatabaseHas('attorney_portal_logs', ['event' => 'refused', 'deal_id' => $dealB['deal']->id]);

        Mail::fake();
        [, $cookieB] = $this->signIn($w['b'], $w['agent']);
        $this->withUnencryptedCookies($this->jar($cookieB))->get(route('attorney.deals'))
            ->assertOk()->assertSee('2 Beta Road')->assertDontSee('1 Alpha Road');
        $this->withUnencryptedCookies($this->jar($cookieB))->get(route('attorney.deal', $dealA['deal']->id))->assertNotFound();
    }

    public function test_the_invite_mails_a_personal_link_and_a_wrong_code_does_not_sign_in(): void
    {
        $w = $this->world();
        $r = app(AttorneyPortalAccessService::class)->invite($w['a'], $w['agent']);
        Mail::assertSent(AttorneyPortalInviteMail::class, fn ($m) => $m->hasTo('botha@bbb.co.za') && str_contains($m->url, '/attorney/p/'));

        $token = basename(parse_url($r['url'], PHP_URL_PATH));
        $this->get(route('attorney.link', $token))->assertOk()->assertSee('b***')->assertDontSee('botha@bbb.co.za');
        $this->post(route('attorney.code', $token))->assertRedirect();
        $this->post(route('attorney.verify', $token), ['code' => '000000'])->assertRedirect(route('attorney.link', $token))->assertSessionHasErrors('code');
        $this->get(route('attorney.deals'))->assertStatus(401);   // no cookie, no deals
        $this->assertDatabaseHas('attorney_portal_logs', ['event' => 'code_failed']);
    }

    public function test_unknown_expired_and_revoked_links_are_all_a_plain_404(): void
    {
        $w = $this->world();
        $this->get(route('attorney.link', 'doesnotexist' . str_repeat('x', 30)))->assertNotFound();

        $r = app(AttorneyPortalAccessService::class)->invite($w['a'], $w['agent']);
        $token = basename(parse_url($r['url'], PHP_URL_PATH));
        $this->get(route('attorney.link', $token))->assertOk();

        $r['access']->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->get(route('attorney.link', $token))->assertNotFound();

        $r2 = app(AttorneyPortalAccessService::class)->invite($w['a'], $w['agent']);   // resend revives and rotates the token
        $token2 = basename(parse_url($r2['url'], PHP_URL_PATH));
        $this->assertNotSame($token, $token2);
        $this->get(route('attorney.link', $token))->assertNotFound('the old link died when a new one was sent');
        $this->get(route('attorney.link', $token2))->assertOk();

        app(AttorneyPortalAccessService::class)->revoke($r2['access'], $w['agent']);
        $this->get(route('attorney.link', $token2))->assertNotFound();
        $this->post(route('attorney.code', $token2))->assertNotFound();
    }

    public function test_revoking_ends_an_open_session_on_its_next_click(): void
    {
        $w = $this->world();
        $this->deal($w, $this->transferTo($w, $w['a']));
        [, $cookie] = $this->signIn($w['a'], $w['agent']);
        $this->withUnencryptedCookies($this->jar($cookie))->get(route('attorney.deals'))->assertOk();

        $access = AttorneyPortalAccess::withoutGlobalScopes()->where('service_provider_contact_id', $w['a']->id)->first();
        app(AttorneyPortalAccessService::class)->revoke($access, $w['agent']);

        $this->withUnencryptedCookies($this->jar($cookie))->get(route('attorney.deals'))->assertStatus(401);
        $this->assertDatabaseHas('attorney_portal_logs', ['event' => 'revoked', 'access_id' => $access->id]);
    }

    public function test_switching_the_portal_off_closes_every_door(): void
    {
        $w = $this->world();
        $this->deal($w, $this->transferTo($w, $w['a']));
        [$token, $cookie] = $this->signIn($w['a'], $w['agent']);

        AttorneyPortalSetting::where('agency_id', $w['agency']->id)->update(['attorney_portal_enabled' => false]);

        $this->get(route('attorney.link', $token))->assertNotFound();
        $this->withUnencryptedCookies($this->jar($cookie))->get(route('attorney.deals'))->assertStatus(401);

        $this->expectException(\DomainException::class);
        app(AttorneyPortalAccessService::class)->invite($w['a'], $w['agent']);
    }

    public function test_every_view_and_download_is_logged_and_only_offered_documents_download(): void
    {
        $w = $this->world();
        $d = $this->deal($w, $this->transferTo($w, $w['a']));
        $this->matrix($w['agency']->id, 'otp', ['transfer_attorney']);
        $otp  = $this->filedDoc($d, 'otp', 'deal');
        $secret = $this->filedDoc($d, 'inspection_report', 'deal');   // not in the matrix for this role

        [, $cookie] = $this->signIn($w['a'], $w['agent']);
        $jar = $this->jar($cookie);

        $this->withUnencryptedCookies($jar)->get(route('attorney.deal', $d['deal']->id))->assertOk()->assertSee('OTP.pdf')->assertDontSee('INSPECTION_REPORT.pdf');

        $res = $this->withUnencryptedCookies($jar)->get(route('attorney.document', [$d['deal']->id, $otp->id, 'download']));
        $res->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('%PDF-1.4 otp', $res->getContent());

        $this->withUnencryptedCookies($jar)->get(route('attorney.document', [$d['deal']->id, $otp->id, 'view']))->assertOk();
        $this->withUnencryptedCookies($jar)->get(route('attorney.document', [$d['deal']->id, $secret->id, 'download']))->assertNotFound();

        $events = AttorneyPortalLog::orderBy('id')->get(['event', 'deal_id', 'document_id']);
        $this->assertTrue($events->contains(fn ($e) => $e->event === 'signed_in'));
        $this->assertTrue($events->contains(fn ($e) => $e->event === 'deal_viewed' && $e->deal_id == $d['deal']->id));
        $this->assertTrue($events->contains(fn ($e) => $e->event === 'document_downloaded' && $e->document_id == $otp->id));
        $this->assertTrue($events->contains(fn ($e) => $e->event === 'document_viewed' && $e->document_id == $otp->id));
        $this->assertTrue($events->contains(fn ($e) => $e->event === 'refused' && $e->document_id == $secret->id));
    }

    public function test_a_document_on_a_colleagues_deal_is_a_404_even_when_the_deal_is_guessed(): void
    {
        $w = $this->world();
        $dealB = $this->deal($w, $this->transferTo($w, $w['b']));
        $this->matrix($w['agency']->id, 'otp', ['transfer_attorney']);
        $docB = $this->filedDoc($dealB, 'otp', 'deal');
        $this->deal($w, $this->transferTo($w, $w['a']));

        [, $cookie] = $this->signIn($w['a'], $w['agent']);
        $this->withUnencryptedCookies($this->jar($cookie))->get(route('attorney.document', [$dealB['deal']->id, $docB->id, 'download']))->assertNotFound();
    }

    public function test_search_sort_filter_pagination_and_empty_states(): void
    {
        $w = $this->world();
        foreach (range(1, 23) as $i) {
            $this->deal($w, $this->transferTo($w, $w['a']), ['address' => sprintf('%02d Pageway', $i)]);
        }
        $this->deal($w, $this->transferTo($w, $w['a']), ['address' => '99 Findme Lane', 'registration_date' => now()->subDays(3)->toDateString()]);

        [, $cookie] = $this->signIn($w['a'], $w['agent']);
        $jar = $this->jar($cookie);

        $this->withUnencryptedCookies($jar)->get(route('attorney.deals'))->assertOk()->assertSee('Page 1 of 2');
        $this->withUnencryptedCookies($jar)->get(route('attorney.deals', ['page' => 2]))->assertOk()->assertSee('Page 2 of 2');
        $this->withUnencryptedCookies($jar)->get(route('attorney.deals', ['q' => 'findme']))->assertOk()->assertSee('99 Findme Lane')->assertDontSee('01 Pageway');
        $this->withUnencryptedCookies($jar)->get(route('attorney.deals', ['show' => 'finished']))->assertOk()->assertSee('99 Findme Lane')->assertSee('Available until');
        $this->withUnencryptedCookies($jar)->get(route('attorney.deals', ['q' => 'nothing-like-this']))->assertOk()->assertSee('No deals match your search.');
        $this->withUnencryptedCookies($jar)->get(route('attorney.deals', ['sort' => 'property', 'dir' => 'desc']))->assertOk()->assertSeeInOrder(['99 Findme Lane', '23 Pageway']);
    }

    public function test_a_person_with_no_deals_sees_the_friendly_empty_state(): void
    {
        $w = $this->world();
        [, $cookie] = $this->signIn($w['b'], $w['agent']);
        $this->withUnencryptedCookies($this->jar($cookie))->get(route('attorney.deals'))->assertOk()->assertSee('No deals yet.');
    }

    public function test_the_deal_page_shows_parties_agent_and_dates_but_no_party_contact_details(): void
    {
        $w = $this->world();
        $d = $this->deal($w, $this->transferTo($w, $w['a']), ['expected_registration' => '2026-12-15']);
        \DB::table('deal_user')->insert(['deal_id' => $d['deal']->id, 'user_id' => $w['agent']->id, 'side' => 'listing', 'created_at' => now(), 'updated_at' => now()]);

        [, $cookie] = $this->signIn($w['a'], $w['agent']);
        $page = $this->withUnencryptedCookies($this->jar($cookie))->get(route('attorney.deal', $d['deal']->id));
        $page->assertOk()->assertSee('Annelise vd Merwe')->assertSee('Pieter Buyer')->assertSee('Agent Smith')
            ->assertSee('Expected registration')->assertSee('15 December 2026')->assertSee('transferring attorney');
        $page->assertDontSee($d['seller']->email)->assertDontSee('0821234567')->assertDontSee($d['buyer']->email);
    }

    public function test_signing_out_ends_the_session(): void
    {
        $w = $this->world();
        [, $cookie] = $this->signIn($w['a'], $w['agent']);
        $this->withUnencryptedCookies($this->jar($cookie))->post(route('attorney.sign-out'))->assertRedirect(route('attorney.signed-out'));
        $this->withUnencryptedCookies($this->jar($cookie))->get(route('attorney.deals'))->assertStatus(401);
    }
}
