<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\SignatureController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\User;
use App\Services\Docuperfect\SignaturePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * AT-387-filename-slash (Johan 2026-08-30) — cc3 found that a document
 * name containing "/" (the pack/mandate naming rule's d/m/y date) 500'd
 * both SignatureController::download() and ::downloadCertificate():
 * Symfony's HeaderUtils::makeDisposition() throws InvalidArgumentException
 * for any Content-Disposition filename containing "/" or "\". Fixed at the
 * SOURCE (ESignWizardController::buildDefaultDocumentName() now emits
 * d-m-y), but Document::name is a free-text field agents can rename to
 * anything (Johan's own words) — these tests prove the two download routes
 * themselves are now hardened against ANY slash/backslash in the name,
 * regardless of where it came from.
 */
final class DownloadFilenameSlashSafetyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{agent:User, document:Document, template:SignatureTemplate} */
    private function completedDocument(string $documentName): array
    {
        $agencyId = (int) Agency::create(['name' => 'ZZZ Download Safety Agency ' . Str::random(6), 'slug' => 'zzz-dl-safety-' . Str::random(8)])->id;
        $branchId = (int) Branch::create(['agency_id' => $agencyId, 'name' => 'ZZZ Download Safety Branch'])->id;
        $agent = User::factory()->create([
            'name' => 'ZZZ Download Safety Agent', 'role' => 'agent',
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'is_active' => true,
        ]);
        $docTmpl = DocuperfectTemplate::create([
            'name' => 'ZZZ Download Safety Template', 'render_type' => 'web', 'template_type' => 'cds',
            'category' => 'sales', 'blade_view' => 'x', 'signing_parties' => ['agent', 'seller'],
            'field_mappings' => [], 'owner_id' => $agent->id, 'agency_id' => $agencyId,
        ]);
        $document = Document::create([
            'name' => $documentName, 'document_type' => 'mandate', 'agency_id' => $agencyId, 'branch_id' => $branchId,
            'owner_id' => $agent->id, 'template_id' => $docTmpl->id,
            'web_template_data' => ['merged_html' => '<div class="corex-document-wrapper"><p>Body</p></div>'],
        ]);
        $template = SignatureTemplate::create([
            'document_id' => $document->id, 'document_hash' => Str::random(64), 'agency_id' => $agencyId,
            'status' => SignatureTemplate::STATUS_COMPLETED, 'created_by' => $agent->id,
        ]);

        return ['agent' => $agent, 'document' => $document, 'template' => $template];
    }

    public function test_download_survives_a_slash_in_the_document_name(): void
    {
        // Simulates BOTH the exact regression (a d/m/y-dated pack name) and
        // the general case (an agent free-typed a "/" into a rename).
        ['agent' => $agent, 'document' => $document, 'template' => $template] = $this->completedDocument(
            'EXCLUSIVE AUTHORITY TO SELL — 20 E2E Test Road — 30/08/26'
        );

        Storage::fake('local');
        Storage::disk('local')->put('zzz-download-safety.pdf', 'fake-pdf-bytes');
        $template->update(['signed_pdf_client_path' => 'zzz-download-safety.pdf']);

        $request = \Illuminate\Http\Request::create('/docuperfect/documents/' . $document->id . '/signatures/download', 'GET');
        $request->setUserResolver(fn () => $agent);

        $response = app(SignatureController::class)->download($request, $document);

        $this->assertInstanceOf(BinaryFileResponse::class, $response, 'must return a real file download, not a 500/error redirect');
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertNotNull($disposition);
        $this->assertStringNotContainsString('/', $disposition, 'no slash from the document name may reach the Content-Disposition header');
        $this->assertStringContainsString('Signed - ', $disposition);
    }

    public function test_download_certificate_survives_a_slash_in_the_document_name(): void
    {
        ['agent' => $agent, 'document' => $document, 'template' => $template] = $this->completedDocument(
            'HARNESS TEST PACK — EATS+MDF+AddendumB (throwaway, do not use for real business) — 20 E2E Test Road — 30/08/26'
        );

        $fakeCertPath = tempnam(sys_get_temp_dir(), 'zzzcert') . '.pdf';
        file_put_contents($fakeCertPath, 'fake-cert-bytes');

        $pdfServiceMock = \Mockery::mock(SignaturePdfService::class);
        $pdfServiceMock->shouldReceive('generateCertificatePdf')->once()->andReturn($fakeCertPath);
        $this->app->instance(SignaturePdfService::class, $pdfServiceMock);

        $request = \Illuminate\Http\Request::create('/docuperfect/documents/' . $document->id . '/signatures/certificate', 'GET');
        $request->setUserResolver(fn () => $agent);

        $response = app(SignatureController::class)->downloadCertificate($request, $document);

        $this->assertInstanceOf(BinaryFileResponse::class, $response, 'must return a real file download, not a 500/error redirect');
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertNotNull($disposition);
        $this->assertStringNotContainsString('/', $disposition, 'no slash from the document name may reach the Content-Disposition header');
        $this->assertStringContainsString('Certificate - ', $disposition);
    }

    public function test_a_clean_document_name_is_left_otherwise_unchanged(): void
    {
        ['agent' => $agent, 'document' => $document, 'template' => $template] = $this->completedDocument(
            'EXCLUSIVE AUTHORITY TO SELL — Erf 1234, 20 Filing Test Road — 30-08-26'
        );

        Storage::fake('local');
        Storage::disk('local')->put('zzz-clean-name.pdf', 'fake-pdf-bytes');
        $template->update(['signed_pdf_client_path' => 'zzz-clean-name.pdf']);

        $request = \Illuminate\Http\Request::create('/docuperfect/documents/' . $document->id . '/signatures/download', 'GET');
        $request->setUserResolver(fn () => $agent);

        $response = app(SignatureController::class)->download($request, $document);
        $disposition = $response->headers->get('Content-Disposition');

        $this->assertStringContainsString('Signed - EXCLUSIVE AUTHORITY TO SELL', $disposition, 'sanitizer must not mangle a name with no slash in it');
        $this->assertStringContainsString('30-08-26', $disposition);
    }
}
