<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Workshop;

use App\Models\Docuperfect\NamedField;
use App\Models\Docuperfect\Template;
use App\Services\Docuperfect\Readiness\TemplateReadinessReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/esign-template-readiness.md §2 / §6 / §7 — every check, pass and fail, against a Mega-shaped template, the
 * null / double-encoded column absorb paths, and the proof that the report writes nothing.
 */
final class TemplateReadinessReportTest extends TestCase
{
    use RefreshDatabase;
    use WorkshopFixtures;

    private function report(Template $t): array
    {
        return app(TemplateReadinessReport::class)->forTemplate($t);
    }

    private function check(array $r, string $id): array
    {
        foreach ($r['checks'] as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }
        $this->fail("check {$id} missing");
    }

    public function test_a_clean_mega_shaped_template_is_ready_with_no_fails(): void
    {
        $a = $this->agency('Mega Real Estate');
        $t = $this->megaShaped($a, $this->owner());
        $r = $this->report($t);

        $this->assertSame(0, $r['counts']['fail'], json_encode(array_filter($r['checks'], fn ($c) => $c['status'] === 'fail')));
        $this->assertSame('pass', $this->check($r, 'R05')['status']);   // Lessor / Lessee / Agent resolve to owner_party / acquiring_party / agent
        $this->assertSame('pass', $this->check($r, 'R10')['status']);   // {PROPERTY} {SURNAME} {YEAR} {PAGE} are known frame tokens
        $this->assertSame('pass', $this->check($r, 'R11')['status']);
        $this->assertSame('pass', $this->check($r, 'R15')['status']);
        $this->assertContains($r['verdict'], ['ready', 'ready_with_warnings']);
        $this->assertSame(['id', 'title', 'status', 'detail', 'action', 'items'], array_keys($r['checks'][0]));
    }

    public function test_an_unlinked_blank_fails_and_is_named(): void
    {
        $a = $this->agency('Mega Real Estate');
        $t = $this->megaShaped($a, $this->owner());
        $editor = $t->editor_state;
        unset($editor['mappings']['tag-b']);
        $t->update(['editor_state' => $editor]);

        $c = $this->check($this->report($t->fresh()), 'R07');
        $this->assertSame('fail', $c['status']);
        $this->assertStringContainsString('#2', implode(' ', $c['items']));
        $this->assertSame('not_ready', $this->report($t->fresh())['verdict']);
    }

    public function test_a_blank_with_an_empty_label_counts_as_unlinked(): void
    {
        $a = $this->agency('Mega Real Estate');
        $t = $this->megaShaped($a, $this->owner());
        $editor = $t->editor_state;
        $editor['mappings']['tag-c']['label'] = '   ';
        $t->update(['editor_state' => $editor]);

        $this->assertSame('fail', $this->check($this->report($t->fresh()), 'R07')['status']);
    }

    public function test_a_place_naming_an_undeclared_signer_fails_and_a_signer_with_no_place_fails(): void
    {
        $a = $this->agency('Mega Real Estate');
        $t = $this->megaShaped($a, $this->owner());
        $editor = $t->editor_state;
        $editor['mappings']['tag-s2']['parties'] = ['Witness'];   // Lessee now has no signature place; "Witness" is not declared
        $t->update(['editor_state' => $editor]);
        $r = $this->report($t->fresh());

        $r05 = $this->check($r, 'R05');
        $this->assertSame('fail', $r05['status']);
        $this->assertStringContainsString('Witness', implode(' ', $r05['items']));
        $r04 = $this->check($r, 'R04');
        $this->assertSame('fail', $r04['status']);
        $this->assertStringContainsString('acquiring_party', implode(' ', $r04['items']));
    }

