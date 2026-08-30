<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\SigningController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-387-filename (Johan 2026-08-30) — filed signed-document copies did not
 * follow the same naming rule as the in-flight document. cc1 confirmed real
 * pack output: "EXCLUSIVE AUTHORITY TO SELL (Signed).pdf" / "EXCLUSIVE
 * AUTHORITY TO SELL - VL (Signed).pdf" — bare template name only, no
 * property address, no date. The in-flight Document::name already follows
 * Johan's rule (web doc name + property address + short date), built by
 * ESignWizardController::buildDefaultDocumentName().
 *
 * Fix: SignatureService::filePackDocuments() now calls that SAME formatter
 * (widened to public, second caller) once per pack member — $isPackFlow
 * false so each member keeps its OWN template name, not the pack's
 * collective name. fileSingleDocument() already reused Document::name
 * (itself built by the same formatter at send time) and needed no change —
 * verified, not assumed.
 *
 * These tests call the private filing methods directly via Reflection
 * (they're deep in the completion pipeline; real PDF rendering is mocked
 * out via a SigningController double so the naming/DB-creation logic under
 * test runs for real without needing Chromium).
 */
final class FiledDocumentNamingTest extends TestCase
{
    use RefreshDatabase;

    private function invokePrivate(object $object, string $method, array $args)
    {
        $ref = new \ReflectionMethod($object, $method);
        $ref->setAccessible(true);
        return $ref->invoke($object, ...$args);
    }

    private function mockPdfGenerator(): void
    {
        $signerMock = \Mockery::mock(SigningController::class)->makePartial();
        $signerMock->shouldReceive('generatePdfFromHtml')->andReturnUsing(function () {
            $tmp = tempnam(sys_get_temp_dir(), 'zzzfakepdf') . '.pdf';
            file_put_contents($tmp, 'fake-pdf-bytes');
            return $tmp;
        });
        $this->app->instance(SigningController::class, $signerMock);
    }

    /**
     * One agency/branch/agent/property/document/template, ready for either
     * fileSingleDocument() or filePackDocuments() to act on.
     *
     * @return array{agent:User, property:Property, document:Document, template:SignatureTemplate}
     */
    private function fixture(array $propertyOverrides = []): array
    {
        $agencyId = (int) Agency::create(['name' => 'ZZZ Filing Test Agency ' . Str::random(6), 'slug' => 'zzz-filing-' . Str::random(8)])->id;
        $branchId = (int) Branch::create(['agency_id' => $agencyId, 'name' => 'ZZZ Filing Test Branch'])->id;
        $agent = User::factory()->create([
            'name' => 'ZZZ Filing Test Agent', 'role' => 'agent',
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'is_active' => true,
        ]);
        $property = Property::create(array_merge([
            'external_id' => (string) Str::uuid(), 'agent_id' => $agent->id, 'branch_id' => $branchId,
            'title' => 'ZZZ Filing Test Property',
        ], $propertyOverrides));

        $docTmpl = DocuperfectTemplate::create([
            'name' => 'ZZZ EXCLUSIVE AUTHORITY TO SELL', 'render_type' => 'web', 'template_type' => 'cds',
            'category' => 'sales', 'blade_view' => 'x', 'signing_parties' => ['agent', 'seller'],
            'field_mappings' => [], 'owner_id' => $agent->id, 'agency_id' => $agencyId,
        ]);
        $document = Document::create([
            // Already correctly named at send time, by the SAME formatter — the
            // invariant fileSingleDocument() relies on instead of re-deriving it.
            'name' => 'ZZZ EXCLUSIVE AUTHORITY TO SELL — ' . $property->address . ' — ' . now()->format('d/m/y'),
            'document_type' => 'mandate', 'agency_id' => $agencyId,
            'owner_id' => $agent->id, 'template_id' => $docTmpl->id, 'property_id' => $property->id,
            'web_template_data' => ['merged_html' => '<div class="corex-document-wrapper"><p>Body</p></div>'],
        ]);
        $template = SignatureTemplate::create([
            'document_id' => $document->id, 'document_hash' => Str::random(64), 'agency_id' => $agencyId,
            'status' => SignatureTemplate::STATUS_COMPLETED, 'created_by' => $agent->id,
        ]);

        return ['agent' => $agent, 'property' => $property, 'document' => $document, 'template' => $template];
    }

    public function test_single_document_filed_name_already_follows_the_naming_rule(): void
    {
        ['property' => $property, 'document' => $document, 'template' => $template] = $this->fixture([
            'address' => '20 Filing Test Road', 'erf_number' => '1234',
        ]);

        Storage::fake('local');
        Storage::disk('local')->put('zzz-filing-single.pdf', 'fake-pdf-bytes');

        $svc = app(\App\Services\Docuperfect\SignatureService::class);
        $filed = $this->invokePrivate($svc, 'fileSingleDocument', [$template, $document, 'zzz-filing-single.pdf', $property->id, []]);

        $this->assertNotNull($filed);
        $this->assertSame($document->name . ' (Signed).pdf', $filed['name']);
        $this->assertStringContainsString('20 Filing Test Road', $filed['name']);
        $this->assertStringContainsString(now()->format('d/m/y'), $filed['name']);
        $this->assertStringEndsWith('(Signed).pdf', $filed['name']);
    }

