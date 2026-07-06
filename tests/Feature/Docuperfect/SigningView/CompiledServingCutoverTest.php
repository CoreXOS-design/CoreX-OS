<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Services\Docuperfect\SignatureService;
use Database\Seeders\DataDictionarySeeder;
use Database\Seeders\ReferencePackDictionarySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * AT-177 / WS6 — the CUTOVER proof, on the REAL signing pipeline (pipeline gate for
 * SigningController.php). A compiled-serving template serves its document from the published
 * compiled CDS via the render-only runtime — bypassing the entire legacy merged_html +
 * compensator chain — while a non-cutover template still serves via the untouched legacy path
 * (dual-path coexistence, §9).
 */
final class CompiledServingCutoverTest extends TestCase
{
    use BuildsSigningSession;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DataDictionarySeeder::class);
        $this->seed(ReferencePackDictionarySeeder::class);
        // Publish the reference pack (116/117/119) as immutable compiled_templates versions.
        $this->artisan('esign:publish-reference-pack')->assertSuccessful();
    }

    /**
     * Build a real signing session for a template CUT OVER to compiled serving.
     *
     * @param list<array{role:string,index:int}> $recipients
     * @return array{template:DocuperfectTemplate,recipients:\Illuminate\Support\Collection}
     */
    private function buildCompiledSession(string $family, array $recipients): array
    {
        $creator = \App\Models\User::factory()->create(['role' => 'agent']);

        $template = DocuperfectTemplate::create([
            'name' => "Compiled {$family} (cutover)",
            'render_type' => 'web',
            'blade_view' => "docuperfect.web-templates.cds.template-{$family}",
            'template_type' => 'cds',
            'category' => 'sales',
            'signing_parties' => array_values(array_unique(array_map(fn ($r) => $r['role'], $recipients))),
            'owner_id' => $creator->id,
        ]);
        // Flip the cutover switch (direct assignment — the flag is not mass-assignable).
        $template->compiled_serving = true;
        $template->compiled_family = $family;
        $template->save();

        $document = Document::create([
            'name' => "Compiled {$family} Doc",
            'document_type' => 'agreement',
            'owner_id' => $creator->id,
            'template_id' => $template->id,
            'web_template_data' => [], // no merged_html — the compiled path never reads it
        ]);

        $signatureTemplate = SignatureTemplate::create([
            'document_id' => $document->id,
            'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_SIGNING,
            'created_by' => $creator->id,
        ]);

        /** @var SignatureService $svc */
        $svc = app(SignatureService::class);
        $built = collect();
        foreach ($recipients as $r) {
            $built->push($svc->createSigningRequest(
                template: $signatureTemplate,
                partyRole: $r['role'],
                signerName: ucfirst($r['role']) . ' ' . $r['index'],
                signerEmail: $r['role'] . $r['index'] . '-' . Str::random(4) . '@x.test',
                roleIndex: $r['index'],
            ));
        }
        SignatureRequest::where('signature_template_id', $signatureTemplate->id)
            ->update(['status' => SignatureRequest::STATUS_PENDING, 'sent_at' => now()]);
        $built->each(fn (SignatureRequest $x) => $x->refresh());

        return ['template' => $template, 'recipients' => $built];
    }

    public function test_cutover_template_serves_from_the_compiled_cds_not_the_legacy_chain(): void
    {
        $session = $this->buildCompiledSession('117', [
            ['role' => 'seller', 'index' => 1],
            ['role' => 'agent', 'index' => 1],
        ]);
        $seller = $session['recipients']->first(fn (SignatureRequest $r) => $r->party_role === 'seller');

        $body = $this->extractRenderedDocumentHtml($this->asRecipient($seller));

        // Served from the compiled CDS: real 117 legal prose + compiled signable surfaces.
        $this->assertStringContainsString('IMMOVABLE PROPERTY CONDITION REPORT', $body);
        $this->assertStringContainsString('data-marker-party="seller"', $body);
        $this->assertStringContainsString('data-marker-type="signature"', $body);
        // Both signers' surfaces render (the signer sees the whole document).
        $this->assertStringContainsString('data-marker-party="agent"', $body);

        // Compensator ARTIFACTS are structurally absent (the compiled path runs NONE of them).
        $this->assertStringNotContainsString('~~~~', $body);
        $this->assertStringNotContainsString('data-role-block', $body);
    }

    public function test_non_cutover_template_still_serves_via_the_untouched_legacy_path(): void
    {
        // The canonical template-111 session is NOT cut over (no compiled_serving) → legacy path.
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 2, includeAgent: true);
        $seller = $this->recipient($session['recipients'], 'seller', 1);

        $body = $this->extractRenderedDocumentHtml($this->asRecipient($seller));

        // Legacy fixture content served, and the LEGACY RoleBlockExpansionService ran (it stamps
        // data-viewer-editable) — i.e. the legacy chain is fully intact for non-cutover templates.
        $this->assertNotSame('', $body, 'legacy signing view must still render');
        $this->assertStringContainsString('data-viewer-editable', $body);
    }

    public function test_resolver_is_dual_path_and_reversible(): void
    {
        $resolver = new \App\Services\Docuperfect\Compiler\Serving\CompiledServingResolver();

        $session = $this->buildCompiledSession('119', [['role' => 'seller', 'index' => 1], ['role' => 'agent', 'index' => 1]]);
        $template = $session['template'];

        $this->assertTrue($resolver->isCompiledServing($template));
        $this->assertNotNull($resolver->resolve($template));

        // Reverting the flag (reversible cutover) → immediately back to the legacy path.
        $template->compiled_serving = false;
        $template->save();
        $this->assertFalse($resolver->isCompiledServing($template->fresh()));
        $this->assertNull($resolver->resolve($template->fresh()));
    }
}
