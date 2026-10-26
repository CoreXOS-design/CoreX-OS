<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ComplianceGateChange;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Property;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Compliance\AgencyComplianceDocTypeService;
use App\Services\Compliance\MarketingReadinessService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FindsOrCreatesDocumentTypes;
use Tests\TestCase;

/**
 * AT-509 — Compliance Switches (.ai/specs/property-compliance-gate-settings.md §12).
 *
 * An agency switches property marketing-compliance checks on/off. Switching off needs the disclaimer
 * (enforced server-side), every change is recorded with who/when/IP/exact text, a listing that went live
 * while a check was off keeps saying so after it is switched back on, a not-yet-live listing is checked again
 * (Q1), and nothing leaks across agencies.
 */
final class ComplianceGateSettingsTest extends TestCase
{
    use RefreshDatabase;
    use FindsOrCreatesDocumentTypes;

    private Agency $agency;
    private Agency $other;
    private Branch $branch;
    private User $admin;
    private array $typeIds = [];
    private string $version;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Seaview Realty', 'slug' => 'seaview-' . uniqid()]);
        $this->other  = Agency::create(['name' => 'Inland Lettings', 'slug' => 'inland-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);

        foreach (['mandate' => ['Mandate', 'shared'], 'fica' => ['FICA', 'contact'], 'disclosure' => ['Disclosure', 'shared']] as $slug => [$label, $grouping]) {
            $this->typeIds[$slug] = $this->documentTypeId($slug, $label, ['grouping' => $grouping]);
        }

        $svc = new AgencyComplianceDocTypeService();
        foreach ([$this->agency, $this->other] as $a) {
            foreach ($this->typeIds as $id) {
                $svc->setRequired($a->id, $id, true);
            }
        }

        Role::create(['name' => 'admin', 'label' => 'Administrator', 'agency_id' => $this->agency->id]);
        foreach (['compliance_switches.manage', 'access_settings'] as $key) {
            RolePermission::updateOrCreate(['role' => 'admin', 'permission_key' => $key, 'agency_id' => $this->agency->id], []);
        }
        Role::clearCache();
        PermissionService::clearCache();

        $this->admin = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true,
        ]);

