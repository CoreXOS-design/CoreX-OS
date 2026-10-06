<?php

namespace Tests\Feature\Platform\Agreement;

use App\Models\PlatformEsign\Signer;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformEsign\Agreement\AgreementRenderer;
use App\Services\PlatformEsign\Agreement\AgreementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Alignment (spec §11.21): the Agency and RR Technologies signature blocks are two equal columns with the same rows in the same order, and the Netcash
 * mandate's "label: field" lines sit on one grid — in every rendering, from the same markup and the same shared CSS. Layout only; the wording is proved separately.
 */
class AgreementAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private const MODES = ['form', 'rr', 'preview', 'wet', 'pdf'];
    private const MANDATE_ROWS = ['Given by (name of Accountholder):', 'Address:', 'Bank Name:', 'Branch Name and Town:', 'Branch Number:', 'Account Number:', 'Type of Account:', 'Date:', 'Contact Number:', 'Amount:',
        'To (Name of Beneficiary):', 'Address:', 'Abbreviated Shortname to be used:'];

    protected function tearDown(): void
    {
        Role::clearCache();
        Storage::disk('local')->deleteDirectory('platform-esign');
        parent::tearDown();
    }

    private function blocks(string $part, string $mode): array
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();
        $owner = User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'name' => 'Johan Reichel']);
        Mail::fake();
        $svc = app(AgreementService::class);
        $doc = $svc->send(['name' => 'Pat Principal', 'email' => 'pat@caprivi.test', 'take_on_month' => now()->format('Y-m')], $owner->id);

        return app(AgreementRenderer::class)->blocks($doc->wording, $part, $mode, $svc->context($doc, ['errors' => []]));
    }

    public function test_the_signature_blocks_are_two_equal_columns_with_the_same_rows_in_the_same_order_everywhere(): void
    {
        foreach (self::MODES as $mode) {
            $table = collect($this->blocks('part_a', $mode))->first(fn ($b) => str_contains($b, 'For the Agency') && str_contains($b, 'For RR Technologies'));
            $this->assertNotNull($table, $mode);
            $this->assertStringContainsString('<table class="sigtable">', $table, $mode);
            $cells = preg_split('#</td>\s*<td#', $table);
            $this->assertCount(2, $cells, "$mode: two columns");
            foreach ($cells as $i => $cell) {
                preg_match_all('#<p class="sr sr-(name|capacity|signature|date|place)">#', $cell, $m);
                $this->assertSame(['name', 'capacity', 'signature', 'date', 'place'], $m[1], "$mode column $i: the same five rows, in the same order");
            }
            // a block element inside a <p> makes the browser close the paragraph early and scatters the rows
            $this->assertDoesNotMatchRegularExpression('#<p class="sr[^"]*">(?:(?!</p>).)*<div#s', $table, $mode);
        }
    }

    public function test_the_signature_pad_is_valid_markup_inside_its_row(): void
    {
        $table = collect($this->blocks('part_a', 'form'))->first(fn ($b) => str_contains($b, 'For the Agency'));
        $this->assertStringContainsString('<span class="sigpad', $table);
        $this->assertStringNotContainsString('<div class="sigpad', $table);
    }

    public function test_the_mandate_lines_are_rows_of_one_grid_in_the_mandates_own_order(): void
    {
        foreach (self::MODES as $mode) {
            $html = implode("\n", $this->blocks('mandate', $mode));
            preg_match_all('#<p class="mf"><span class="mf-l">(.*?)</span>#s', $html, $m);
            $this->assertSame(self::MANDATE_ROWS, array_map(fn ($l) => trim(strip_tags($l)), $m[1]), "$mode: the mandate's own order");
            $this->assertSame(count(self::MANDATE_ROWS), substr_count($html, 'class="mf-v"'), $mode);
        }
    }

    public function test_the_grid_tips_sit_in_their_own_column_and_the_sentence_tips_at_the_end_of_the_sentence(): void
    {
        $html = implode("\n", $this->blocks('mandate', 'form'));
        $this->assertGreaterThanOrEqual(4, substr_count($html, 'class="mf-t"'), 'address, date, contact, amount');
        $this->assertMatchesRegularExpression('#regularly on the</p>|<p>[^<]*issued and delivered on .*?<span class="tip-end">#s', $html);
        $this->assertStringContainsString('<span class="tip-end">', $html);
        $pdf = implode("\n", $this->blocks('mandate', 'pdf'));
        $this->assertStringNotContainsString('auto-tip', $pdf);
        $this->assertStringNotContainsString('mf-t', $pdf);
    }

    public function test_screen_and_pdf_share_the_alignment_rules(): void
    {
        foreach ([false, true] as $pdf) {
            $css = view('platform-esign.agreement._css', ['pdf' => $pdf])->render();
            $this->assertStringContainsString('table.sigtable', $css);
            $this->assertStringContainsString('p.sr-signature', $css);
            $this->assertStringContainsString('p.mf', $css);
            // equal row heights in both columns come from ONE height per row class
            $this->assertSame(1, preg_match_all('#\.sigtable td p\.sr \{[^}]*height:#', $css));
        }
    }
}
