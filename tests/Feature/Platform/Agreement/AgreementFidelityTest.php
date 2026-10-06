<?php

namespace Tests\Feature\Platform\Agreement;

use App\Models\PlatformEsign\WordingVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformEsign\Agreement\AgreementContent;
use App\Services\PlatformEsign\Agreement\AgreementFidelity;
use App\Services\PlatformEsign\Agreement\AgreementRenderer;
use App\Services\PlatformEsign\Agreement\AgreementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Word-for-word proof of what the client sees and signs (spec §11.15): the recipient page, the wet-ink download PDF and the
 * sealed PDF, each compared with the two signed-off source files as ONE continuous word sequence. The browser run itself is
 * scripts/verify-agreement-wording.sh (real Chromium); here the recipient page text is taken from the server's own form render.
 */
class AgreementFidelityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        Storage::disk('local')->deleteDirectory('platform-esign');
        parent::tearDown();
    }

    private function source(): array
    {
        return [
            (string) file_get_contents(AgreementContent::sourcePath('agreement-v1.0.md')),
            (string) file_get_contents(AgreementContent::sourcePath('netcash-mandate-v1.0.md')),
        ];
    }

    // ── the comparison itself must catch every kind of change ──────────────

    private function diff(string $source, string $rendered): array
    {
        return AgreementFidelity::compare(AgreementFidelity::fromSource($source), AgreementFidelity::fromRendered($rendered))['differences'];
    }

    public function test_identical_text_has_no_differences_whatever_the_markup_and_wrapping(): void
    {
        $src = "# **Part B**\n\n| **Question**| **Answer**|\n|---|---|\n| Who owns it?| Your agency (Clause **B6**)|\n\nWe sign on the 1<sup>st</sup> of the month — see [www.example.co.za](http://www.example.co.za).";
        $this->assertSame([], $this->diff($src, "Part B  Question Answer\nWho owns it?   Your agency (Clause B6)\nWe sign on the 1 st of the\nmonth — see www.example.co.za."));
    }

    public function test_a_changed_missing_extra_or_reordered_word_is_a_difference(): void
    {
        $src = 'The fee is R295 a seat and notice is 30 days written notice.';
        $this->assertCount(1, $this->diff($src, 'The fee is R250 a seat and notice is 30 days written notice.'), 'changed rate');
        $this->assertCount(1, $this->diff($src, 'The fee is R295 a seat and notice is 30 days notice.'), 'missing word');
        $this->assertCount(1, $this->diff($src, 'The fee is R295 a seat and notice is 30 working days written notice.'), 'extra word');
        $this->assertNotEmpty($this->diff($src, 'The fee is R295 a seat and notice is written 30 days notice.'), 'reordered');
        $this->assertNotEmpty($this->diff('Clause B12.4 applies.', 'Clause B12.5 applies.'), 'wrong clause number');
        $this->assertNotEmpty($this->diff('Clause B12.4 applies.', 'Clause B12.4 applies. Extra paragraph here.'), 'extra tail');
        $this->assertNotEmpty($this->diff('Clause B12.4 applies. Final sentence.', 'Clause B12.4 applies.'), 'missing tail');
    }

    public function test_a_blank_may_be_filled_by_a_value_but_the_words_around_it_must_still_match(): void
    {
        $src = 'Name: ______ Capacity: ______ of the agency.';
        $r = AgreementFidelity::compare(AgreementFidelity::fromSource($src), AgreementFidelity::fromRendered('Name: Pat Principal Capacity: Director of the agency.'));
        $this->assertSame([], $r['differences']);
        $this->assertSame(['Pat Principal', 'Director'], array_column($r['fills'], 'text'));
        $this->assertNotEmpty($this->diff($src, 'Name: Pat Principal Capacity: Director of the company.'));
        $this->assertSame([], $this->diff($src, 'Name: Capacity: of the agency.'), 'a blank left empty (a form field) is allowed');
    }

    public function test_an_empty_table_cell_is_a_blank_and_an_escaped_pipe_is_text(): void
    {
        $this->assertSame([], $this->diff("| **Registered name**||\n| **Trading name**||", 'Registered name Trading name'));
        $this->assertSame([], $this->diff('| **Mail**| <a@b.co> \| (039) 004 0125|', 'Mail a@b.co | (039) 004 0125'));
        $this->assertNotEmpty($this->diff('| **Mail**| <a@b.co> \| (039) 004 0125|', 'Mail a@b.co (039) 004 0125'));
    }

    // ── the real wording ───────────────────────────────────────────────────

    public function test_a_fresh_database_holds_only_version_1_0_and_it_is_the_source_word_for_word(): void
    {
        $this->assertSame(0, WordingVersion::query()->count(), 'migrate alone seeds no wording version');
        $v = app(AgreementContent::class)->ensureSeeded();
        $this->assertSame(1, WordingVersion::withTrashed()->count());
        $this->assertSame('1.0', $v->version);
        $this->assertSame('Version 1.0 — 28 September 2026', $v->label());

        // stored content is exactly what the source files produce …
        $this->assertSame((new AgreementContent)->buildV1(), array_map('strval', (array) $v->content_json));

        // … and, read as plain words (a token stands for the blank it replaced; rates are the version's own), it IS the source.
        [$agr, $man] = $this->source();
        $rates = $v->rates_json;
        $strip = fn (string $md) => preg_replace('/\{\{[^}]*\}\}/', ' ', preg_replace_callback('/\{\{rate:([a-z0-9_]+)\}\}/', fn ($m) => \App\Services\PlatformEsign\Agreement\AgreementPricing::rate((float) $rates[$m[1]]), $md));
        $plain = fn (array $t) => array_values(array_filter($t, fn ($x) => $x !== AgreementFidelity::BLANK && $x !== AgreementFidelity::BOX));
        $parts = array_diff_key((array) $v->content_json, ['mandate' => 1]);
        $stored = array_merge(AgreementFidelity::fromSource($strip(implode("\n\n", $parts))), AgreementFidelity::fromSource($strip((string) $v->content_json['mandate'])));
        $r = AgreementFidelity::compare($plain(AgreementFidelity::sourceTokens($agr, $man)), $plain($stored));
        $diffs = array_values(array_filter($r['differences'], fn ($d) => !preg_match('/^Contract reference number:$/', trim($d['actual']))));
        $this->assertSame([], $diffs, json_encode($diffs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->assertGreaterThan(5000, $r['matched'], 'the whole of the two documents was compared, not a sample');
    }

    public function test_the_recipient_page_the_wet_ink_pdf_and_the_sealed_pdf_match_the_source_with_zero_differences(): void
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();
        $owner = User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'name' => 'Johan Reichel']);

        Mail::fake();
        $svc = app(AgreementService::class);
        $doc = $svc->send(['name' => 'Pat Principal', 'email' => 'pat@caprivi.test', 'cell' => '+27 82 555 0123'], $owner->id);
        $this->assertSame('1.0', $doc->wording->version);

        // The recipient page as the server renders it for the browser (fields carry no text; the browser run reads the same).
        $ctx = $svc->context($doc, ['errors' => [], 'locked' => false]);
        $html = '';
        foreach (array_keys(AgreementContent::PARTS) as $part) {
            $html .= implode("\n", app(AgreementRenderer::class)->blocks($doc->wording, $part, 'form', $ctx)) . "\n";
        }
        $html = preg_replace('#<(select|button|textarea|script|style)\b.*?</\1>#is', ' ', $html);
        $html = preg_replace('#</?(?:p|td|th|tr|table|thead|tbody|br|ul|ol|li|div|h[1-6]|label)\b[^>]*>#i', ' ', $html);
        $file = tempnam(sys_get_temp_dir(), 'agrweb');
        file_put_contents($file, html_entity_decode(strip_tags($html)));

        $exit = \Illuminate\Support\Facades\Artisan::call('platform-esign:verify-wording', ['--doc' => $doc->id, '--web-text' => $file, '--seal' => true, '--cleanup' => true]);
        $out = \Illuminate\Support\Facades\Artisan::output();
        @unlink($file);

        $this->assertStringContainsString('web: 0 differences', $out, $out);
        $this->assertStringContainsString('wet-ink PDF: 0 differences', $out, $out);
        $this->assertStringContainsString('sealed PDF: 0 differences', $out, $out);
        // the screen-only tips are a declared addition of the web page ONLY — never in a PDF
        $web = substr($out, strpos($out, 'RECIPIENT WEB PAGE'), strpos($out, 'WET-INK DOWNLOAD PDF') - strpos($out, 'RECIPIENT WEB PAGE'));
        $this->assertStringContainsString('3 × "Fills in automatically', $web, 'the three tips are reported as declared additions of the web page');
        $this->assertSame(1, substr_count($out, 'Fills in automatically'), 'and appear nowhere else (wet-ink PDF, sealed PDF)');
        $this->assertStringContainsString('Version 1.0 — 28 September 2026', $out, 'the footer of the PDFs reads the v1.0 label');
        $this->assertSame(0, $exit, $out);
    }
}
