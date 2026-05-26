<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Services\Docuperfect\InsertableBlockRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * E-sign reset Commit 4 — InsertableBlockRenderer tolerance.
 *
 * The audit Q3 found that today's regex couldn't resolve markers when
 * the agent mis-typed the inner token OR when CKEditor wrapped the
 * marker text in HTML. The fix tolerates both shapes via a fuzzy
 * Levenshtein match. These tests pin the contract:
 *
 *   • clean canonical marker still resolves
 *   • marker with embedded HTML still resolves
 *   • marker with misspelled token resolves to the closest canonical
 *   • marker with garbage doesn't accidentally collapse onto a canonical
 *   • lowercase / mixed-case markers normalise correctly
 */
final class OtherConditionsToleranceTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_canonical_marker_resolves(): void
    {
        $rendered = $this->renderMarker('~~~~OTHER_CONDITIONS~~~~');
        $this->assertStringNotContainsString('~~~~OTHER_CONDITIONS~~~~', $rendered);
        $this->assertStringContainsString('insertable-block', $rendered);
        $this->assertStringContainsString('Other Conditions', $rendered);
    }

    public function test_marker_with_embedded_html_resolves(): void
    {
        $rendered = $this->renderMarker(
            '~~~~<span class="x">OTHER_CONDITIONS</span>~~~~',
        );
        $this->assertStringContainsString('insertable-block', $rendered);
        $this->assertStringNotContainsString('~~~~', $rendered);
    }

    public function test_misspelled_marker_resolves_via_fuzzy_match(): void
    {
        // Three real misspellings seen in the wild:
        $rendered1 = $this->renderMarker('~~~~Other Contitions~~~~');
        $rendered2 = $this->renderMarker('~~~~OTHER_CONDITONS~~~~');
        $rendered3 = $this->renderMarker('~~~~other conditions~~~~');

        foreach ([$rendered1, $rendered2, $rendered3] as $r) {
            $this->assertStringContainsString('insertable-block', $r);
            $this->assertDoesNotMatchRegularExpression('/~~~~.+?~~~~/', $r);
        }
    }

    public function test_marker_with_embedded_html_AND_misspelling_resolves(): void
    {
        // The exact pathology observed in live template 111 / doc 399.
        $rendered = $this->renderMarker(
            '~~~~<span class="corex-clause-text">Other Contitions</span>~~~~',
        );
        $this->assertStringContainsString('insertable-block', $rendered);
        $this->assertStringNotContainsString('~~~~', $rendered);
    }

    public function test_garbage_marker_text_does_not_collapse_onto_canonical(): void
    {
        // Random text shouldn't accidentally become OTHER_CONDITIONS.
        $rendered = $this->renderMarker('~~~~LOREM IPSUM DOLOR~~~~');
        // Either renders as a custom block OR remains untouched, but
        // MUST NOT label itself "Other Conditions".
        $this->assertStringNotContainsString('Other Conditions', $rendered);
    }

    public function test_custom_label_marker_preserves_label_case(): void
    {
        $rendered = $this->renderMarker('~~~~CUSTOM:Special Terms of Sale~~~~');
        $this->assertStringContainsString('Special Terms of Sale', $rendered);
        $this->assertStringNotContainsString('~~~~', $rendered);
    }

    // ── Helpers ──

    /**
     * Render a single marker through the full InsertableBlockRenderer
     * pipeline and return the resulting HTML. Uses a minimal template
     * + document so the renderer has the structural context it
     * expects (template, signing token, party key).
     */
    private function renderMarker(string $markerHtml): string
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'Tester', 'email' => 't-' . Str::random(6) . '@x.test',
            'password' => bcrypt('p'), 'role' => 'agent',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tpl = DocuperfectTemplate::create([
            'name' => 'Marker test', 'render_type' => 'web',
            'template_type' => 'cds', 'category' => 'sales',
            'signing_parties' => ['owner_party'],
            'field_mappings' => [], 'owner_id' => $userId,
        ]);
        $doc = Document::create([
            'name' => 'Marker Doc', 'document_type' => 'agreement',
            'owner_id' => $userId, 'template_id' => $tpl->id,
            'web_template_data' => ['merged_html' => ''],
        ]);
        $sigTpl = SignatureTemplate::create([
            'document_id' => $doc->id,
            'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_SIGNING,
            'created_by' => $userId,
        ]);

        $html = '<p>3.7 ' . $markerHtml . '</p>';
        return app(InsertableBlockRenderer::class)->renderInDocument(
            $html,
            $sigTpl,
            [], // blocksMeta
            InsertableBlockRenderer::CONTEXT_RECIPIENT_SIGNING,
            'test-token',
            'owner_party',
        );
    }
}