    public function test_corex_standard_signature_block_gives_every_declared_signer_a_place(): void
    {
        $a = $this->agency('Mega Real Estate');
        $t = $this->megaShaped($a, $this->owner(), [], ['signature_block' => 'corex']);
        $editor = $t->editor_state;
        unset($editor['mappings']['tag-s2']);   // the document's own Lessee place is gone, CoreX's block still covers the Lessee
        $editor['tags'] = array_values(array_filter($editor['tags'], fn ($x) => $x['id'] !== 'tag-s2'));
        $t->update(['editor_state' => $editor]);

        $this->assertSame('pass', $this->check($this->report($t->fresh()), 'R04')['status']);
    }

    public function test_header_that_looks_like_one_tenancy_warns_and_an_unknown_token_warns(): void
    {
        $a = $this->agency('Mega Real Estate');
        $specific = $this->megaShaped($a, $this->owner(), [], [], ['page_header' => ['lines' => [['text' => 'Mega Rent Lease – 50d Madeira Crescent – Arendse – 2026']]]]);
        $c = $this->check($this->report($specific), 'R10');
        $this->assertSame('warn', $c['status']);
        $this->assertStringContainsString('50d Madeira Crescent', implode(' ', $c['items']));

        $unknown = $this->megaShaped($a, $this->owner(), [], [], ['page_header' => ['lines' => [['text' => 'Lease – {TENANT_ID_NUMBER}']]]]);
        $c2 = $this->check($this->report($unknown), 'R10');
        $this->assertSame('warn', $c2['status']);
        $this->assertStringContainsString('TENANT_ID_NUMBER', implode(' ', $c2['items']));
    }

    public function test_the_importers_own_report_of_dropped_content_warns(): void
    {
        $a = $this->agency('Mega Real Estate');
        $t = $this->megaShaped($a, $this->owner(), [], [], ['parse_report' => ['line' => '2 text boxes not carried over.', 'dropped' => ['2 text boxes', '1 picture'], 'unread_chars' => 120]]);

        $c = $this->check($this->report($t), 'R11');
        $this->assertSame('warn', $c['status']);
        $this->assertSame(['2 text boxes', '1 picture'], $c['items']);
    }

    public function test_a_deleted_named_field_fails_r08(): void
    {
        $a = $this->agency('Mega Real Estate');
        $t = $this->megaShaped($a, $this->owner());
        \DB::table((new NamedField())->getTable())->where('source_column', 'address')->delete();

        $this->assertSame('fail', $this->check($this->report($t->fresh()), 'R08')['status']);
    }

    public function test_an_archived_template_fails_r16_and_a_missing_signers_list_fails_r03(): void
    {
        $a = $this->agency('Mega Real Estate');
        $t = $this->megaShaped($a, $this->owner(), ['signing_parties' => [], 'archived_at' => now()]);
        $r = $this->report($t->fresh());

        $this->assertSame('fail', $this->check($r, 'R16')['status']);
        $this->assertSame('fail', $this->check($r, 'R03')['status']);
    }

    public function test_unsafe_content_would_be_refused_by_the_package_scan(): void
    {
        $a = $this->agency('Mega Real Estate');
        $t = $this->megaShaped($a, $this->owner(), [], ['tagged_html' => '<p>Lease</p><script>alert(1)</script>']);

        $c = $this->check($this->report($t), 'R15');
        $this->assertSame('fail', $c['status']);
        $this->assertStringContainsString('a script', implode(' ', $c['items']));
    }

    public function test_missing_page_file_and_missing_document_type_warn(): void
    {
        $a = $this->agency('Mega Real Estate');
        $t = $this->megaShaped($a, $this->owner(), ['blade_view' => 'docuperfect.web-templates.cds.template-no-such', 'document_type_id' => null]);
        $r = $this->report($t->fresh());

        $this->assertSame('warn', $this->check($r, 'R02')['status']);
        $this->assertSame('warn', $this->check($r, 'R12')['status']);
    }

