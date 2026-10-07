<?php

namespace Tests\Feature\Platform;

use App\Models\Platform\PlatformCompany;
use App\Support\Platform\SafeHtml;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Platform Company Profile — the interface other lanes call. Spec: .ai/specs/platform-company-profile.md §4, §9.
 */
class PlatformCompanyInterfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_returns_the_seeded_record_and_there_is_exactly_one(): void
    {
        $c = PlatformCompany::current();

        $this->assertSame('RR Technologies (Pty) Ltd', $c->legal_name);
        $this->assertSame('CoreX OS', $c->trading_name);
        $this->assertSame('2026 / 444132 / 07', $c->registration_number);
        $this->assertFalse($c->vat_registered);
        $this->assertSame(['Johan Reichel', 'Andre Roets'], $c->directorNames());
        $this->assertStringContainsString('Southbroom', $c->physical_address);
        $this->assertSame('admin@corexos.co.za', $c->email_general);
        $this->assertSame('support@corexos.co.za', $c->email_support);
        $this->assertCount(3, $c->phoneList());
        $this->assertSame(['www.corexweb.co.za', 'www.corexos.co.za'], $c->websiteList());
        $this->assertSame(1, PlatformCompany::query()->count());
    }

    public function test_a_second_row_is_refused_by_the_database(): void
    {
        $this->expectException(QueryException::class);
        DB::table('platform_company')->insert(['singleton' => 1, 'legal_name' => 'X', 'trading_name' => 'X', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_current_self_heals_if_the_row_is_missing(): void
    {
        DB::table('platform_company')->delete();

        $this->assertSame('RR Technologies (Pty) Ltd', PlatformCompany::current()->legal_name);
        $this->assertSame(1, PlatformCompany::query()->count());
    }

    public function test_letterhead_renders_for_web_and_pdf_without_blank_labels(): void
    {
        $c = PlatformCompany::current();

        $web = $c->letterheadHtml('web');
        $pdf = $c->letterheadHtml(context: 'pdf');

        foreach ([$web, $pdf] as $html) {
            $this->assertStringContainsString('CoreX OS', $html);
            $this->assertStringContainsString('RR Technologies (Pty) Ltd', $html);
            $this->assertStringContainsString('2026 / 444132 / 07', $html);
            $this->assertStringContainsString('076 423 2426', $html);
            $this->assertStringContainsString('admin@corexos.co.za', $html);
            $this->assertStringContainsString('www.corexos.co.za', $html);
            $this->assertStringNotContainsString('VAT no', $html, 'not VAT registered: no VAT line');
            $this->assertStringNotContainsString('Accounts:', $html, 'optional empty value must be omitted, not printed blank');
        }
        $this->assertStringContainsString(route('platform-company.logo', ['l' => 0]), $web);
        $this->assertStringContainsString('src="data:image/svg+xml;base64,', $pdf, 'pdf embeds the logo — no network fetch');
        $this->assertSame($web, $c->letterheadHtml(), "default context is 'web'");
    }

    public function test_vat_line_and_strap_line_appear_only_when_set(): void
    {
        $c = PlatformCompany::current();
        $c->update(['vat_registered' => true, 'vat_number' => '4123456789', 'strap_line' => 'Property, simplified']);

        $html = $c->fresh()->letterheadHtml('web');
        $this->assertStringContainsString('VAT no 4123456789', $html);
        $this->assertStringContainsString('Property, simplified', $html);
        $this->assertStringContainsString('VAT no 4123456789', $c->fresh()->letterheadFooterHtml('pdf'));
    }

    public function test_footer_is_generated_when_none_saved_and_saved_text_wins(): void
    {
        $c = PlatformCompany::current();
        $this->assertStringContainsString('Directors: Johan Reichel, Andre Roets', $c->letterheadFooterHtml());

        $c->update(['letterhead_footer' => "Custom <b>footer</b>\nline two"]);
        $html = $c->fresh()->letterheadFooterHtml();
        $this->assertStringContainsString('Custom &lt;b&gt;footer&lt;/b&gt;<br />', $html, 'footer text is escaped');
    }

    public function test_default_email_signature_is_generated_and_saved_signature_is_sanitised(): void
    {
        $c = PlatformCompany::current();
        $sig = $c->emailSignatureHtml();
        $this->assertStringContainsString('CoreX OS', $sig);
        $this->assertStringContainsString('support@corexos.co.za', $sig);

        $c->update(['email_signature_html' => '<p onclick="x()">Regards <script>alert(1)</script><a href="javascript:alert(1)">bad</a> <a href="https://corexos.co.za">ok</a></p>']);
        $clean = $c->fresh()->emailSignatureHtml();
        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringContainsString('href="https://corexos.co.za"', $clean);
    }

    public function test_safe_html_strips_dangerous_markup(): void
    {
        $out = SafeHtml::clean('<div style="background:url(http://x/y)"><img src="javascript:alert(1)" onerror="x()"><img src="https://a/b.png"><iframe src="x"></iframe><form><input></form><svg><script>1</script></svg><!-- c --><b>keep</b></div>');
        $this->assertStringNotContainsString('url(', $out);
        $this->assertStringNotContainsString('javascript', $out);
        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringNotContainsString('iframe', $out);
        $this->assertStringNotContainsString('<form', $out);
        $this->assertStringNotContainsString('<svg', $out);
        $this->assertStringNotContainsString('a/b.png', $out, 'a remote image is a tracking pixel: only the CoreX logo route or a small inline image survives (audit F2/E4)');
        $this->assertStringContainsString('<b>keep</b>', $out);
        $this->assertSame('', SafeHtml::clean("  \n "));
    }

    public function test_svg_logo_check_rejects_active_content(): void
    {
        $this->assertNull(SafeHtml::svgProblem('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>'));
        $this->assertNotNull(SafeHtml::svgProblem('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'));
        $this->assertNotNull(SafeHtml::svgProblem('<svg xmlns="http://www.w3.org/2000/svg" onload="x()"></svg>'));
        $this->assertNotNull(SafeHtml::svgProblem('<svg xmlns="http://www.w3.org/2000/svg"><image href="http://evil/x.png"/></svg>'));
        $this->assertNotNull(SafeHtml::svgProblem('<!DOCTYPE svg [<!ENTITY a "b">]><svg xmlns="http://www.w3.org/2000/svg"/>'));
        $this->assertNotNull(SafeHtml::svgProblem('not svg at all'));
    }

    public function test_logo_route_is_public_and_streams_the_built_in_logo_safely(): void
    {
        $res = $this->get(route('platform-company.logo'));

        $res->assertOk();
        $res->assertHeader('Content-Type', 'image/svg+xml');
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("default-src 'none'", $res->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('<svg', $res->getContent());
    }
}
