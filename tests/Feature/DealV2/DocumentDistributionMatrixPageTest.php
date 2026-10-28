<?php

namespace Tests\Feature\DealV2;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\DealV2\DealStageDocumentRule;
use App\Models\DocumentDistributionAuditEntry;
use App\Models\DocumentType;
use App\Models\RolePermission;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\DealV2\DocumentDistributionDefaults;
use App\Services\DealV2\DocumentDistributionMatrix;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** signed-document-distribution.md "The settings screen" - the simple matrix: render, save, defaults, audit, one source of truth. */
class DocumentDistributionMatrixPageTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    private array $t = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'cr-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'name' => 'Admin Anna']);
        foreach (['otp' => 'OTP (Offer to Purchase)', 'fica' => 'FICA', 'mandate' => 'Mandate', 'ids' => 'IDs / Identity', 'sale_agreement' => 'Sale Agreement', 'bond_grant' => 'Bond Grant'] as $slug => $label) {
            $this->t[$slug] = DocumentType::firstOrCreate(['slug' => $slug], ['label' => $label, 'sort_order' => count($this->t) + 1, 'is_active' => true])->id;
        }
    }

    private function allow(array $keys = ['deals_v2.manage_distribution_rules']): void
    {
        foreach ($keys as $k) {
            RolePermission::create(['role' => $this->admin->role, 'permission_key' => $k, 'scope' => 'agency']);
        }
        PermissionService::clearCache();
        $this->actingAs($this->admin);
    }

    private function rules(string $role, ?int $agencyId = null): array
    {
        return DealStageDocumentRule::withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId ?? $this->agency->id)
            ->whereNull('pipeline_step_id')->where('party_role', $role)->where('is_active', true)->pluck('document_type_id')->map(fn ($i) => (int) $i)->sort()->values()->all();
    }

    private function savePost(array $cells, array $extra = ['grid' => '1'])
    {
        return $this->from('/back')->post(route('admin.settings.document-distribution.save'), $extra + ['cell' => $cells]);
    }

    public function test_the_page_is_permission_gated_and_shows_every_type_and_party(): void
    {
        RolePermission::create(['role' => $this->admin->role, 'permission_key' => 'deals_v2.view', 'scope' => 'agency']);
        PermissionService::clearCache();
        $this->actingAs($this->admin)->get(route('admin.settings.document-distribution'))->assertForbidden();

        $this->allow();
        $res = $this->get(route('admin.settings.document-distribution'))->assertOk();
        foreach (['Deal Document Distribution', 'Seller', 'Buyer', 'Transferring Attorney', 'Bond Attorney', 'Bond Originator', 'Conveyancer', 'External Agency', 'FICA', 'Bond Grant'] as $needle) {
            $res->assertSee($needle);
        }
        $res->assertSee('data-dd-frame', false)->assertSee('Find a document type', false)->assertSee('Restore standard set-up')
            ->assertSee('Review &amp; save', false)->assertSee('No changes saved yet');
    }

    public function test_ticks_come_from_the_type_level_rules(): void
    {
        $this->allow();
        app(DocumentDistributionMatrix::class)->setTypeDistribution($this->agency->id, $this->t['fica'], ['transfer_attorney', 'buyer']);

        $res = $this->get(route('admin.settings.document-distribution'));
        $cells = $res->viewData('cells');
        $this->assertEqualsCanonicalizing(['transfer_attorney' => true, 'buyer' => true], $cells[$this->t['fica']]);
        $this->assertSame(['seller', 'buyer', 'transfer_attorney', 'bond_attorney', 'bond_originator', 'conveyancer', 'external_agency'], array_keys($res->viewData('columns')));
    }

    public function test_saving_adds_and_removes_cells_keeps_delivery_mode_and_writes_one_audit_row(): void
    {
        $this->allow();
        $m = app(DocumentDistributionMatrix::class);
        $m->setTypeDistribution($this->agency->id, $this->t['otp'], ['seller', 'buyer']);
        DealStageDocumentRule::withoutGlobalScope(AgencyScope::class)->where('agency_id', $this->agency->id)->where('party_role', 'seller')
            ->update(['delivery_mode' => 'direct_attachment']);

        // untick buyer on OTP, tick transfer attorney on OTP, tick conveyancer on FICA; seller stays ticked
        $this->savePost([
            $this->t['otp']  => ['seller' => '1', 'transfer_attorney' => '1'],
            $this->t['fica'] => ['conveyancer' => '1'],
        ])->assertRedirect('/back')->assertSessionHas('success');

        $this->assertSame([$this->t['otp']], $this->rules('seller'));
        $this->assertSame([], $this->rules('buyer'));
        $this->assertSame([$this->t['otp']], $this->rules('transfer_attorney'));
        $this->assertSame([$this->t['fica']], $this->rules('conveyancer'));
        $this->assertSame('direct_attachment', DealStageDocumentRule::withoutGlobalScope(AgencyScope::class)->where('agency_id', $this->agency->id)
            ->where('party_role', 'seller')->where('document_type_id', $this->t['otp'])->value('delivery_mode'), 'an untouched cell keeps how it is delivered');

        $audit = DocumentDistributionAuditEntry::where('agency_id', $this->agency->id)->get();
        $this->assertCount(1, $audit);
        $this->assertSame($this->admin->id, $audit[0]->user_id);
        $this->assertSame('2 added, 1 removed', $audit[0]->summary);
        $this->assertContains('Added: FICA - Conveyancer', $audit[0]->changes);
        $this->assertContains('Removed: OTP (Offer to Purchase) - Buyer', $audit[0]->changes);

        // the same grid again changes nothing and writes nothing
        $this->savePost([$this->t['otp'] => ['seller' => '1', 'transfer_attorney' => '1'], $this->t['fica'] => ['conveyancer' => '1']])->assertSessionHas('success', 'Nothing was changed.');
        $this->assertCount(1, DocumentDistributionAuditEntry::where('agency_id', $this->agency->id)->get());
    }

    public function test_stage_rules_and_other_agencies_are_never_touched(): void
    {
        // the other agency's rule is made BEFORE anyone is signed in (an authenticated create is stamped with the signed-in user's agency)
        $other = Agency::create(['name' => 'Other Realty', 'slug' => 'or-' . uniqid()]);
        app(DocumentDistributionMatrix::class)->setTypeDistribution($other->id, $this->t['otp'], ['buyer']);
        $this->allow();
        $step = DB::table('deal_pipeline_templates')->insertGetId(['agency_id' => $this->agency->id, 'name' => 'T', 'is_default' => 1, 'created_by_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $stepId = DB::table('deal_pipeline_steps')->insertGetId(['pipeline_template_id' => $step, 'agency_id' => $this->agency->id, 'name' => 'Documents Signed', 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $stage = DealStageDocumentRule::create(['agency_id' => $this->agency->id, 'pipeline_step_id' => $stepId, 'document_type_id' => $this->t['fica'], 'party_role' => 'buyer',
            'delivery_mode' => 'secure_link', 'auto_on_stage_tick' => true, 'is_active' => true]);

        $this->get(route('admin.settings.document-distribution'))->assertSee('Documents Signed');   // the dot's tooltip names the stage
        $this->savePost([$this->t['otp'] => ['seller' => '1']])->assertSessionHas('success');

        $this->assertTrue($stage->fresh()->is_active, 'a stage-scoped rule is not touched by the matrix');
        $this->assertSame([$this->t['otp']], $this->rules('buyer', $other->id), 'another agency is not touched');
    }

    public function test_a_post_without_the_grid_marker_cannot_wipe_the_matrix(): void
    {
        $this->allow();
        app(DocumentDistributionMatrix::class)->setTypeDistribution($this->agency->id, $this->t['otp'], ['seller']);

        $this->from('/back')->post(route('admin.settings.document-distribution.save'), [])->assertSessionHasErrors('grid');
        $this->assertSame([$this->t['otp']], $this->rules('seller'));
    }

    public function test_saving_needs_the_permission(): void
    {
        RolePermission::create(['role' => $this->admin->role, 'permission_key' => 'deals_v2.view', 'scope' => 'agency']);
        PermissionService::clearCache();
        $this->actingAs($this->admin);
        $this->savePost([$this->t['otp'] => ['seller' => '1']])->assertForbidden();
        $this->assertSame([], $this->rules('seller'));
    }

    public function test_the_standard_set_up_is_the_shipped_defaults_in_one_place(): void
    {
        $this->allow();
        $res = $this->get(route('admin.settings.document-distribution'));
        $defaults = $res->viewData('defaults');

        $this->assertEqualsCanonicalizing(['seller', 'buyer', 'bond_originator', 'transfer_attorney', 'bond_attorney', 'conveyancer'], $defaults[$this->t['otp']]);
        $this->assertSame(['bond_attorney'], $defaults[$this->t['bond_grant']]);
        $this->assertNotContains('bond_attorney', $defaults[$this->t['mandate']]);
        $res->assertSee('restoreDefaults', false);
    }

    public function test_the_portal_and_the_email_button_read_the_same_matrix(): void
    {
        $this->allow();
        $m = app(DocumentDistributionMatrix::class);
        $m->setTypeDistribution($this->agency->id, $this->t['fica'], ['transfer_attorney']);

        $this->assertSame([$this->t['fica']], $m->typesForParty($this->agency->id, 'transfer_attorney')->pluck('id')->all());

        // untick it on the page: both consumers' source (typesForParty) loses it at once - there is no second list
        $this->savePost([$this->t['fica'] => []])->assertSessionHas('success');
        $this->assertSame([], $m->typesForParty($this->agency->id, 'transfer_attorney')->pluck('id')->all());

        // and the portal's document service reads exactly that query
        $src = file_get_contents(app_path('Services/AttorneyPortal/AttorneyPortalDocuments.php'));
        $this->assertStringContainsString('->typesForParty(', $src);
        $this->assertStringNotContainsString("'fica'", $src, 'the portal carries no document-type list of its own beyond the deal-only scoping slugs');
    }

    public function test_the_layout_fits_without_page_scroll_by_design(): void
    {
        $this->allow();
        $html = $this->get(route('admin.settings.document-distribution'))->getContent();
        $this->assertStringContainsString('.dd-page { height: 100%; display: flex; flex-direction: column;', $html);
        $this->assertStringContainsString('.dd-frame { flex: 1 1 auto; min-height: 0; overflow: auto;', $html);
        $this->assertStringContainsString('position: sticky; top: 0', $html);       // pinned party row
        $this->assertStringContainsString('position: sticky; left: 0', $html);      // pinned document column
    }
}
