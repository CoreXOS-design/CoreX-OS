<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderServiceType;
use App\Models\DealV2\AgencyServiceType;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalFaultPhotoMark;
use App\Models\RentalFaultPhotoNote;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportPhoto;
use App\Models\RentalFaultReportUpdate;
use App\Models\RentalFaultType;
use App\Models\RentalJobCard;
use App\Models\RentalPortalSetting;
use App\Models\RolePermission;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\RentalFaultContractorService;
use App\Services\Rentals\RentalFaultPhotoAnnotationService as Annotations;
use App\Services\Rentals\RentalFaultTypePortalView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AT-452 (.ai/specs/rentals-faults-work-orders.md §18): the agent's marks and per-photo notes, who sees them, the original file staying
 * byte-for-byte untouched, the owner decision screen / "Try this first" payloads, and the "no contractor" warning before sending.
 */
class FaultPhotoMarksTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private Contact $tenant;
    private Contact $landlord;
    private RentalFaultType $faultType;
    /** @var array<int,string> public-disk paths this test wrote */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->agency = Agency::create(['name' => 'Marks Agency', 'slug' => 'mk-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Marks Unit', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $this->tenant = Contact::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 'tina+' . uniqid() . '@example.com',
        ]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $this->landlord = Contact::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Lenny', 'last_name' => 'Landlord', 'email' => 'lenny+' . uniqid() . '@example.com',
        ]);
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
        $this->faultType = RentalFaultType::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'name' => 'Leaking tap', 'category' => 'Plumbing', 'urgency' => 'routine',
            'first_aid_steps' => RentalFaultType::SAFETY_LINE . "\n\nClose the valve. Dry the area.", 'is_default' => false, 'sort_order' => 1, 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            Storage::disk('public')->delete($path);
        }
        Storage::disk('public')->deleteDirectory(\App\Services\Images\PortalImageService::DIR . '/fault-marks-test');
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /** A real JPEG on the public disk, so the checksum test and PortalImageService have a file to read. */
    private function realPhoto(RentalFaultReport $fault, ?int $uploadedBy = null): RentalFaultReportPhoto
    {
        $im = imagecreatetruecolor(1200, 800);
        imagefill($im, 0, 0, imagecolorallocate($im, random_int(0, 255), 120, 90));
        ob_start();
        imagejpeg($im, null, 90);
        $bytes = ob_get_clean();
        $rel = 'fault-marks-test/' . uniqid('p', true) . '.jpg';
        Storage::disk('public')->put($rel, $bytes);
        $this->written[] = $rel;

        return RentalFaultReportPhoto::create([
            'agency_id' => $this->agency->id, 'rental_fault_report_id' => $fault->id,
            'storage_path' => '/storage/' . $rel, 'uploaded_by_user_id' => $uploadedBy, 'file_size_bytes' => strlen($bytes),
        ]);
    }

    private function fault(array $over = []): RentalFaultReport
    {
        return RentalFaultReport::create(array_merge([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'rental_fault_type_id' => $this->faultType->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_TENANT, 'reported_by_contact_id' => $this->tenant->id,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON,
            'title' => 'Tap leaks', 'description' => 'Under the sink.', 'created_by_user_id' => $this->agent->id,
            'reported_at' => now(), 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED,
        ], $over));
    }

    private function circle(array $over = []): array
    {
        return array_merge(['shape' => 'circle', 'geometry' => ['cx' => 0.4, 'cy' => 0.5, 'r' => 0.1], 'colour' => '#e11d48'], $over);
    }

    private function marksUrl(RentalFaultReport $f, RentalFaultReportPhoto $p, string $tail = ''): string
    {
        return "/corex/rental-fault-reports/{$f->id}/photos/{$p->id}/marks{$tail}";
    }

    private function clientUserFor(Contact $contact): ClientUser
    {
        $cu = ClientUser::firstOrCreate(['email' => $contact->email], ['current_agency_id' => $contact->agency_id]);
        $contact->forceFill(['client_user_id' => $cu->id])->saveQuietly();

        return $cu;
    }

    private function supplier(string $name, string $trade): AgencyServiceProvider
    {
        $type = AgencyServiceType::withoutGlobalScopes()->firstOrCreate(
            ['agency_id' => $this->agency->id, 'code' => $trade], ['label' => $trade, 'sort_order' => 1, 'is_active' => true]
        );
        $provider = AgencyServiceProvider::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => $name, 'phone' => '0315550000', 'is_active' => true, 'specialty' => 'other']);
        AgencyServiceProviderServiceType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'service_provider_id' => $provider->id, 'service_type' => $type->code]);

        return $provider;
    }

    /** A sent fault the owner can open, with photo 1 shared and photo 2 not. */
    private function sentFaultWithTwoPhotos(): array
    {
        $fault = $this->fault();
        $shared = $this->realPhoto($fault);
        $private = $this->realPhoto($fault);
        $fault->saveOwnerVersion(['owner_title' => 'Tap leaks', 'owner_description' => 'Under the sink.', 'owner_photo_ids' => [$shared->id]], $this->agent);
        $fault->fresh()->requestApproval($this->agent);

        return [$fault->fresh(), $shared, $private];
    }

    // ── marks: the staff routes ──────────────────────────────────────────────────────────────

    public function test_an_editor_adds_moves_removes_and_restores_a_mark_and_each_step_is_in_the_history(): void
    {
        $fault = $this->fault();
        $photo = $this->realPhoto($fault);

        $created = $this->actingAs($this->agent)->postJson($this->marksUrl($fault, $photo), $this->circle())->assertCreated()->json('mark');
        $this->assertSame('circle', $created['shape']);
        $this->assertSame(0.4, $created['geometry']['cx']);

        $this->actingAs($this->agent)->putJson($this->marksUrl($fault, $photo, "/{$created['id']}"), ['shape' => 'circle', 'geometry' => ['cx' => 0.6, 'cy' => 0.5, 'r' => 0.1], 'colour' => '#2563eb'])->assertOk();
        $row = RentalFaultPhotoMark::withoutGlobalScope(AgencyScope::class)->find($created['id']);
        $this->assertSame(0.6, $row->geometry['cx']);
        $this->assertSame('#2563eb', $row->colour);
        $this->assertSame($this->agent->id, $row->created_by_user_id);
        $this->assertNotNull($row->created_at);

        $this->actingAs($this->agent)->deleteJson($this->marksUrl($fault, $photo, "/{$created['id']}"))->assertOk();
        $this->assertNull(RentalFaultPhotoMark::withoutGlobalScope(AgencyScope::class)->find($created['id']), 'gone from normal reads');
        $trashed = RentalFaultPhotoMark::withoutGlobalScope(AgencyScope::class)->withTrashed()->find($created['id']);
        $this->assertNotNull($trashed->deleted_at, 'soft delete, never a hard delete');
        $this->assertSame($this->agent->id, $trashed->deleted_by_user_id);

        $this->actingAs($this->agent)->postJson($this->marksUrl($fault, $photo, "/{$created['id']}/restore"))->assertOk();
        $this->assertNull(RentalFaultPhotoMark::withoutGlobalScope(AgencyScope::class)->withTrashed()->find($created['id'])->deleted_at);

        $types = RentalFaultReportUpdate::withoutGlobalScopes()->where('rental_fault_report_id', $fault->id)->pluck('update_type')->all();
        foreach (['photo_mark_added', 'photo_mark_changed', 'photo_mark_removed', 'photo_mark_restored'] as $t) {
            $this->assertContains($t, $types);
        }
        $history = $fault->fresh()->history()->pluck('action')->all();
        $this->assertContains('Photo mark added', $history);
        $this->assertSame($this->agent->id, RentalFaultReportUpdate::withoutGlobalScopes()->where('update_type', 'photo_mark_added')->value('created_by_user_id'));
    }

    public function test_an_arrow_is_accepted_and_bad_marks_are_refused(): void
    {
        $fault = $this->fault();
        $photo = $this->realPhoto($fault);
        $this->actingAs($this->agent)->postJson($this->marksUrl($fault, $photo), ['shape' => 'arrow', 'geometry' => ['x1' => 0.1, 'y1' => 0.1, 'x2' => 0.5, 'y2' => 0.4]])->assertCreated();
        $this->assertSame('#e11d48', RentalFaultPhotoMark::withoutGlobalScope(AgencyScope::class)->latest('id')->first()->colour, 'unknown/absent colour falls back to the default');

        $this->actingAs($this->agent)->postJson($this->marksUrl($fault, $photo), ['shape' => 'star', 'geometry' => []])->assertStatus(422);
        $this->actingAs($this->agent)->postJson($this->marksUrl($fault, $photo), $this->circle(['geometry' => ['cx' => 1.5, 'cy' => 0.5, 'r' => 0.1]]))->assertStatus(422);
        $this->actingAs($this->agent)->postJson($this->marksUrl($fault, $photo), $this->circle(['geometry' => ['cx' => 0.5, 'cy' => 0.5, 'r' => 0.0]]))->assertStatus(422);
        $this->actingAs($this->agent)->postJson($this->marksUrl($fault, $photo), ['shape' => 'arrow', 'geometry' => ['x1' => 0.2, 'y1' => 0.2, 'x2' => 0.2, 'y2' => 0.2]])->assertStatus(422);
        $this->assertSame(1, RentalFaultPhotoMark::withoutGlobalScope(AgencyScope::class)->count());
    }

    public function test_adding_marks_never_changes_the_original_photo_file(): void
    {
        $fault = $this->fault();
        $photo = $this->realPhoto($fault);
        $rel = ltrim(substr($photo->storage_path, strlen('/storage/')), '/');
        $disk = Storage::disk('public');
        $before = hash('sha256', $disk->get($rel));
        $rowBefore = $photo->fresh()->getAttributes();

        $mark = $this->actingAs($this->agent)->postJson($this->marksUrl($fault, $photo), $this->circle())->assertCreated()->json('mark');
        $this->actingAs($this->agent)->putJson($this->marksUrl($fault, $photo, "/{$mark['id']}"), $this->circle(['geometry' => ['cx' => 0.7, 'cy' => 0.2, 'r' => 0.2]]))->assertOk();
        $this->actingAs($this->agent)->putJson("/corex/rental-fault-reports/{$fault->id}/photos/{$photo->id}/note", ['description' => 'Water stain here'])->assertOk();
        // the viewer payloads (which make the small copies) must not touch it either
        app(Annotations::class)->payload($fault, $fault->photos, Annotations::AUDIENCE_STAFF);

        $this->assertSame($before, hash('sha256', $disk->get($rel)), 'the tenant\'s original file is byte-for-byte unchanged');
        $this->assertSame($rowBefore, $photo->fresh()->getAttributes(), 'and so is its row');
    }

    public function test_a_note_is_one_per_photo_edited_in_place_and_removed_softly(): void
    {
        $fault = $this->fault();
        $photo = $this->realPhoto($fault);
        $url = "/corex/rental-fault-reports/{$fault->id}/photos/{$photo->id}/note";

        $this->actingAs($this->agent)->putJson($url, ['description' => 'Rust at the joint'])->assertOk()->assertJsonPath('note.text', 'Rust at the joint');
        $this->actingAs($this->agent)->putJson($url, ['description' => 'Rust and a drip'])->assertOk();
        $this->assertSame(1, RentalFaultPhotoNote::withoutGlobalScope(AgencyScope::class)->count());
        $note = RentalFaultPhotoNote::withoutGlobalScope(AgencyScope::class)->first();
        $this->assertSame('Rust and a drip', $note->description);
        $this->assertSame($this->agent->id, $note->recorded_by_user_id);
        $this->assertNotNull($note->recorded_at);

        $this->actingAs($this->agent)->putJson($url, ['description' => '   '])->assertStatus(422);
        $this->actingAs($this->agent)->putJson($url, ['description' => str_repeat('x', 501)])->assertStatus(422);

        $this->actingAs($this->agent)->deleteJson($url)->assertOk();
        $this->assertSame(0, RentalFaultPhotoNote::withoutGlobalScope(AgencyScope::class)->count());
        $this->assertSame(1, RentalFaultPhotoNote::withoutGlobalScope(AgencyScope::class)->withTrashed()->count(), 'soft delete only');
        $this->actingAs($this->agent)->putJson($url, ['description' => 'Back again'])->assertOk();
        $this->assertSame(1, RentalFaultPhotoNote::withoutGlobalScope(AgencyScope::class)->withTrashed()->count(), 'setting it again restores the same row');

        $types = RentalFaultReportUpdate::withoutGlobalScopes()->pluck('update_type')->all();
        $this->assertContains('photo_note_set', $types);
        $this->assertContains('photo_note_removed', $types);
    }

    // ── who may write ────────────────────────────────────────────────────────────────────────

    public function test_a_user_who_may_not_edit_faults_cannot_add_marks_but_can_still_open_the_page(): void
    {
        $viewer = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'rental_fault_reports.view', 'scope' => 'all']);
        PermissionService::clearCache();
        $fault = $this->fault();
        $photo = $this->realPhoto($fault);

        $this->actingAs($viewer)->postJson($this->marksUrl($fault, $photo), $this->circle())->assertForbidden();
        $this->actingAs($viewer)->putJson("/corex/rental-fault-reports/{$fault->id}/photos/{$photo->id}/note", ['description' => 'x'])->assertForbidden();
        $this->assertSame(0, RentalFaultPhotoMark::withoutGlobalScope(AgencyScope::class)->count());

        $html = $this->actingAs($viewer)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();
        $this->assertStringContainsString('data-open-photo="' . $photo->id . '"', $html);
        $this->assertStringContainsString('var editable = false;', $html, 'a view-only user gets the viewer without the marks tools');
    }

    public function test_marks_cannot_reach_another_agencys_fault_or_another_faults_photo(): void
    {
        // another agency's staff - created BEFORE anyone is logged in (BelongsToAgency stamps the logged-in user's agency on create)
        $foreignAgency = Agency::create(['name' => 'Foreign', 'slug' => 'fg-' . uniqid()]);
        $foreignBranch = Branch::create(['agency_id' => $foreignAgency->id, 'name' => 'F', 'code' => 'F-' . $foreignAgency->id, 'is_active' => true]);
        $foreigner = User::factory()->create(['agency_id' => $foreignAgency->id, 'branch_id' => $foreignBranch->id, 'role' => 'agent']);
        // once any grant exists the allow-all fallback is off, so grant this test's own two roles exactly what they need
        foreach (['agent', 'admin'] as $role) {
            foreach (['rental_fault_reports.view', 'rental_fault_reports.create'] as $k) {
                RolePermission::create(['role' => $role, 'permission_key' => $k, 'scope' => 'all']);
            }
        }
        PermissionService::clearCache();
        $fault = $this->fault();
        $photo = $this->realPhoto($fault);

        $otherFault = $this->fault(['title' => 'Other']);
        $otherPhoto = $this->realPhoto($otherFault);
        // a photo of another fault under this fault's URL
        $this->actingAs($this->agent)->postJson($this->marksUrl($fault, $otherPhoto), $this->circle())->assertNotFound();

        // a mark of another photo under this photo's URL
        $mark = $this->actingAs($this->agent)->postJson($this->marksUrl($otherFault, $otherPhoto), $this->circle())->assertCreated()->json('mark');
        $this->actingAs($this->agent)->putJson($this->marksUrl($fault, $photo, "/{$mark['id']}"), $this->circle())->assertNotFound();
        $this->actingAs($this->agent)->deleteJson($this->marksUrl($fault, $photo, "/{$mark['id']}"))->assertNotFound();

        $this->assertContains($this->actingAs($foreigner)->postJson($this->marksUrl($fault, $photo), $this->circle())->status(), [403, 404], 'refused');
        $this->assertContains($this->actingAs($foreigner)->putJson("/corex/rental-fault-reports/{$fault->id}/photos/{$photo->id}/note", ['description' => 'x'])->status(), [403, 404], 'refused');
        $this->assertContains($this->actingAs($foreigner)->get(route('corex.rental-fault-reports.show', $fault))->status(), [403, 404], 'refused');
        $this->assertSame(1, RentalFaultPhotoMark::withoutGlobalScope(AgencyScope::class)->count(), 'nothing was written by the other agency');
        $this->assertSame(0, RentalFaultPhotoNote::withoutGlobalScope(AgencyScope::class)->count());
    }

    // ── who sees them ────────────────────────────────────────────────────────────────────────

    public function test_the_owner_sees_marks_and_notes_only_on_shared_photos_and_only_after_the_fault_was_sent(): void
    {
        $fault = $this->fault();
        $shared = $this->realPhoto($fault);
        $private = $this->realPhoto($fault);
        $svc = app(Annotations::class);
        $svc->addMark($fault, $shared, $this->circle(), $this->agent);
        $svc->setNote($fault, $shared, 'This is the leak', $this->agent);
        $svc->addMark($fault, $private, $this->circle(), $this->agent);
        $svc->setNote($fault, $private, 'Internal remark', $this->agent);

        // not sent: the service itself withholds everything from the owner
        $unsent = $svc->payload($fault->fresh(), $fault->photos, Annotations::AUDIENCE_OWNER);
        $this->assertSame([], $unsent[0]['marks']);
        $this->assertNull($unsent[0]['note']);

        $fault->saveOwnerVersion(['owner_title' => 'Tap leaks', 'owner_description' => 'Under the sink.', 'owner_photo_ids' => [$shared->id]], $this->agent);
        $fault->fresh()->requestApproval($this->agent);

        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
        $payload = $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}")->assertOk()->json('fault_report');
        $this->assertCount(1, $payload['photos'], 'only the shared photo reaches the owner');
        $this->assertSame($shared->id, $payload['photos'][0]['id']);
        $this->assertCount(1, $payload['photos'][0]['marks']);
        $this->assertSame('This is the leak', $payload['photos'][0]['note']['text']);
        $this->assertSame('Your agent', $payload['photos'][0]['note']['by'], 'the owner is not shown a staff member\'s name');
        $this->assertFalse($payload['reported_by_owner']);
        $this->assertStringNotContainsString('Internal remark', json_encode($payload));
        $this->assertNotEmpty($payload['photos'][0]['thumb_url']);
    }

    public function test_the_tenant_sees_only_their_own_photos_and_marks_only_when_the_agency_allows_it(): void
    {
        $fault = $this->fault();
        $mine = $this->realPhoto($fault);                          // came in through the portal: no staff uploader
        $agentsOwn = $this->realPhoto($fault, $this->agent->id);   // an agent's site photo
        $svc = app(Annotations::class);
        $svc->addMark($fault, $mine, $this->circle(), $this->agent);
        $svc->setNote($fault, $mine, 'Look here', $this->agent);

        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);
        $row = $this->getJson('/api/v1/client/rentals/fault-reports')->assertOk()->json('fault_reports.0');
        $this->assertCount(1, $row['reported_photos'], 'the tenant\'s own photo only');
        $this->assertSame($mine->id, $row['reported_photos'][0]['id']);
        $this->assertSame([], $row['reported_photos'][0]['marks'], 'default: the tenant does not see the agent\'s marks');
        $this->assertNull($row['reported_photos'][0]['note']);

        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['tenant_sees_agent_marks' => true]);
        $row = $this->getJson('/api/v1/client/rentals/fault-reports')->assertOk()->json('fault_reports.0');
        $this->assertCount(1, $row['reported_photos'][0]['marks']);
        $this->assertSame('Look here', $row['reported_photos'][0]['note']['text']);
        $this->assertNotContains($agentsOwn->id, array_column($row['reported_photos'], 'id'));
    }

    public function test_the_contractor_and_crew_see_the_marks_and_notes_of_the_photos_the_owner_saw(): void
    {
        [$fault, $shared, $private] = $this->sentFaultWithTwoPhotos();
        $svc = app(Annotations::class);
        $svc->addMark($fault, $shared, $this->circle(), $this->agent);
        $svc->setNote($fault, $shared, 'Fix this joint', $this->agent);
        $svc->setNote($fault, $private, 'Not for the crew', $this->agent);

        $card = new RentalJobCard();
        $card->forceFill(['agency_id' => $this->agency->id, 'rental_fault_report_id' => $fault->id]);
        $list = $svc->forJobCard($card);

        $this->assertCount(1, $list, 'the photos the work order starts with (the owner version)');
        $this->assertCount(1, $list[0]['marks']);
        $this->assertSame('Fix this joint', $list[0]['note']['text']);
        $this->assertStringNotContainsString('Not for the crew', json_encode($list));
        $this->assertSame([], $svc->forJobCard((new RentalJobCard())->forceFill(['agency_id' => $this->agency->id])), 'a job with no fault shows nothing');
    }

    // ── the staff page + settings + payload shapes ───────────────────────────────────────────

    public function test_the_staff_page_opens_photos_in_the_viewer_not_a_new_tab_and_carries_the_marks(): void
    {
        $fault = $this->fault();
        $photo = $this->realPhoto($fault);
        app(Annotations::class)->addMark($fault, $photo, $this->circle(), $this->agent);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();
        $this->assertStringContainsString('data-photo-viewer', $html);
        $this->assertStringContainsString('window.CorexPhotoViewer', $html);
        $this->assertStringContainsString('data-open-photo="' . $photo->id . '"', $html);
        $this->assertStringNotContainsString('target="_blank"><img src="' . $photo->storage_path, $html, 'no photo link opens a new tab any more');
        $this->assertStringContainsString('var editable = true;', $html);
        $this->assertMatchesRegularExpression('/id="fault-photo-data">.*"shape":"circle"/s', $html);
    }

    public function test_the_tenant_sees_agent_marks_setting_is_off_by_default_and_saves_has_guarded(): void
    {
        $this->assertFalse(RentalPortalSetting::tenantSeesAgentMarksFor($this->agency->id));

        $this->actingAs($this->agent)->post('/corex/settings/rental-portal/tenant-sees-agent-marks', ['tenant_sees_agent_marks' => '1'])->assertRedirect();
        $this->assertTrue(RentalPortalSetting::tenantSeesAgentMarksFor($this->agency->id));

        // the wizard step posts a subset: a post WITHOUT the field must not wipe it
        $this->actingAs($this->agent)->post('/corex/settings/rental-portal/tenant-sees-agent-marks', [])->assertRedirect();
        $this->assertTrue(RentalPortalSetting::tenantSeesAgentMarksFor($this->agency->id));

        $this->actingAs($this->agent)->post('/corex/settings/rental-portal/tenant-sees-agent-marks', ['tenant_sees_agent_marks' => '0'])->assertRedirect();
        $this->assertFalse(RentalPortalSetting::tenantSeesAgentMarksFor($this->agency->id));
    }

    public function test_the_setting_is_surfaced_in_the_setup_wizard(): void
    {
        $steps = config('agency-onboarding-copy');
        $json = json_encode($steps);
        $this->assertStringContainsString('tenant_sees_agent_marks', $json);
        $this->assertStringContainsString('updateTenantSeesAgentMarks', json_encode(array_map(fn ($x) => $x, $this->flattenSavers($steps))));
    }

    /** @return array<int,mixed> every 'method' => name in the wizard config */
    private function flattenSavers(array $node): array
    {
        $out = [];
        array_walk_recursive($node, function ($v, $k) use (&$out) {
            if ($k === 'method') {
                $out[] = $v;
            }
        });

        return $out;
    }

    public function test_try_this_first_splits_the_safety_line_and_the_steps_without_changing_the_words(): void
    {
        $view = app(RentalFaultTypePortalView::class);
        $text = RentalFaultType::SAFETY_LINE . "\n\nYour main water valve is at: Kitchen cupboard. Close it now. Turn off any electrical appliances near the water.";
        $out = $view->splitSteps($text);
        $this->assertSame(RentalFaultType::SAFETY_LINE, $out['safety_line']);
        $this->assertSame(['Your main water valve is at: Kitchen cupboard.', 'Close it now.', 'Turn off any electrical appliances near the water.'], $out['steps']);

        $multi = $view->splitSteps("1. Switch off the geyser.\n2) Call the office.\n- Wait.");
        $this->assertNull($multi['safety_line']);
        $this->assertSame(['Switch off the geyser.', 'Call the office.', 'Wait.'], $multi['steps']);

        $this->assertSame([], $view->splitSteps('')['steps']);

        // joined back with spaces the words are the originals
        $this->assertSame('Close the valve. Dry the area.', implode(' ', $view->splitSteps("Close the valve. Dry the area.")['steps']));
    }

    public function test_the_fault_types_payload_carries_the_split_and_the_whole_text(): void
    {
        $payload = app(RentalFaultTypePortalView::class)->payload(collect([$this->faultType]), $this->property)[0];
        $this->assertSame(RentalFaultType::SAFETY_LINE, $payload['safety_line']);
        $this->assertSame(['Close the valve.', 'Dry the area.'], $payload['steps']);
        $this->assertStringContainsString('Close the valve.', $payload['first_aid_steps']);
    }

    public function test_the_owner_payload_says_whether_the_owner_reported_it_themselves(): void
    {
        $fault = $this->fault(['reported_by_type' => RentalFaultReport::REPORTED_BY_LANDLORD, 'reported_by_contact_id' => $this->landlord->id, 'title' => 'Owner raised']);
        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
        $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}")->assertOk()->assertJsonPath('fault_report.reported_by_owner', true);
    }

    // ── item 9: "No contractor loaded for <type>" ────────────────────────────────────────────

    public function test_the_agent_warning_and_the_owner_note_use_the_same_test(): void
    {
        [$fault] = $this->sentFaultWithTwoPhotos();
        $svc = app(RentalFaultContractorService::class);

        $this->assertFalse($svc->hasContractorFor($fault));
        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
        $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}")->assertJsonPath('fault_report.has_contractor', false);

        $this->supplier('Drip Plumbing', 'Plumbing');
        $this->assertTrue($svc->hasContractorFor($fault));
        $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}")->assertJsonPath('fault_report.has_contractor', true);
    }

    public function test_the_fault_page_warns_before_sending_with_both_controls_and_the_re_check_clears_it(): void
    {
        $fault = $this->fault();
        $this->realPhoto($fault);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();
        $this->assertStringContainsString('data-no-contractor-warning', $html);
        $this->assertStringContainsString('No contractor loaded for Leaking tap.', $html);
        $this->assertStringContainsString('Add a contractor', $html);
        $this->assertStringContainsString('Add Leaking tap to an existing contractor', $html);
        $this->assertStringContainsString('data-contractor-popup="add"', $html, 'the add paths open a popup over the fault, they do not navigate away');
        $this->assertStringContainsString('data-fault-id="' . $fault->id . '"', $html);
        $this->assertStringNotContainsString('href="' . route('deals-v2.suppliers.index', ['fault' => $fault->id, 'mode' => 'add']) . '"', $html);
        $this->assertStringContainsString('Send without a contractor for Leaking tap?', $html, 'the app\'s own confirm dialog, not a browser box');
        $this->assertStringNotContainsString('confirm(', explode('id="owner-version-card"', $html)[1] ?? '');

        $this->supplier('Drip Plumbing', 'Plumbing');
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-no-contractor-warning', $html, 'covered now: the warning is gone');
        $this->assertStringNotContainsString('Send without a contractor', $html);
        $this->assertStringContainsString('Send this to the owner now?', $html);
    }

    public function test_the_two_controls_show_only_to_users_who_may_manage_suppliers(): void
    {
        $limited = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        foreach (['rental_fault_reports.view', 'rental_fault_reports.create', 'rental_fault_reports.send_to_owner'] as $k) {
            RolePermission::create(['role' => 'agent', 'permission_key' => $k, 'scope' => 'all']);
        }
        PermissionService::clearCache();
        $fault = $this->fault();

        $html = $this->actingAs($limited)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();
        $this->assertStringContainsString('No contractor loaded for Leaking tap.', $html);
        $this->assertStringNotContainsString('data-no-contractor-add', $html);
        $this->assertStringNotContainsString('data-no-contractor-types', $html);
    }

    public function test_sending_while_uncovered_still_works_and_writes_who_and_when_to_the_history(): void
    {
        $fault = $this->fault();
        $fault->saveOwnerVersion(['owner_title' => 'Tap leaks', 'owner_description' => 'x'], $this->agent);

        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.send-to-owner', $fault), ['send_without_contractor' => '1'])->assertRedirect();

        $this->assertNotNull($fault->fresh()->sent_to_owner_at, 'sending stays allowed');
        $row = RentalFaultReportUpdate::withoutGlobalScopes()->where('update_type', 'sent_without_contractor')->first();
        $this->assertNotNull($row);
        $this->assertSame($this->agent->id, $row->created_by_user_id);
        $this->assertNotNull($row->created_at);
        $this->assertStringContainsString('Leaking tap', $row->note);
        $this->assertStringContainsString('confirmed in the dialog', $row->note);
        $this->assertContains('Sent to owner without a contractor', $fault->fresh()->history()->pluck('action')->all());
    }

    public function test_sending_when_covered_writes_no_without_contractor_line(): void
    {
        $this->supplier('Drip Plumbing', 'Plumbing');
        $fault = $this->fault();
        $fault->saveOwnerVersion(['owner_title' => 'Tap leaks', 'owner_description' => 'x'], $this->agent);

        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.send-to-owner', $fault))->assertRedirect();

        $this->assertNotNull($fault->fresh()->sent_to_owner_at);
        $this->assertSame(0, RentalFaultReportUpdate::withoutGlobalScopes()->where('update_type', 'sent_without_contractor')->count());
    }

    public function test_the_supplier_directory_brings_the_agent_back_to_the_fault_and_re_checks(): void
    {
        // a second agency's user, created before anyone is logged in
        $foreign = Agency::create(['name' => 'Foreign2', 'slug' => 'f2-' . uniqid()]);
        $other = User::factory()->create(['agency_id' => $foreign->id, 'role' => 'admin']);
        $fault = $this->fault();
        $url = route('deals-v2.suppliers.index', ['fault' => $fault->id, 'mode' => 'add']);

        $html = $this->actingAs($this->agent)->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('data-return-to-fault', $html);
        $this->assertStringContainsString(route('corex.rental-fault-reports.show', $fault), $html);
        $this->assertStringNotContainsString('data-fault-now-covered', $html);
        $this->assertStringContainsString('showAdd: true', $html, 'the add form is open');

        $this->supplier('Drip Plumbing', 'Plumbing');
        $this->assertStringContainsString('data-fault-now-covered', $this->actingAs($this->agent)->get($url)->getContent(), 'the directory re-checks on the way back');

        // another agency's fault id is simply not offered
        $this->assertStringNotContainsString('data-return-to-fault', $this->actingAs($other)->get($url)->assertOk()->getContent());
    }
}
