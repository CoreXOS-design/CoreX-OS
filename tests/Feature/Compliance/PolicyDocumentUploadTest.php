<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Compliance\AgencyPolicy;
use App\Models\Compliance\PolicyAcknowledgement;
use App\Models\Compliance\PolicyVersion;
use App\Models\User;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Tests\Feature\Compliance\Policy\PolicyTestHelpers;
use Tests\TestCase;

/**
 * A policy created by uploading a document (PDF, or Word turned into a PDF): one section per page, read page by page, one
 * signature at the end - the existing acknowledgement wizard, unchanged (compliance.md "Uploaded-document policies").
 */
final class PolicyDocumentUploadTest extends TestCase
{
    use RefreshDatabase, PolicyTestHelpers;

    private int $agencyId;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Auth::logout();
        Storage::fake('local');

        if (! (new ExecutableFinder())->find('pdftoppm') || ! (new ExecutableFinder())->find('pdfinfo')) {
            $this->markTestSkipped('poppler-utils (pdftoppm, pdfinfo) is not installed on this box.');
        }

        $this->agencyId = $this->seedAgency();
        $this->admin    = $this->makeUser($this->agencyId);
    }

    private function pdfBytes(int $pages): string
    {
        $html = '';
        for ($i = 1; $i <= $pages; $i++) {
            $html .= '<div style="' . ($i < $pages ? 'page-break-after:always;' : '') . '"><h1>Policy page ' . $i . '</h1><p>Staff must follow this rule.</p></div>';
        }
        $d = new Dompdf();
        $d->loadHtml('<html><body>' . $html . '</body></html>');
        $d->render();

        return $d->output();
    }

    private function pdf(int $pages = 3, string $name = 'privacy-policy.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->pdfBytes($pages));
    }

    private function create(array $extra = [])
    {
        return $this->actingAs($this->admin)->post(route('compliance.policy.store'), array_merge([
            'name' => 'Privacy Policy', 'policy_key' => 'privacy', 'document' => $this->pdf(),
        ], $extra));
    }

    public function test_a_pdf_becomes_one_section_per_page_plus_the_closing_declaration(): void
    {
        $this->create()->assertRedirect();

        $policy  = AgencyPolicy::where('policy_key', 'privacy')->firstOrFail();
        $version = PolicyVersion::where('policy_id', $policy->id)->firstOrFail();

        self::assertSame('draft', $version->status);
        self::assertTrue($version->isDocument());
        self::assertSame(3, (int) $version->source_page_count);
        self::assertSame('privacy-policy.pdf', $version->source_document_name);
        Storage::disk('local')->assertExists($version->source_document_path);

        $sections = $version->sections()->orderBy('display_order')->get();
        self::assertCount(4, $sections);
        self::assertSame(['section', 'section', 'section', 'acknowledgement'], $sections->pluck('section_type')->all());
        self::assertTrue((bool) $sections[0]->requires_acknowledgement);
        self::assertSame('I have read this page', $sections[0]->acknowledgement_prompt);
        self::assertFalse((bool) $sections[3]->requires_acknowledgement);
        self::assertStringContainsString('src="/', $sections[0]->body_html, 'a relative URL, so a change of domain cannot break it');
        self::assertStringNotContainsString('http', $sections[0]->body_html);
        for ($n = 1; $n <= 3; $n++) {
            Storage::disk('local')->assertExists(preg_replace('/\.pdf$/', '', $version->source_document_path) . "-pages/p-{$n}.jpg");
        }
    }

    public function test_page_images_are_private_to_the_agency_and_staff_read_page_by_page_then_sign_once(): void
    {
        $this->create();
        $version = PolicyVersion::firstOrFail();
        $version->forceFill(['status' => 'active', 'approved_at' => now(), 'approved_by' => $this->admin->id])->save();

        $this->get(route('compliance.policy.page', [$version, 1]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get(route('compliance.policy.page', [$version, 4]))->assertNotFound();

        $agent = $this->makeUser($this->agencyId, 'agent');
        Auth::logout();
        $this->actingAs($agent)->post(route('policy.ack.start', 'privacy'))->assertRedirect();
        $this->get(route('policy.ack.step', ['privacy', 1]))->assertOk()
            ->assertSee('/compliance/policy-manager/' . $version->id . '/pages/1', false)->assertSee('I have read this page');

        $ack = PolicyAcknowledgement::where('user_id', $agent->id)->firstOrFail();
        self::assertSame(3, (int) $ack->sections_total_count, 'one confirmation per page; the declaration is the signature page');

        // Another agency cannot fetch the page.
        Auth::logout();
        $other = $this->makeUser($this->seedAgency());
        $this->actingAs($other)->get(route('compliance.policy.page', [$version, 1]))->assertNotFound();
    }

    public function test_the_pdf_button_returns_the_uploaded_document_itself_and_edit_points_to_a_new_upload(): void
    {
        $this->create();
        $version = PolicyVersion::firstOrFail();

        $this->get(route('compliance.policy.pdf', $version))->assertOk()->assertDownload();
        $this->get(route('compliance.policy.edit', $version))->assertRedirect(route('compliance.policy.show', $version))->assertSessionHas('error');
        $this->get(route('compliance.policy.show', $version))->assertOk()->assertSee('Uploaded document')->assertSee('3 pages');
    }

    public function test_a_new_version_is_made_by_uploading_a_new_file(): void
    {
        $this->create();
        $policy = AgencyPolicy::firstOrFail();

        $this->post(route('compliance.policy.version.upload', $policy), ['document' => $this->pdf(2, 'v2.pdf')])->assertRedirect();

        $v2 = PolicyVersion::where('policy_id', $policy->id)->where('version_number', 2)->firstOrFail();
        self::assertSame('draft', $v2->status);
        self::assertSame(2, (int) $v2->source_page_count);
        self::assertSame(3, $v2->sections()->count());
        self::assertSame(3, (int) PolicyVersion::where('policy_id', $policy->id)->where('version_number', 1)->first()->source_page_count, 'v1 is untouched');
    }

    public function test_an_unreadable_file_leaves_nothing_behind_and_a_typed_policy_still_works(): void
    {
        $this->create(['document' => UploadedFile::fake()->createWithContent('broken.pdf', 'this is not a pdf')])
            ->assertSessionHasErrors('document');
        self::assertSame(0, AgencyPolicy::withTrashed()->count(), 'the policy and its draft were rolled back');
        self::assertSame(0, PolicyVersion::withoutGlobalScopes()->withTrashed()->count());

        $this->create(['document' => UploadedFile::fake()->create('notes.txt', 3, 'text/plain')])->assertSessionHasErrors('document');

        // No file = the typed starter policy, exactly as before.
        $this->post(route('compliance.policy.store'), ['name' => 'Typed Policy', 'policy_key' => 'typed'])->assertRedirect();
        $typed = PolicyVersion::firstOrFail();
        self::assertFalse($typed->isDocument());
        self::assertSame(2, $typed->sections()->count());
    }

    public function test_a_word_file_is_converted_to_a_pdf_first(): void
    {
        if (! (new ExecutableFinder())->find('soffice')) {
            $this->markTestSkipped('LibreOffice (soffice) is not installed on this box.');
        }
        // A minimal Word file (two pages), built by hand: PhpWord is not a dependency.
        $path = sys_get_temp_dir() . '/policy-test-' . uniqid() . '.docx';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Staff Conduct Policy - page one.</w:t></w:r></w:p><w:p><w:r><w:br w:type="page"/></w:r></w:p><w:p><w:r><w:t>Page two.</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();

        $this->create(['name' => 'Staff Conduct', 'policy_key' => 'conduct', 'document' => new UploadedFile($path, 'conduct.docx', null, null, true)])->assertRedirect();
        @unlink($path);

        $version = PolicyVersion::firstOrFail();
        self::assertTrue($version->isDocument());
        self::assertSame(2, (int) $version->source_page_count);
        self::assertStringEndsWith('.pdf', $version->source_document_path);
    }
}