    public function test_pack_filed_documents_are_each_named_with_own_template_name_address_and_date(): void
    {
        ['agent' => $agent, 'property' => $property, 'document' => $document, 'template' => $template] = $this->fixture([
            'address' => '20 Filing Test Road', 'erf_number' => '1234',
        ]);

        $tplA = DocuperfectTemplate::create([
            'name' => 'ZZZ EXCLUSIVE AUTHORITY TO SELL', 'render_type' => 'web', 'template_type' => 'cds',
            'category' => 'sales', 'blade_view' => 'x', 'signing_parties' => ['agent', 'seller'], 'field_mappings' => [],
            'owner_id' => $agent->id, 'agency_id' => $document->agency_id,
        ]);
        $tplB = DocuperfectTemplate::create([
            'name' => 'ZZZ EXCLUSIVE AUTHORITY TO SELL - VL', 'render_type' => 'web', 'template_type' => 'cds',
            'category' => 'sales', 'blade_view' => 'x', 'signing_parties' => ['agent', 'seller'], 'field_mappings' => [],
            'owner_id' => $agent->id, 'agency_id' => $document->agency_id,
        ]);

        $mergedHtml = '<style>.x{}</style>'
            . '<div class="corex-document-wrapper"><p>Body A</p></div>'
            . '<div class="corex-document-wrapper"><p>Body B</p></div>';

        Storage::fake('local');
        $this->mockPdfGenerator();

        $svc = app(\App\Services\Docuperfect\SignatureService::class);
        $filed = $this->invokePrivate($svc, 'filePackDocuments', [
            $template, $document, [$tplA->id, $tplB->id], $mergedHtml, $property->id, [],
            ['client' => 'unused-for-pack-branch.pdf'],
        ]);

        $this->assertCount(2, $filed);
        $today = now()->format('d/m/y');

        $this->assertSame('ZZZ EXCLUSIVE AUTHORITY TO SELL — Erf 1234, 20 Filing Test Road — ' . $today . ' (Signed).pdf', $filed[0]['name']);
        $this->assertSame('ZZZ EXCLUSIVE AUTHORITY TO SELL - VL — Erf 1234, 20 Filing Test Road — ' . $today . ' (Signed).pdf', $filed[1]['name']);
        // Each member kept ITS OWN name — not collapsed into the pack's shared name.
        $this->assertNotSame($filed[0]['name'], $filed[1]['name']);
    }

    public function test_property_with_no_erf_number_falls_back_cleanly(): void
    {
        // No erf_number set at all — cc5's own formatter must skip the "Erf X, "
        // prefix entirely rather than leaving stray punctuation ("Erf , road").
        ['agent' => $agent, 'property' => $property, 'document' => $document, 'template' => $template] = $this->fixture([
            'address' => '5 Freehold Avenue',
        ]);

        $tplA = DocuperfectTemplate::create([
            'name' => 'ZZZ Freehold Naming Template', 'render_type' => 'web', 'template_type' => 'cds',
            'category' => 'sales', 'blade_view' => 'x', 'signing_parties' => ['agent', 'seller'], 'field_mappings' => [],
            'owner_id' => $agent->id, 'agency_id' => $document->agency_id,
        ]);
        $tplB = DocuperfectTemplate::create([
            'name' => 'ZZZ Freehold Naming Template B', 'render_type' => 'web', 'template_type' => 'cds',
            'category' => 'sales', 'blade_view' => 'x', 'signing_parties' => ['agent', 'seller'], 'field_mappings' => [],
            'owner_id' => $agent->id, 'agency_id' => $document->agency_id,
        ]);

        $mergedHtml = '<div class="corex-document-wrapper"><p>Body A</p></div>'
            . '<div class="corex-document-wrapper"><p>Body B</p></div>';

        Storage::fake('local');
        $this->mockPdfGenerator();

        $svc = app(\App\Services\Docuperfect\SignatureService::class);
        $filed = $this->invokePrivate($svc, 'filePackDocuments', [
            $template, $document, [$tplA->id, $tplB->id], $mergedHtml, $property->id, [],
            ['client' => 'unused-for-pack-branch.pdf'],
        ]);

        $this->assertCount(2, $filed);
        $this->assertStringContainsString('5 Freehold Avenue', $filed[0]['name']);
        $this->assertStringNotContainsString('Erf', $filed[0]['name'], 'no erf number set — no "Erf" segment at all');
        $this->assertStringNotContainsString(',,', $filed[0]['name'], 'no doubled-comma artefact from a skipped segment');
        $this->assertMatchesRegularExpression('/^ZZZ Freehold Naming Template — 5 Freehold Avenue — \d{2}\/\d{2}\/\d{2} \(Signed\)\.pdf$/', $filed[0]['name']);
    }
}
