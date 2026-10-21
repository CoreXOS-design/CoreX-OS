<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\Template;

/**
 * HOTFIX 2026-10-21 (Johan, QA1) — the download-and-sign and wet-ink routes.
 *
 *  1. A Web Pack on a paper route printed only its FIRST document. It must be one Document per pack member (pack
 *     order) and ONE combined print of the whole pack.
 *  2. What the agent typed in Fill & Review (District…) never reached the paper copy — the electronic route applied
 *     it, the paper routes did not.
 *  3. A generated web-template blade is an untracked per-host file; the paper routes must regenerate it, not 500.
 */
final class PaperRoutePackAndPropertyFieldsTest extends PaperRouteFixtureCase
{
    // ---- 1. packs ---------------------------------------------------------------------------------------------

    public function test_download_pack_makes_one_document_per_template_in_pack_order(): void
    {
        $flow = $this->flow($this->templates);
        $this->runRoute('docuperfect.esign.prepareDownload', $flow);

        $docs = $this->packDocuments($flow);
        $this->assertCount(3, $docs, 'one Document per pack member');
        $this->assertSame(array_map(fn (Template $t) => $t->id, $this->templates), $docs->pluck('template_id')->all());
        $this->assertSame(1, $docs->pluck('pack_instance_id')->unique()->count(), 'one pack instance');
        foreach ($docs as $i => $d) {
            $this->assertStringContainsString('PACKDOC' . ($i + 1) . ' TITLE', $this->printed($d));
            $this->assertStringNotContainsString('PACKDOC' . (($i + 1) % 3 + 1) . ' TITLE', $this->printed($d), 'each document holds only its own body');
            $this->assertStringContainsString('Pack Doc ' . ($i + 1), $d->name);
        }
    }

    public function test_download_pack_prints_one_combined_document_in_pack_order(): void
    {
        $flow = $this->flow($this->templates);
        $this->runRoute('docuperfect.esign.prepareDownload', $flow);
        $first = $this->packDocuments($flow)->first();

        $html = $this->actingAs($this->agent)->get(route('docuperfect.esign.downloadDocument', $first->id))->assertOk()->getContent();
        $p1 = strpos($html, 'PACKDOC1 TITLE');
        $p2 = strpos($html, 'PACKDOC2 TITLE');
        $p3 = strpos($html, 'PACKDOC3 TITLE');
        $this->assertNotFalse($p1);
        $this->assertTrue($p1 < $p2 && $p2 < $p3, 'whole pack, in pack order');
        $this->assertSame(2, substr_count($html, 'page-break-after:always'), 'each document on its own page');
    }

    public function test_wet_ink_pack_makes_one_document_per_template_and_one_envelope(): void
    {
        $flow = $this->flow($this->templates);
        $this->runRoute('docuperfect.esign.prepareWetInk', $flow);

        $docs = $this->packDocuments($flow);
        $this->assertCount(3, $docs);
        $this->assertSame(array_map(fn (Template $t) => $t->id, $this->templates), $docs->pluck('template_id')->all());
        $this->assertNotNull($docs[0]->signatureTemplate, 'the first document carries the pack\'s wet-ink envelope');
        $this->assertNull($docs[1]->signatureTemplate);
        foreach ($docs as $i => $d) {
            $this->assertStringContainsString('PACKDOC' . ($i + 1) . ' TITLE', $this->printed($d));
        }

        $html = $this->actingAs($this->agent)->get(route('docuperfect.esign.wetInkConfirmation', $flow->id))->assertOk()->getContent();
        $this->assertTrue(strpos($html, 'PACKDOC1 TITLE') < strpos($html, 'PACKDOC2 TITLE'));
        $this->assertNotFalse(strpos($html, 'PACKDOC3 TITLE'));
    }

    public function test_stand_alone_document_is_unchanged_one_document_no_pack(): void
    {
        $flow = $this->flow([$this->templates[1]], 1, false);
        $this->runRoute('docuperfect.esign.prepareDownload', $flow);

        $docs = $this->packDocuments($flow);
        $this->assertCount(1, $docs);
        $this->assertNull($docs[0]->pack_instance_id);
        $this->assertArrayNotHasKey('paper_pack', $docs[0]->web_template_data);
        $this->assertStringContainsString('PACKDOC2 TITLE', $this->printed($docs[0]));
    }

    public function test_three_sellers_each_get_a_domicilium_block_in_a_paper_pack(): void
    {
        foreach (['docuperfect.esign.prepareDownload', 'docuperfect.esign.prepareWetInk'] as $route) {
            $flow = $this->flow($this->templates, 3);
            $this->runRoute($route, $flow);
            $printed = $this->printed($this->packDocuments($flow)->first());
            $this->assertSame(3, substr_count($printed, 'data-field="seller_address'), "{$route}: 3 sellers → 3 address blocks");
        }
    }

    public function test_district_typed_in_fill_and_review_lands_on_both_paper_routes_single_and_pack(): void
    {
        foreach ([false, true] as $pack) {
            foreach (['docuperfect.esign.prepareDownload', 'docuperfect.esign.prepareWetInk'] as $route) {
                $tpls = $pack ? $this->templates : [$this->templates[0]];
                $flow = $this->flow($tpls, 2, $pack, ['district' => 'Typed District XYZ']);
                $this->runRoute($route, $flow);
                foreach ($this->packDocuments($flow) as $d) {
                    $p = $this->printed($d);
                    $this->assertStringContainsString('Typed District XYZ', $p, ($pack ? 'pack' : 'single') . " / {$route}: typed District wins");
                    $this->assertStringNotContainsString('Ray Nkonyeni', $p, 'a typed value is never overwritten by the record');
                }
            }
        }
    }

    // ---- 3. generated blade regenerated when missing ----------------------------------------------------------

    public function test_missing_generated_blade_is_regenerated_instead_of_failing_the_paper_route(): void
    {
        foreach ($this->templates as $t) {
            $this->assertFileDoesNotExist(resource_path('views/docuperfect/web-templates/cds/template-' . $t->id . '.blade.php'));
        }
        $flow = $this->flow($this->templates);
        $this->runRoute('docuperfect.esign.prepareDownload', $flow);

        $this->assertCount(3, $this->packDocuments($flow));
        $this->assertFileExists(resource_path('views/docuperfect/web-templates/cds/template-' . $this->templates[0]->id . '.blade.php'));
    }
}
