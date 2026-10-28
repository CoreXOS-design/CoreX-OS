<?php

namespace Tests\Feature\AttorneyPortal;

use App\Mail\OtpMail;
use App\Services\AttorneyPortal\AttorneyPortalAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** attorney-portal.md 6.1 - the sign-in page tells the truth about the emailed code: sent (and when), or blocked (and for how long). */
class AttorneyPortalSignInCodesTest extends TestCase
{
    use RefreshDatabase, AttorneyPortalFixtures;

    private function link(array $w, $contact): array
    {
        $r = app(AttorneyPortalAccessService::class)->invite($contact, $w['agent']);

        return [basename(parse_url($r['url'], PHP_URL_PATH)), $r['access']];
    }

    private function sentKey($access): string
    {
        return 'attorney_code_sent_' . $access->id;
    }

    public function test_a_fresh_link_page_makes_no_claim_about_an_email(): void
    {
        $w = $this->world();
        [$token] = $this->link($w, $w['a']);

        $this->get(route('attorney.link', $token))->assertOk()->assertSee('Email me a code')
            ->assertDontSee('We emailed')->assertDontSee('Too many codes');
    }

    public function test_when_a_code_was_sent_the_page_says_so_with_the_time(): void
    {
        $w = $this->world();
        [$token, $access] = $this->link($w, $w['a']);
        Carbon::setTestNow(Carbon::parse('2026-10-28 14:32:10'));

        $page = $this->followingRedirects()->post(route('attorney.code', $token))->assertOk();
        Mail::assertSent(OtpMail::class, 1);
        $page->assertSee('We emailed a 6-digit code to')->assertSee('<strong>14:32</strong>', false)->assertSee('Type the code here')->assertDontSee('Too many codes');
        Carbon::setTestNow();
    }

    public function test_when_the_hourly_limit_blocked_sending_only_the_wait_is_shown_even_after_an_earlier_send(): void
    {
        $w = $this->world();
        config(['attorney-portal.otp.hourly_limit' => 3]);
        [$token, $access] = $this->link($w, $w['a']);
        $key = 'otp.hourly:attorney_portal:contact-' . $w['a']->id;
        foreach (range(1, 3) as $i) {
            RateLimiter::hit($key, 3600);
        }

        // an earlier send left the page believing a code was emailed - the very case that used to lie
        $page = $this->withSession([$this->sentKey($access) => now()->subMinutes(20)->timestamp])
            ->followingRedirects()->post(route('attorney.code', $token))->assertOk();

        Mail::assertNotSent(OtpMail::class);
        $page->assertSee('Too many codes were requested. Please try again in')->assertSee('minutes', false)
            ->assertDontSee('We emailed')->assertDontSee('6-digit')->assertDontSee('Type the code here')->assertDontSee('Email me a code')->assertDontSee('Send me a new code');
        $this->assertMatchesRegularExpression('/try again in (\d+) minutes/', $page->getContent());
    }

    public function test_a_second_request_inside_the_pause_says_how_many_seconds_and_keeps_the_code_form(): void
    {
        $w = $this->world();
        [$token, $access] = $this->link($w, $w['a']);
        $this->followingRedirects()->post(route('attorney.code', $token))->assertOk();
        Mail::assertSent(OtpMail::class, 1);

        $page = $this->followingRedirects()->post(route('attorney.code', $token))->assertOk();
        Mail::assertSent(OtpMail::class, 1);   // still just the one
        $page->assertSee('A code was only just sent. You can ask for another in')->assertSee('seconds', false)->assertSee('Type the code here');
    }

    public function test_the_limit_counts_per_attorney_contact_and_is_ten_an_hour(): void
    {
        $this->assertSame(10, config('attorney-portal.otp.hourly_limit'));

        $w = $this->world();
        config(['attorney-portal.otp.hourly_limit' => 2]);
        // two contacts who share one mailbox (a firm's front desk) must not use up each other's codes
        \App\Models\DealV2\AgencyServiceProviderContact::withoutGlobalScopes()->where('id', $w['b']->id)->update(['email' => 'botha@bbb.co.za']);
        [$tokenA] = $this->link($w, $w['a']);
        [$tokenB] = $this->link($w, $w['b']->fresh());
        foreach (range(1, 2) as $i) {
            RateLimiter::hit('otp.hourly:attorney_portal:contact-' . $w['a']->id, 3600);
        }

        $this->followingRedirects()->post(route('attorney.code', $tokenA))->assertSee('Too many codes were requested');
        Mail::assertNotSent(OtpMail::class);
        $this->followingRedirects()->post(route('attorney.code', $tokenB))->assertSee('We emailed a 6-digit code')->assertDontSee('Too many codes');
        Mail::assertSent(OtpMail::class, 1);
    }

    public function test_the_email_notice_sign_in_page_behaves_the_same(): void
    {
        $w = $this->world();
        config(['attorney-portal.otp.hourly_limit' => 1]);
        [, $access] = $this->link($w, $w['a']);
        $d = $this->deal($w, $this->transferTo($w, $w['a']));
        RateLimiter::hit('otp.hourly:attorney_portal:contact-' . $w['a']->id, 3600);

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('attorney.open.code', now()->addHour(), ['access' => $access->id, 'deal' => $d['deal']->id]);
        $page = $this->withSession([$this->sentKey($access) => now()->timestamp])->followingRedirects()->post($url)->assertOk();
        $page->assertSee('Too many codes were requested. Please try again in')->assertDontSee('We emailed')->assertDontSee('6-digit');
    }
}