        $this->version = (string) config('corex-compliance.gate_disclaimer.version');
    }

    // ── helpers ──

    private function listing(?Agency $agency = null): Property
    {
        $agency ??= $this->agency;

        return Property::create([
            'title' => 'Switch Test', 'agency_id' => $agency->id, 'agent_id' => $this->admin->id,
            'branch_id' => $this->branch->id, 'listing_type' => 'sale',
            'address' => '3 Beach Road', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo', 'town' => 'Uvongo',
            'province' => 'KwaZulu-Natal', 'price' => 1450000, 'property_type' => 'House', 'erf_size_m2' => 700,
            'gallery_images_json' => ['a.jpg', 'b.jpg', 'c.jpg', 'd.jpg'],
        ]);
    }

    /** Mandate + disclosure on file and a seller WITHOUT approved FICA — only FICA blocks. */
    private function listingBlockedOnlyByFica(?Agency $agency = null): Property
    {
        $agency ??= $this->agency;
        $p = $this->listing($agency);
        $contact = Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->admin->id,
            'first_name' => 'Lerato', 'last_name' => 'Dlamini', 'phone' => '0821234567',
        ]);
        $p->contacts()->attach($contact->id, ['role' => 'seller']);
        foreach (['mandate', 'disclosure'] as $slug) {
            $doc = Document::create([
                'original_name' => $slug . '.pdf', 'storage_path' => 'docs/' . $slug . uniqid() . '.pdf', 'disk' => 'local',
                'mime_type' => 'application/pdf', 'size' => 10, 'document_type_id' => $this->typeIds[$slug],
                'source_type' => 'upload', 'uploaded_by' => $this->admin->id,
            ]);
            $doc->properties()->attach($p->id);
        }

        return $p;
    }

    private function save(array $gates, ?string $accepted = null)
    {
        return $this->actingAs($this->admin)
            ->from(route('corex.settings', ['s' => 'compliance-switches']))
            ->withServerVariables(['REMOTE_ADDR' => '41.13.250.7'])
            ->post(route('corex.settings.compliance-switches.update'), array_filter([
                'compliance_switches_submitted' => '1',
                'gates' => $gates,
                'compliance_disclaimer_accepted' => $accepted,
            ], fn ($v) => $v !== null));
    }

    private function readiness(Property $p)
    {
        return app(MarketingReadinessService::class)->statusFor($p->fresh());
    }

    // ── tests ──

    public function test_switching_off_without_the_disclaimer_is_refused_and_changes_nothing(): void
    {
        $this->save(['doc:fica' => '0'])->assertSessionHasErrors('compliance_disclaimer');

        $this->assertSame(0, ComplianceGateChange::count());
        $this->assertFalse($this->readiness($this->listingBlockedOnlyByFica())->ready);
    }

    public function test_switching_fica_off_unblocks_and_records_who_when_ip_and_exact_text(): void
    {
        $p = $this->listingBlockedOnlyByFica();
        $this->assertFalse($this->readiness($p)->ready);

        $this->save(['doc:fica' => '0'], $this->version)->assertSessionHasNoErrors();

        $report = $this->readiness($p);
        $this->assertTrue($report->ready);
        $this->assertTrue($report->checklist['fica']['switched_off']);

        $row = ComplianceGateChange::sole();
        $this->assertSame('doc:fica', $row->gate_key);
        $this->assertSame('switched_off', $row->action);
        $this->assertSame($this->admin->id, $row->user_id);
        $this->assertSame($this->admin->email, $row->user_email);
        $this->assertSame('41.13.250.7', $row->ip_address);
        $this->assertSame($this->version, $row->disclaimer_version);
        $this->assertNotNull($row->disclaimer_accepted_at);
        $this->assertStringContainsString('Seaview Realty', $row->disclaimer_text);
        $this->assertStringContainsString(': FICA.', $row->disclaimer_text);
        $this->assertStringContainsString('POPIA', $row->disclaimer_text);
    }

    public function test_another_agency_is_not_affected(): void
    {
        $this->save(['doc:fica' => '0'], $this->version);

        // Records are stamped with the acting user's agency; the test vouches for the other agency explicitly.
        $listing = Property::withoutAgencyStamping(fn () => Contact::withoutAgencyStamping(fn () => Document::withoutAgencyStamping(
            fn () => $this->listingBlockedOnlyByFica($this->other)
        )));

        $this->assertSame($this->other->id, (int) $listing->agency_id);
        $report = $this->readiness($listing);
        $this->assertFalse($report->ready);
        $this->assertFalse($report->checklist['fica']['passed']);
        $this->assertArrayNotHasKey('switched_off', $report->checklist['fica']);
    }

    public function test_live_listing_keeps_its_go_live_state_and_drafts_are_checked_again_when_switched_back_on(): void
    {
        $this->save(['doc:fica' => '0'], $this->version);

        $live = $this->listingBlockedOnlyByFica();
        app(MarketingReadinessService::class)->snapshotCompliance($live->fresh(), $this->admin);
        $draft = $this->listingBlockedOnlyByFica(); // captured while off, NOT live

        $this->save(['doc:fica' => '1'])->assertSessionHasNoErrors(); // switching ON needs no disclaimer

        $live = $live->fresh();
        $this->assertSame(3, $live->compliance_snapshot_data['snapshot_version']);
        $this->assertSame('doc:fica', $live->compliance_snapshot_data['gates_off_at_go_live'][0]['key']);
        $liveReport = $this->readiness($live);
        $this->assertTrue($liveReport->ready);
        $this->assertTrue($liveReport->checklist['fica']['switched_off']);

        $this->assertFalse($this->readiness($draft)->ready, 'Q1: a not-yet-live listing must pass a check switched back on');
        $this->assertFalse($this->readiness($this->listingBlockedOnlyByFica())->ready);

        $this->assertSame(['switched_off', 'switched_on'], ComplianceGateChange::orderBy('id')->pluck('action')->all());
    }

    public function test_master_off_unblocks_everything_and_master_on_restores_previous_positions(): void
    {
        $bare = $this->listing(); // no mandate, disclosure or seller
        $this->assertFalse($this->readiness($bare)->ready);

        $this->save(['master' => '0'], $this->version)->assertSessionHasNoErrors();
        $report = $this->readiness($bare);
        $this->assertTrue($report->ready);
        $this->assertArrayHasKey('compliance_switched_off', $report->checklist);
        $this->assertSame([], app(MarketingReadinessService::class)->complianceChecklistFor($bare->fresh()));

        $this->save(['master' => '1']);
        $this->assertFalse($this->readiness($bare)->ready);
        $this->assertSame(2, ComplianceGateChange::where('gate_key', 'master')->count());
    }

    public function test_photos_switch_off_is_a_grey_row_not_a_block(): void
    {
        $this->save(['photos' => '0'], $this->version);
        $p = $this->listingBlockedOnlyByFica();
        $p->update(['gallery_images_json' => []]);

        $report = $this->readiness($p);
        $this->assertTrue($report->checklist['photos']['passed']);
        $this->assertTrue($report->checklist['photos']['switched_off']);
    }

    public function test_saver_does_nothing_without_its_marker_so_the_wizard_cannot_wipe_it(): void
    {
        $this->actingAs($this->admin)->post(route('corex.settings.compliance-switches.update'), ['gates' => ['doc:fica' => '0']]);

        $this->assertSame(0, ComplianceGateChange::count());
    }

    public function test_document_types_page_can_no_longer_switch_a_check_off(): void
    {
        $this->assertTrue((new AgencyComplianceDocTypeService())->complianceMapFor($this->agency->id)[$this->typeIds['fica']]);

        RolePermission::updateOrCreate(['role' => 'admin', 'permission_key' => 'access_pdf_splitter', 'agency_id' => $this->agency->id], []);
        PermissionService::clearCache();
        $type = \App\Models\SplitterDocType::find($this->typeIds['fica']);
        $this->actingAs($this->admin)->post(route('admin.splitter.doc-types.bulk-save'), ['types' => [[
            'id' => $type->id, 'label' => $type->label, 'sort_order' => $type->sort_order ?? 0, 'is_active' => '1',
        ]]]);

        $this->assertTrue((new AgencyComplianceDocTypeService())->complianceMapFor($this->agency->id)[$this->typeIds['fica']]);
        $this->assertSame(0, ComplianceGateChange::count());
    }

    public function test_settings_page_has_the_section_for_a_permitted_user_only(): void
    {
        // The Settings page is several MB of HTML; plain contains-checks keep a failure message readable.
        $page = $this->actingAs($this->admin)->get(route('corex.settings', ['s' => 'compliance-switches']));
        $this->assertSame(200, $page->getStatusCode());
        $html = $page->getContent();
        $this->assertTrue(str_contains($html, "activeSection === 'compliance-switches'"), 'section missing');
        $this->assertTrue(str_contains($html, 'compliance_switches_submitted'), 'form missing');
        $this->assertTrue(str_contains($html, 'Compliance check before marketing'), 'master switch missing');

        // Someone who can open Settings but does not hold the key sees no section and cannot save.
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'access_settings', 'agency_id' => $this->agency->id], []);
        Role::clearCache();
        PermissionService::clearCache();
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true]);

        $agentPage = $this->actingAs($agent)->get(route('corex.settings'));
        $this->assertSame(200, $agentPage->getStatusCode());
        $this->assertFalse(str_contains($agentPage->getContent(), 'compliance_switches_submitted'), 'section shown without the key');
        $this->actingAs($agent)->post(route('corex.settings.compliance-switches.update'), [
            'compliance_switches_submitted' => '1', 'gates' => ['doc:fica' => '0'], 'compliance_disclaimer_accepted' => $this->version,
        ])->assertForbidden();
        $this->assertSame(0, ComplianceGateChange::count());
    }

    public function test_dev_settings_pages_list_every_agency_and_the_history_for_the_system_owner_only(): void
    {
        $this->save(['doc:fica' => '0'], $this->version);

        Role::forceCreate(['name' => 'test-owner', 'label' => 'Owner', 'agency_id' => null, 'is_owner' => true]);
        Role::clearCache();
        $owner = User::factory()->create(['agency_id' => null, 'branch_id' => null, 'role' => 'test-owner', 'is_active' => true]);

        $this->actingAs($owner)->get(route('admin.dev-settings.compliance-switches'))
            ->assertOk()->assertSee('Seaview Realty')->assertSee('Inland Lettings')->assertSee('Accepted by');
        // The owner's layout lists every agency (switcher), so check the table rows themselves.
        $this->actingAs($owner)->get(route('admin.dev-settings.compliance-switches', ['off' => 'any']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => collect($rows->items())->pluck('name')->all() === ['Seaview Realty']);
        $this->actingAs($owner)->get(route('admin.dev-settings.compliance-switches.history'))
            ->assertOk()->assertSee('41.13.250.7')->assertSee('Switched off');
        $this->actingAs($owner)->get(route('admin.dev-settings.compliance-switches.history', ['q' => 'nobody-matches']))
            ->assertOk()->assertSee('No compliance switches have ever been changed.');
        $csv = $this->actingAs($owner)->get(route('admin.dev-settings.compliance-switches.history.export'));
        $csv->assertOk();
        $this->assertStringContainsString('41.13.250.7', $csv->streamedContent());

        $this->actingAs($this->admin)->get(route('admin.dev-settings.compliance-switches'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.dev-settings.compliance-switches.history'))->assertForbidden();
    }

    public function test_setup_wizard_compliance_step_shows_the_switches_and_enforces_the_disclaimer(): void
    {
        RolePermission::updateOrCreate(['role' => 'admin', 'permission_key' => 'agency_setup.run', 'agency_id' => $this->agency->id], []);
        PermissionService::clearCache();

        $this->actingAs($this->admin)->get(route('corex.agency-setup.step', ['step' => 'compliance']))
            ->assertOk()->assertSee('Compliance checks before marketing')->assertSee('compliance_switches_submitted', false);

        $this->actingAs($this->admin)->post(route('corex.agency-setup.step.save', ['step' => 'compliance']), [
            'compliance_switches_submitted' => '1',
            'gates' => ['doc:fica' => '0'],
        ])->assertSessionHasErrors('compliance_disclaimer');

        $this->assertSame(0, ComplianceGateChange::count());
        $this->assertTrue((new AgencyComplianceDocTypeService())->complianceMapFor($this->agency->id)[$this->typeIds['fica']]);
    }

    public function test_history_rows_cannot_be_edited_or_deleted(): void
    {
        $this->save(['doc:fica' => '0'], $this->version);
        $row = ComplianceGateChange::sole();

        $this->expectException(\LogicException::class);
        $row->update(['ip_address' => '1.1.1.1']);
    }
}