    public function test_letterhead_warns_when_it_prints_but_the_agency_has_no_company_detail(): void
    {
        $a = $this->agency('Bare Agency');
        $t = $this->megaShaped($a, $this->owner(), ['header_display' => 'all_pages']);
        $this->assertSame('warn', $this->check($this->report($t), 'R13')['status']);

        $none = $this->megaShaped($a, $this->owner(), ['header_display' => 'none']);
        $this->assertSame('info', $this->check($this->report($none), 'R13')['status']);
    }

    public function test_null_columns_and_double_encoded_columns_are_absorbed_never_an_exception(): void
    {
        $a = $this->agency('Mega Real Estate');
        $bare = $this->megaShaped($a, $this->owner(), ['signing_parties' => null, 'cds_json' => null, 'editor_state' => null, 'field_mappings' => null, 'fields_json' => null, 'blade_view' => null]);
        $r = $this->report($bare->fresh());
        $this->assertSame('fail', $this->check($r, 'R01')['status']);
        $this->assertNotEmpty($r['checks']);

        $dbl = $this->megaShaped($a, $this->owner());
        \DB::table('docuperfect_templates')->where('id', $dbl->id)->update([
            'editor_state' => json_encode(json_encode($dbl->editor_state)),
            'signing_parties' => json_encode(json_encode(['owner_party', 'agent', 'acquiring_party'])),
        ]);
        $r2 = $this->report(Template::find($dbl->id));
        $this->assertSame('pass', $this->check($r2, 'R05')['status'], 'double-encoded columns decode once and the checks still see the places');
        $this->assertSame(0, $r2['counts']['fail']);
    }

    public function test_a_pdf_template_is_reported_without_exception(): void
    {
        $a = $this->agency('Mega Real Estate');
        $t = $this->pdfTemplate($a, $this->owner());
        $r = $this->report($t);

        $this->assertSame('pass', $this->check($r, 'R01')['status']);
        $this->assertSame('info', $this->check($r, 'R07')['status']);
        $this->assertSame('pass', $this->check($r, 'R05')['status'], 'the PDF zones name owner_party, which is declared');
    }

    public function test_a_template_with_300_blanks_is_checked_in_one_pass(): void
    {
        $a = $this->agency('Mega Real Estate');
        $t = $this->megaShaped($a, $this->owner());
        $editor = $t->editor_state;
        for ($i = 0; $i < 300; $i++) {
            $editor['tags'][] = ['id' => "bulk-{$i}", 'type' => 'input', 'label' => '[INPUT]', 'number' => 100 + $i];
            $editor['mappings']["bulk-{$i}"] = ['label' => "Bulk {$i}", 'typeKey' => 'sf:manual', 'mappingType' => 'manual', 'namedFieldId' => null, 'fieldGroupId' => null];
        }
        $t->update(['editor_state' => $editor]);

        $queries = 0;
        \DB::listen(function () use (&$queries) {
            $queries++;
        });
        $r = $this->report($t->fresh());
        $this->assertLessThan(40, $queries, 'no per-blank queries');
        $this->assertSame('pass', $this->check($r, 'R07')['status']);
    }

    public function test_the_report_writes_nothing(): void
    {
        $a = $this->agency('Mega Real Estate');
        $t = $this->megaShaped($a, $this->owner());
        $before = $this->templateSnapshot($t);
        $counts = fn () => [\DB::table('documents')->count(), \DB::table('flows')->count(), \DB::table('template_transfer_log')->count(), \DB::table('docuperfect_templates')->count()];
        $c1 = $counts();

        $this->report($t);
        $this->report($t->fresh());

        $this->assertSame($before, $this->templateSnapshot($t));
        $this->assertSame($c1, $counts());
    }

    public function test_sorted_puts_fails_then_warns_first(): void
    {
        $checks = [
            ['id' => 'R01', 'status' => 'pass'], ['id' => 'R02', 'status' => 'warn'], ['id' => 'R03', 'status' => 'fail'], ['id' => 'R04', 'status' => 'info'],
        ];
        $this->assertSame(['R03', 'R02', 'R04', 'R01'], array_column(TemplateReadinessReport::sorted($checks), 'id'));
    }
}
