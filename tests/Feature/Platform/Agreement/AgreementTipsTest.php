<?php

namespace Tests\Feature\Platform\Agreement;

use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Signer;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformEsign\Agreement\AgreementCompany;
use App\Services\PlatformEsign\Agreement\AgreementRenderer;
use App\Services\PlatformEsign\Agreement\AgreementSample;
use App\Services\PlatformEsign\Agreement\AgreementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The little grey tips beside read-only boxes (27 Oct 2026, Johan, doc 5): the mandate Date said "Fills in automatically from the  in section 6."
 * — a sentence with its field name missing, and the wrong meaning (a date is stamped on the day of signing, it is not read from section 6).
 * Bug class: a tip that names a link must have link text and a target, or it is not rendered at all.
 */
class AgreementTipsTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Feature\Platform\Agreement\Concerns\PinsTierPricing;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Role::clearCache();
        Storage::disk('local')->deleteDirectory('platform-esign');
        parent::tearDown();
    }

    private function owner(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'name' => 'Johan Reichel']);
    }

    private function send(User $owner): Document
    {
        Mail::fake();

        return app(AgreementService::class)->send(['name' => 'Pat Principal', 'email' => 'pat@caprivi.test', 'take_on_month' => now()->format('Y-m')], $owner->id);
    }

    private function token(Document $doc): string
    {
        return Signer::where('document_id', $doc->id)->where('role_key', 'r1')->value('token');
    }

    /** Every tip on a page, as plain text with its link text kept: the broken shape is "from the" + nothing + "in". */
    private function assertNoBrokenTips(string $html, string $where): void
    {
        $this->assertDoesNotMatchRegularExpression('/from the\s+in\b/i', strip_tags($html), "$where: a tip lost its field name (\"from the  in …\")");
        $this->assertDoesNotMatchRegularExpression('/<a [^>]*data-goto="[^"]*"[^>]*>\s*<\/a>/', $html, "$where: a tip link has no text");
        $this->assertDoesNotMatchRegularExpression('/<a [^>]*data-goto=""/', $html, "$where: a tip link has no target");
        $this->assertStringNotContainsString('{link}', $html, "$where: an unfilled {link} placeholder");
        foreach (preg_split('/<span class="auto-tip/', $html) as $i => $chunk) {
            if ($i === 0) {
                continue;
            }
            $tip = trim(strip_tags(strstr($chunk, '</span>', true) ?: $chunk));
            $this->assertNotSame('', $tip, "$where: an empty tip is rendered");
        }
    }

    public function test_every_declared_tip_is_complete_and_the_generator_refuses_a_half_built_one(): void
    {
        foreach (AgreementRenderer::MIRROR_TIPS as $key => $spec) {
            if ($spec === null) {
                continue;
            }
            $html = AgreementRenderer::tipHtml($spec);
            $this->assertNotSame('', $html, "$key renders");
            $this->assertNotSame('', AgreementRenderer::mirrorTipText($key), "$key has plain text");
            $this->assertStringNotContainsString('{link}', $html, $key);
            $this->assertDoesNotMatchRegularExpression('/from the\s+in\b/', strip_tags($html), $key);
        }
        // a sentence that names a link but has no link text or no target is dropped, never printed half-built
        $this->assertSame('', AgreementRenderer::tipHtml(['Fills in automatically from the {link} in section 6.', '', 'fld-sig_date']));
        $this->assertSame('', AgreementRenderer::tipHtml(['Fills in automatically from the {link} in section 6.', null, null]));
        $this->assertSame('', AgreementRenderer::tipHtml(['Fills in automatically from the {link} in section 6.', 'date', '']));
        $this->assertSame('', AgreementRenderer::tipHtml(['   ', null, null]));
        $this->assertStringContainsString('<a href="#fld-x" data-goto="fld-x">the box</a>', AgreementRenderer::tipHtml(['From {link}.', 'the box', 'fld-x']));
    }

    public function test_a_stamped_date_says_it_is_filled_in_on_the_day_you_sign_not_from_section_six(): void
    {
        $this->assertSame('Filled in on the day you sign.', AgreementRenderer::mirrorTipText('m_date'));
        $doc = $this->send($this->owner());
        $html = $this->get(route('platform-esign.agreement.show', $this->token($doc)))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'Filled in on the day you sign.'), 'Part A date + mandate Date');
        $this->assertStringNotContainsString('data-goto="fld-sig_date"', $html, 'nothing links to the (now read-only) date box');
        // the mandate Date's own tip, right after its box
        $this->assertMatchesRegularExpression('/data-mirror-of="sig_date"[^>]*>.{0,80}?<span class="auto-tip" data-screen-only="1">Filled in on the day you sign\.<\/span>/s', $html);
    }

    public function test_no_tip_is_broken_on_the_sign_page_the_owner_review_or_the_countersign_page(): void
    {
        Carbon::setTestNow('2026-10-14 09:00:00');
        $owner = $this->owner();
        $doc = $this->send($owner);
        $token = $this->token($doc);

        $this->assertNoBrokenTips($this->get(route('platform-esign.agreement.show', $token))->assertOk()->getContent(), 'sign page');
        $this->assertNoBrokenTips($this->actingAs($owner)->get(route('platform-esign.agreements.review', $doc->id))->assertOk()->getContent(), 'owner review');

        // agency signs, then the countersign page
        $this->postJson(route('platform-esign.agreement.initials', $token), ['initials' => 'pp'])->assertOk();
        foreach (range(1, app(AgreementService::class)->totalPages($doc)) as $p) {
            $this->postJson(route('platform-esign.agreement.initial-page', [$token, $p]))->assertOk();
        }
        $values = AgreementSample::ctx($doc->wording)['values'] + ['sigA' => self::PNG, 'sigM' => self::PNG];
        $this->postJson(route('platform-esign.agreement.submit', $token), ['values' => $values, 'id_number' => '8001015009087', 'consent' => 1])->assertOk();

        $html = $this->actingAs($owner)->get(route('platform-esign.agreements.countersign', $doc->id))->assertOk()->getContent();
        $this->assertNoBrokenTips($html, 'countersign page');
        $this->assertStringContainsString('Filled in on the day ' . AgreementCompany::for($doc->fresh())->legalName() . ' countersigns.', $html, 'the countersign date says who countersigns');
    }

    public function test_the_pdfs_and_the_signed_record_carry_no_tips_at_all(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner);
        $ctx = app(AgreementService::class)->context($doc, ['errors' => [], 'locked' => false]);
        foreach (['wet', 'pdf', 'preview', 'text', 'canon'] as $mode) {
            foreach (array_keys(\App\Services\PlatformEsign\Agreement\AgreementContent::PARTS) as $part) {
                $out = implode('', app(AgreementRenderer::class)->blocks($doc->wording, $part, $mode, $ctx));
                $this->assertStringNotContainsString('auto-tip', $out, "$mode/$part");
            }
        }
        // the countersign form carries exactly one tip: on the countersign date
        $rr = '';
        foreach (array_keys(\App\Services\PlatformEsign\Agreement\AgreementContent::PARTS) as $part) {
            $rr .= implode('', app(AgreementRenderer::class)->blocks($doc->wording, $part, 'rr', $ctx));
        }
        $this->assertSame(1, substr_count($rr, 'class="auto-tip'));
    }
}
