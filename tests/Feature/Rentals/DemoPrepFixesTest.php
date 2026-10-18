<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\FicaDocument;
use App\Models\FicaSubmission;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\SignedDocumentDistributionLog;
use App\Models\User;
use App\Services\Rentals\RentalInspectionCopiesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 13 Oct 2026 demo-prep fixes (rentals, QA1):
 *  1. A public FICA upload by a logged-out applicant takes its agency from the submission (was "Field 'agency_id' doesn't have a default value").
 *  2/3. Two Alpine crashes: x-for keyed by an email two recipients can share; a link that dereferenced chainTail before any inspection exists.
 *  4. The "Copies sent" row shows the person's name with their role - not the role twice.
 *  5. Button pairs that measured 36px against 30px sit in the equal-button scope.
 *
 * Input paths: FICA upload by a guest with and without a logged-in staff user in the session, a wrong/expired token still refused;
 * copies rows for tenant / landlord / creator / inspector / agency-copy, a removed contact, an unknown role; the view sources.
 */
final class DemoPrepFixesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private User $inspector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Sea Point', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Retha Kelly', 'email' => 'retha@cape.test']);
        $this->inspector = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Falan Du Bois', 'email' => 'falan@cape.test']);
    }

    // ── 1. public FICA upload ──────────────────────────────────────────────

    private function submission(?Agency $agency = null): FicaSubmission
    {
        $agency ??= $this->agency;
        $branch = Branch::where('agency_id', $agency->id)->first() ?? Branch::forceCreate(['name' => 'Main', 'agency_id' => $agency->id]);
        $contact = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Thandi', 'last_name' => 'Mokoena', 'email' => 'thandi@example.co.za']);

        return FicaSubmission::create([
            'contact_id' => $contact->id, 'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'requested_by' => $this->agent->id, 'status' => 'draft',
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ]);
    }

    public function test_a_logged_out_applicant_can_upload_and_the_document_gets_the_submissions_agency(): void
    {
        $submission = $this->submission();

        $this->post(route('fica.upload', $submission->token), [
            'file' => UploadedFile::fake()->image('id.jpg'), 'document_type' => 'id_copy',
        ])->assertOk()->assertJson(['success' => true]);

        $doc = FicaDocument::withoutGlobalScopes()->where('fica_submission_id', $submission->id)->firstOrFail();
        $this->assertSame($this->agency->id, (int) $doc->agency_id);
        $this->assertSame('id_copy', $doc->document_type);
    }

    public function test_two_agencies_applicants_each_land_in_their_own_agency(): void
    {
        $other = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-' . uniqid()]);
        $a = $this->submission();
        $b = $this->submission($other);

        $this->post(route('fica.upload', $a->token), ['file' => UploadedFile::fake()->create('poa.pdf', 20, 'application/pdf'), 'document_type' => 'proof_of_address'])->assertOk();
        $this->post(route('fica.upload', $b->token), ['file' => UploadedFile::fake()->create('poa.pdf', 20, 'application/pdf'), 'document_type' => 'proof_of_address'])->assertOk();

        $this->assertSame($this->agency->id, (int) FicaDocument::withoutGlobalScopes()->where('fica_submission_id', $a->id)->value('agency_id'));
        $this->assertSame($other->id, (int) FicaDocument::withoutGlobalScopes()->where('fica_submission_id', $b->id)->value('agency_id'));
    }

    public function test_the_staff_wet_ink_service_also_takes_the_agency_from_the_submission_not_the_session(): void
    {
        $submission = $this->submission();
        \Illuminate\Support\Facades\Auth::logout(); // a job / owner with no agency context

        $doc = app(\App\Services\Compliance\FicaWetInkService::class)->addUploadedDocument($submission, UploadedFile::fake()->image('id.jpg'), 'id_copy');

        $this->assertSame($this->agency->id, (int) $doc->agency_id);
    }

    public function test_a_bad_token_and_a_bad_file_are_still_refused(): void
    {
        $this->post(route('fica.upload', Str::random(64)), ['file' => UploadedFile::fake()->image('id.jpg'), 'document_type' => 'id_copy'])->assertNotFound();

        $submission = $this->submission();
        $this->post(route('fica.upload', $submission->token), ['file' => UploadedFile::fake()->create('virus.exe', 10), 'document_type' => 'id_copy'])->assertSessionHasErrors('file');
        $this->post(route('fica.upload', $submission->token), ['file' => UploadedFile::fake()->image('id.jpg')])->assertSessionHasErrors('document_type');
        $this->assertSame(0, FicaDocument::withoutGlobalScopes()->count());
    }

    // ── 4. Copies sent: name and role ──────────────────────────────────────

    private function inspectionWithLog(array $rows): RentalInspection
    {
        $property = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => '14 Jackson Street', 'status' => 'active', 'listing_type' => 'rental']);
        $lease = Lease::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id, 'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 12500, 'start_date' => now()->subMonths(6), 'created_by_user_id' => $this->agent->id]);
        $inspection = RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $lease->id, 'type' => RentalInspection::TYPE_OUT,
            'created_by_user_id' => $this->agent->id, 'inspector_user_id' => $this->inspector->id,
        ]);
        foreach ($rows as $r) {
            SignedDocumentDistributionLog::create($r + [
                'agency_id' => $this->agency->id, 'distributable_type' => RentalInspection::class, 'distributable_id' => $inspection->id,
                'channel' => 'email', 'mode' => 'auto', 'status' => 'sent',
            ]);
        }

        return $inspection;
    }

    public function test_the_copies_panel_shows_the_agents_name_with_their_role_not_the_role_twice(): void
    {
        $tenant = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Naledi', 'last_name' => 'Dlamini', 'email' => 'naledi@example.co.za']);
        $inspection = $this->inspectionWithLog([
            ['recipient_role' => 'tenant', 'recipient_contact_id' => $tenant->id, 'recipient_email' => 'naledi@example.co.za'],
            ['recipient_role' => 'creator', 'recipient_email' => 'retha@cape.test'],
            ['recipient_role' => 'inspector', 'recipient_email' => 'falan@cape.test'],
            ['recipient_role' => 'agency', 'recipient_email' => 'office@cape.test'],
        ]);

        $rows = collect(app(RentalInspectionCopiesService::class)->panelFor($inspection))->keyBy('role_label');

        $this->assertSame('Naledi Dlamini', $rows['Tenant']['party']);
        $this->assertSame('Retha Kelly', $rows['Agent who created it']['party']);
        $this->assertSame('Falan Du Bois', $rows['Inspector']['party']);
        $this->assertSame('Cape Rentals', $rows['Agency copy']['party']);
        foreach ($rows as $label => $row) {
            $this->assertNotSame($label, $row['party'], "the {$label} row must not show its role as the name");
        }
    }

    public function test_the_copies_panel_degrades_to_the_email_never_to_the_role_label(): void
    {
        $inspection = $this->inspectionWithLog([
            ['recipient_role' => 'creator', 'recipient_email' => 'retha@cape.test'],
            ['recipient_role' => 'other_thing', 'recipient_email' => 'someone@example.co.za'],
            ['recipient_role' => 'tenant', 'recipient_contact_id' => 999999, 'recipient_email' => 'gone@example.co.za'],
        ]);
        // The creating agent has since been removed.
        $inspection->forceFill(['created_by_user_id' => null])->saveQuietly();

        $rows = collect(app(RentalInspectionCopiesService::class)->panelFor($inspection->fresh()))->keyBy('email');

        $this->assertSame('retha@cape.test', $rows['retha@cape.test']['party'], 'no creator on file: show the address, not "Agent who created it"');
        $this->assertSame('someone@example.co.za', $rows['someone@example.co.za']['party']);
        $this->assertSame('Contact removed', $rows['gone@example.co.za']['party']);
    }

    // ── 2/3/5. view sources ────────────────────────────────────────────────

    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    public function test_no_recipient_list_is_keyed_by_email_alone(): void
    {
        foreach (['resources/views/corex/rental-inventories/show.blade.php', 'resources/views/corex/properties/show.blade.php'] as $file) {
            $src = $this->source($file);
            $this->assertStringNotContainsString(':key="r.email"', $src, "$file: two recipients can share one address; key by position as well");
            $this->assertStringContainsString("x-for=\"(r, i) in", $src, $file);
        }
    }

    public function test_the_download_report_link_does_not_touch_chainTail_before_an_inspection_exists(): void
    {
        $src = $this->source('resources/views/corex/properties/show.blade.php');
        $this->assertStringNotContainsString(":href=\"inspectionUrls.inspectionsBase + '/' + chainTail.id", $src);
        $this->assertStringContainsString(":href=\"chainTail ? inspectionUrls.inspectionsBase + '/' + chainTail.id + '/report' : '#'\"", $src);
    }

    public function test_the_three_button_pairs_sit_in_the_equal_button_scope(): void
    {
        $this->assertStringContainsString('cx-equal-btns', $this->source('resources/views/corex/rentals/reports/index.blade.php'));
        $contacts = $this->source('resources/views/corex/contacts/index.blade.php');
        $this->assertMatchesRegularExpression('/cx-equal-btns[^>]*><button type="submit" class="corex-btn-outline text-xs">Search/', $contacts);
        $catalogue = $this->source('resources/views/corex/rental-catalogue-items/index.blade.php');
        $this->assertStringContainsString('cx-equal-btns', $catalogue);
        $this->assertStringContainsString('class="corex-btn-outline text-xs">Edit</a>', $catalogue);
        $this->assertStringContainsString('class="corex-btn-danger text-xs">Archive</button>', $catalogue);
    }
}
