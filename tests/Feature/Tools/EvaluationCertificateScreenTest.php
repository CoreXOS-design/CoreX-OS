<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\EvaluationCertificate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * /tools/cma redesign — the screen renders the Evaluation Certificate builder and
 * the persist endpoints (store/update) create + edit rows. Companion to
 * EvaluationCertificateSignTest (the sign surface).
 */
final class EvaluationCertificateScreenTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        $this->agent = User::factory()->create([
            'name' => 'Full Agent', 'role' => 'agent', 'designation' => 'Property Practitioner',
            'branch_id' => $branch->id, 'agency_id' => $this->agency->id, 'is_active' => true,
        ]);
    }

    public function test_screen_renders_the_evaluation_certificate_builder(): void
    {
        $res = $this->actingAs($this->agent)->get(route('tools.cma'));
        $res->assertOk()
            ->assertSee('Evaluation Certificate')
            ->assertSee('evalCert()', false)                 // the Alpine component is wired
            ->assertSee('searchProps:', false)               // the endpoint config object is emitted
            ->assertSee('Find a property', false);           // the property-search box is present
    }

    public function test_screen_no_longer_ships_the_old_client_side_generator(): void
    {
        $res = $this->actingAs($this->agent)->get(route('tools.cma'));
        $res->assertDontSee('generateCmaPrintHtml', false)
            ->assertDontSee('Market Analysis Certificate', false); // old title, scrubbed
    }

    public function test_store_creates_a_draft_certificate(): void
    {
        $res = $this->actingAs($this->agent)->postJson(route('tools.cma.evaluation.store'), [
            'address' => '12 Smith Street, Shelly Beach',
            'property_type' => 'House',
            'estimated_market_value' => 2500000,
            'bedrooms' => 3, 'bathrooms' => 2, 'parking' => 2,
            'key_features' => 'Sea views',
        ]);

        $res->assertCreated()->assertJson(['status' => 'draft', 'is_signed' => false]);
        $id = $res->json('id');
        $this->assertDatabaseHas('evaluation_certificates', [
            'id' => $id, 'agency_id' => $this->agency->id, 'created_by_user_id' => $this->agent->id,
            'address' => '12 Smith Street, Shelly Beach', 'estimated_market_value' => 2500000, 'status' => 'draft',
        ]);
    }

    public function test_store_requires_an_address(): void
    {
        $this->actingAs($this->agent)
            ->postJson(route('tools.cma.evaluation.store'), ['property_type' => 'House'])
            ->assertStatus(422);
    }

    public function test_update_edits_an_unsigned_certificate(): void
    {
        $cert = EvaluationCertificate::create([
            'agency_id' => $this->agency->id, 'address' => 'Old address',
            'status' => EvaluationCertificate::STATUS_DRAFT, 'created_by_user_id' => $this->agent->id,
        ]);

        $this->actingAs($this->agent)
            ->putJson(route('tools.cma.evaluation.update', $cert), [
                'address' => 'New address', 'estimated_market_value' => 999000,
            ])
            ->assertOk();

        $this->assertDatabaseHas('evaluation_certificates', [
            'id' => $cert->id, 'address' => 'New address', 'estimated_market_value' => 999000,
        ]);
    }

    public function test_a_signed_certificate_cannot_be_edited(): void
    {
        $cert = EvaluationCertificate::create([
            'agency_id' => $this->agency->id, 'address' => 'Signed address',
            'status' => EvaluationCertificate::STATUS_AUTHORISED, 'created_by_user_id' => $this->agent->id,
            'signed_by_user_id' => $this->agent->id,
        ]);

        $this->actingAs($this->agent)
            ->putJson(route('tools.cma.evaluation.update', $cert), ['address' => 'Hacked'])
            ->assertStatus(409);

        $this->assertDatabaseHas('evaluation_certificates', ['id' => $cert->id, 'address' => 'Signed address']);
    }

    public function test_a_foreign_agency_certificate_is_not_updatable(): void
    {
        $other = Agency::create(['name' => 'Rival', 'slug' => 'rival']);
        $cert = EvaluationCertificate::withoutGlobalScopes()->create([
            'agency_id' => $other->id, 'address' => 'Foreign',
            'status' => EvaluationCertificate::STATUS_DRAFT, 'created_by_user_id' => $this->agent->id,
        ]);

        $this->actingAs($this->agent)
            ->putJson(route('tools.cma.evaluation.update', $cert), ['address' => 'X'])
            ->assertStatus(404);
    }

    public function test_share_meta_returns_a_signed_public_link_for_a_linked_contact(): void
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'first_name' => 'Jane', 'last_name' => 'Doe', 'phone' => '0821234567',
        ]);
        $cert = EvaluationCertificate::create([
            'agency_id' => $this->agency->id, 'address' => '1 Beach Rd', 'contact_id' => $contact->id,
            'status' => EvaluationCertificate::STATUS_DRAFT, 'created_by_user_id' => $this->agent->id,
        ]);

        $res = $this->actingAs($this->agent)->getJson(route('tools.cma.evaluation.share-meta', $cert));
        $res->assertOk()->assertJson(['contact_id' => $contact->id]);
        $this->assertStringContainsString('/tools/cma/evaluation/public/', $res->json('share_url'));
        $this->assertStringContainsString('signature=', $res->json('share_url'));  // it is a signed URL
        $this->assertStringContainsString($res->json('share_url'), $res->json('message'));
    }

    public function test_share_meta_422_without_a_linked_contact(): void
    {
        $cert = EvaluationCertificate::create([
            'agency_id' => $this->agency->id, 'address' => '1 Beach Rd',
            'status' => EvaluationCertificate::STATUS_DRAFT, 'created_by_user_id' => $this->agent->id,
        ]);

        $this->actingAs($this->agent)
            ->getJson(route('tools.cma.evaluation.share-meta', $cert))
            ->assertStatus(422);
    }

    public function test_public_view_requires_a_valid_signed_url(): void
    {
        Storage::fake();
        $cert = EvaluationCertificate::create([
            'agency_id' => $this->agency->id, 'address' => '1 Beach Rd',
            'status' => EvaluationCertificate::STATUS_AUTHORISED, 'created_by_user_id' => $this->agent->id,
            'signed_by_user_id' => $this->agent->id, 'signed_pdf_path' => 'eval/signed.pdf',
        ]);
        Storage::put('eval/signed.pdf', '%PDF-1.4 fake');

        // Unsigned (tampered / hand-typed) link → 403; no auth involved either way.
        $this->get(route('tools.cma.evaluation.public', $cert))->assertStatus(403);

        // A valid temporary signed link → streams the filed PDF.
        $url = URL::temporarySignedRoute('tools.cma.evaluation.public', now()->addDay(), ['certificate' => $cert->id]);
        $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_pdf_view_shows_authorised_by_only_when_flagged(): void
    {
        $cert = EvaluationCertificate::create([
            'agency_id' => $this->agency->id, 'address' => '1 Beach Rd',
            'status' => EvaluationCertificate::STATUS_AUTHORISED,
            'created_by_user_id' => $this->agent->id, 'signed_by_user_id' => $this->agent->id,
        ])->fresh();

        $hidden = view('tools.evaluation-certificate.pdf', ['certificate' => $cert, 'showAuthoriser' => false])->render();
        $this->assertStringNotContainsString('Authorised by', $hidden);
        $this->assertStringContainsString('Evaluated &amp; signed by', $hidden);   // signer block always present
        $this->assertStringContainsString($this->agency->name, $hidden);           // logo-less header falls back to name

        $shown = view('tools.evaluation-certificate.pdf', ['certificate' => $cert, 'showAuthoriser' => true])->render();
        $this->assertStringContainsString('Authorised by', $shown);
    }

    public function test_authoriser_visibility_hides_for_full_status_shows_for_candidate(): void
    {
        $mk = fn (string $designation) => User::factory()->create([
            'role' => 'agent', 'designation' => $designation,
            'agency_id' => $this->agency->id, 'branch_id' => $this->agent->branch_id, 'is_active' => true,
        ]);
        $full = $mk('Property Practitioner');
        $candidate = $mk('Candidate Property Practitioner');

        $fullCert = EvaluationCertificate::create([
            'agency_id' => $this->agency->id, 'address' => 'A', 'status' => EvaluationCertificate::STATUS_DRAFT,
            'created_by_user_id' => $full->id,
        ]);
        $candCert = EvaluationCertificate::create([
            'agency_id' => $this->agency->id, 'address' => 'B', 'status' => EvaluationCertificate::STATUS_DRAFT,
            'created_by_user_id' => $candidate->id,
        ]);

        $ctrl = app(\App\Http\Controllers\Tools\EvaluationCertificateController::class);
        $show = new \ReflectionMethod($ctrl, 'showsAuthoriser');
        $show->setAccessible(true);

        $this->assertFalse($show->invoke($ctrl, $fullCert), 'full-status creator: no authoriser block');
        $this->assertTrue($show->invoke($ctrl, $candCert), 'candidate creator: authoriser block shows');

        // An authoriser on record always shows it, regardless of creator designation.
        $fullCert->authorised_by_user_id = $full->id;
        $fullCert->save();
        $this->assertTrue($show->invoke($ctrl, $fullCert->fresh()));
    }

    public function test_download_filename_is_built_from_the_property_address(): void
    {
        $ctrl = app(\App\Http\Controllers\Tools\EvaluationCertificateController::class);
        $fn = new \ReflectionMethod($ctrl, 'certificateFilename');
        $fn->setAccessible(true);

        $addressed = EvaluationCertificate::create([
            'agency_id' => $this->agency->id, 'address' => '380 Wilfred Street, Shelly Beach, Margate',
            'status' => EvaluationCertificate::STATUS_DRAFT, 'created_by_user_id' => $this->agent->id,
        ]);
        $this->assertSame(
            '380-Wilfred-Street-Shelly-Beach-Margate-Evaluation-Certificate.pdf',
            $fn->invoke($ctrl, $addressed)
        );

        // Fallback to the ref/id when there is no address.
        $blank = EvaluationCertificate::create([
            'agency_id' => $this->agency->id, 'address' => '',
            'status' => EvaluationCertificate::STATUS_DRAFT, 'created_by_user_id' => $this->agent->id,
        ]);
        $this->assertSame('Evaluation-Certificate-EC-' . $blank->id . '.pdf', $fn->invoke($ctrl, $blank));
    }

    public function test_pdf_uses_the_full_esign_company_letterhead(): void
    {
        $cert = EvaluationCertificate::create([
            'agency_id' => $this->agency->id, 'address' => '1 Beach Rd',
            'status' => EvaluationCertificate::STATUS_DRAFT, 'created_by_user_id' => $this->agent->id,
        ])->fresh();

        $html = view('tools.evaluation-certificate.pdf', ['certificate' => $cert, 'showAuthoriser' => false])->render();
        $this->assertStringContainsString('ec-letterhead', $html);                 // reused e-sign block, wrapped
        $this->assertStringContainsString('company-header-contact-grid', $html);   // the shared letterhead component
        $this->assertStringContainsString('Reg no:', $html);                       // full company details present
        $this->assertStringContainsString('FFC:', $html);
    }
}
