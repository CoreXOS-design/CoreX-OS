<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentPermission;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Property;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Document inline view — the "View" button beside every Download in a CoreX drive.
 * Spec: .ai/specs/document-inline-view.md
 *
 * Proves the three things that make View safe rather than merely convenient:
 *  1. It serves `Content-Disposition: inline` with a WHITELISTED Content-Type + nosniff, so an
 *     `.svg` / `text/html` row can never execute in the app origin (§5.2).
 *  2. It carries the same per-record scope guard as the download route beside it (§6) — a
 *     document belonging to another property/contact 404s, not 200s.
 *  3. It deliberately does NOT carry `deny_assistant_download` — AT-267's contract is that an
 *     assistant may still OPEN and VIEW a document, only not pull it down (§6). So the same
 *     assistant gets 200 on view and 403 on download.
 */
final class DocumentInlineViewTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private User $assistant;
    private AssistantAssignment $assignment;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid(), 'assistants_enabled' => true]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        Role::create(['name' => 'assistant', 'label' => 'Assistant', 'agency_id' => $this->agency->id]);

        $this->agent     = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true]);
        $this->assistant = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'assistant', 'is_active' => true, 'is_assistant' => true]);

        $this->assignment = AssistantAssignment::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'assistant_user_id' => $this->assistant->id, 'agent_user_id' => $this->agent->id,
            'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);

        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'Marine Drive', 'street_name' => 'Marine Drive', 'street_number' => '14',
            'suburb' => 'Margate', 'city' => 'Margate', 'status' => 'active',
        ]);

        foreach (['access_properties', 'properties.view', 'access_contacts', 'contacts.view'] as $k) {
            $this->grant($k, str_contains($k, '.view') ? 'branch' : null);
        }
        PermissionService::clearCache();
        User::flushAssistantsEnabledCache();
        PermissionService::forceProductionPosture();
    }

    // ---------------------------------------------------------------- model whitelist

    /** @dataProvider inlineMimeCases */
    public function test_inline_mime_type_is_a_whitelist(?string $mime, string $name, ?string $expected): void
    {
        $doc = new Document(['mime_type' => $mime, 'original_name' => $name]);

        $this->assertSame($expected, $doc->inlineMimeType());
        $this->assertSame($expected !== null, $doc->isViewableInline());
    }

    public static function inlineMimeCases(): array
    {
        return [
            'pdf'                   => ['application/pdf', 'mandate.pdf', 'application/pdf'],
            'jpeg'                  => ['image/jpeg', 'photo.jpg', 'image/jpeg'],
            'png'                   => ['image/png', 'plan.PNG', 'image/png'],
            'webp'                  => ['image/webp', 'x.webp', 'image/webp'],
            'legacy blank mime'     => ['', 'legacy.pdf', 'application/pdf'],
            'octet-stream upload'   => ['application/octet-stream', 'legacy2.PDF', 'application/pdf'],
            'null mime'             => [null, 'nomime.jpeg', 'image/jpeg'],
            // The security cases — an SVG or an HTML mime must NEVER be viewable inline.
            'svg is refused'        => ['image/svg+xml', 'logo.svg', null],
            'html is refused'       => ['text/html', 'evil.html', null],
            'svg by extension'      => ['', 'evil.svg', null],
            'word is refused'       => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'a.docx', null],
            'excel is refused'      => ['', 'sheet.xlsx', null],
        ];
    }

    public function test_an_html_mime_document_is_never_served_inline(): void
    {
        // A row whose mime_type says text/html is refused by the whitelist, so it falls through to
        // the download path and comes back as an ATTACHMENT. It must never render in the app origin,
        // whatever the file is named.
        $doc = $this->makePropertyDocument('report.pdf', 'text/html', '<script>alert(1)</script>');

        $response = $this->actingAs($this->agent)
            ->get(route('corex.properties.files.view', [$this->property, $doc]));

        $response->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_the_inline_content_type_comes_from_the_whitelist_not_from_the_row(): void
    {
        // Worst case for sniffing: the row is tagged application/pdf but holds SVG bytes under an
        // .svg name. The response must state application/pdf (the whitelist value) + nosniff, so the
        // browser hands it to the PDF renderer and never to the SVG/script parser.
        $doc = $this->makePropertyDocument('evil.svg', 'application/pdf', '<svg onload="alert(1)"></svg>');

        $response = $this->actingAs($this->agent)
            ->get(route('corex.properties.files.view', [$this->property, $doc]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
    }

    // ---------------------------------------------------------------- property drive

    public function test_property_drive_view_serves_the_pdf_inline(): void
    {
        $doc = $this->makePropertyDocument('mandate.pdf', 'application/pdf', '%PDF-1.4 fake');

        $response = $this->actingAs($this->agent)
            ->get(route('corex.properties.files.view', [$this->property, $doc]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('mandate.pdf', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_property_drive_view_404s_for_a_document_on_another_property(): void
    {
        $other = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'Beach Road', 'street_name' => 'Beach Road', 'street_number' => '2',
            'suburb' => 'Margate', 'city' => 'Margate', 'status' => 'active',
        ]);
        $doc = $this->makePropertyDocument('mandate.pdf', 'application/pdf');

        // Direct-URL access by id is blocked, not merely unlinked (CLAUDE.md rule 8).
        $this->actingAs($this->agent)
            ->get(route('corex.properties.files.view', [$other, $doc]))
            ->assertNotFound();
    }

    public function test_a_non_viewable_property_file_falls_back_to_the_download(): void
    {
        $doc = $this->makePropertyDocument('lease.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $response = $this->actingAs($this->agent)
            ->get(route('corex.properties.files.view', [$this->property, $doc]));

        $response->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
    }

    // ---------------------------------------------------------------- contact drive

    public function test_contact_drive_view_serves_the_pdf_inline_and_404s_across_contacts(): void
    {
        $contact      = $this->makeContact('Sipho', 'Dlamini');
        $otherContact = $this->makeContact('Nomvula', 'Khumalo');

        $doc = $this->makeStoredDocument('fica.pdf', 'application/pdf');
        $doc->contacts()->attach($contact->id);

        $this->actingAs($this->agent)
            ->get(route('corex.contacts.documents.view', [$contact, $doc]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($this->agent)
            ->get(route('corex.contacts.documents.view', [$otherContact, $doc]))
            ->assertNotFound();
    }

    // ---------------------------------------------------------------- the buttons render

    public function test_the_property_drive_row_renders_a_view_button_for_a_pdf_and_not_for_a_docx(): void
    {
        $pdf  = $this->makePropertyDocument('mandate.pdf', 'application/pdf');
        $docx = $this->makePropertyDocument('lease.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->actingAs($this->agent);

        $html = view('corex.properties._drive-row', [
            'doc' => $pdf, 'property' => $this->property, 'documentTypes' => collect(),
        ])->render();
        $this->assertStringContainsString(route('corex.properties.files.view', [$this->property, $pdf]), $html);
        $this->assertStringContainsString('>View</a>', $html);
        $this->assertStringContainsString('Download', $html);

        // A non-viewable type must not show a dead View button.
        $html = view('corex.properties._drive-row', [
            'doc' => $docx, 'property' => $this->property, 'documentTypes' => collect(),
        ])->render();
        $this->assertStringNotContainsString(route('corex.properties.files.view', [$this->property, $docx]), $html);
        $this->assertStringContainsString('Download', $html);
    }

    public function test_the_contact_drive_row_renders_a_view_button(): void
    {
        $contact = $this->makeContact('Sipho', 'Dlamini');
        $doc     = $this->makeStoredDocument('fica.pdf', 'application/pdf');
        $doc->contacts()->attach($contact->id);

        $this->actingAs($this->agent);

        $html = view('corex.contacts._drive-row', [
            'doc' => $doc, 'contact' => $contact->fresh(), 'documentTypes' => collect(),
        ])->render();

        $this->assertStringContainsString(route('corex.contacts.documents.view', [$contact, $doc]), $html);
        $this->assertStringContainsString('>View</a>', $html);
    }

    // ---------------------------------------------------------------- AT-267 posture

    public function test_an_assistant_with_downloads_off_can_still_view_but_not_download(): void
    {
        $doc = $this->makePropertyDocument('mandate.pdf', 'application/pdf');

        $this->assignment->forceFill(['can_download_documents' => false])->save();
        User::flushAssistantsEnabledCache();

        // AT-267's own contract: "The assistant can still OPEN and VIEW a document in the browser
        // — this blocks only the act of pulling the file down."
        $this->actingAs(User::find($this->assistant->id))
            ->get(route('corex.properties.files.view', [$this->property, $doc]))
            ->assertOk();

        $this->actingAs(User::find($this->assistant->id))
            ->get(route('corex.properties.files.download', [$this->property, $doc]))
            ->assertForbidden();
    }

    public function test_the_fallback_download_of_a_non_viewable_file_still_honours_the_toggle(): void
    {
        // The view route has no deny_assistant_download middleware, so the controller itself must
        // re-check the toggle on the download fallback — otherwise View becomes a way around it.
        $doc = $this->makePropertyDocument('lease.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->assignment->forceFill(['can_download_documents' => false])->save();
        User::flushAssistantsEnabledCache();

        $this->actingAs(User::find($this->assistant->id))
            ->get(route('corex.properties.files.view', [$this->property, $doc]))
            ->assertForbidden();
    }

    // ---------------------------------------------------------------- helpers

    private function makeStoredDocument(string $name, string $mime, string $bytes = 'fake bytes'): Document
    {
        $path = "properties/{$this->property->id}/files/" . uniqid() . '-' . $name;
        Storage::disk('local')->put($path, $bytes);

        return Document::create([
            'original_name' => $name,
            'storage_path'  => $path,
            'disk'          => 'local',
            'mime_type'     => $mime,
            'size'          => strlen($bytes),
            'source_type'   => 'upload',
            'uploaded_by'   => $this->agent->id,
        ]);
    }

    private function makePropertyDocument(string $name, string $mime, string $bytes = 'fake bytes'): Document
    {
        $doc = $this->makeStoredDocument($name, $mime, $bytes);
        $doc->properties()->attach($this->property->id);

        return $doc;
    }

    private function makeContact(string $first, string $last): Contact
    {
        return Contact::create([
            'agency_id'  => $this->agency->id,
            'branch_id'  => $this->branch->id,
            'agent_id'   => $this->agent->id,
            'first_name' => $first,
            'last_name'  => $last,
        ]);
    }

    private function grant(string $key, ?string $scope = null): void
    {
        RolePermission::updateOrCreate(
            ['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id],
            ['scope' => $scope],
        );
        AssistantAssignmentPermission::updateOrCreate(
            ['assistant_assignment_id' => $this->assignment->id, 'permission_key' => $key],
            ['agency_id' => $this->agency->id, 'granted' => true, 'scope' => $scope],
        );
    }
}
