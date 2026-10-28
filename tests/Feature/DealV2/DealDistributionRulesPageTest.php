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
use App\Services\DealV2\DocumentDistributionMatrix;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** signed-document-distribution.md "The rules screen" - list / search / filter / sort / pages, add, edit, archive, restore, duplicates, audit, scoping. */
class DealDistributionRulesPageTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    private array $t = [];
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'cr-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'name' => 'Admin Anna']);
        foreach (['otp' => 'OTP (Offer to Purchase)', 'fica' => 'FICA', 'mandate' => 'Mandate'] as $slug => $label) {
            $this->t[$slug] = DocumentType::firstOrCreate(['slug' => $slug], ['label' => $label, 'sort_order' => count($this->t) + 1, 'is_active' => true])->id;
        }
        $this->s = $this->stages($this->agency->id, ['Offer Accepted', 'Documents Signed', 'Lodged']);
    }

    /** @return int[] step ids by position */
    private function stages(int $agencyId, array $names): array
    {
        $tpl = DB::table('deal_pipeline_templates')->insertGetId(['agency_id' => $agencyId, 'name' => 'Sale pipeline', 'is_default' => 1, 'created_by_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $ids = [];
        foreach ($names as $i => $n) {
            $ids[] = DB::table('deal_pipeline_steps')->insertGetId(['pipeline_template_id' => $tpl, 'agency_id' => $agencyId, 'name' => $n, 'position' => $i + 1, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $ids;
    }

    private function allow(): void
    {
        RolePermission::create(['role' => $this->admin->role, 'permission_key' => 'deals_v2.manage_distribution_rules', 'scope' => 'agency']);
        PermissionService::clearCache();
        $this->actingAs($this->admin);
    }

    private function rule(int $stage, string $type, string $role, array $over = []): DealStageDocumentRule
    {
        return DealStageDocumentRule::withoutGlobalScopes()->create($over + [
            'agency_id' => $this->agency->id, 'pipeline_step_id' => $stage, 'document_type_id' => $this->t[$type], 'party_role' => $role,
            'delivery_mode' => 'secure_link', 'auto_on_stage_tick' => true, 'is_active' => true,
        ]);
    }

    private function body(array $over = []): array
    {
        return $over + ['pipeline_step_id' => $this->s[1], 'document_type_id' => $this->t['fica'], 'party_role' => 'buyer', 'delivery_mode' => 'secure_link', 'auto_on_stage_tick' => '1'];
    }

    private function audit(): \Illuminate\Support\Collection
    {
        return DocumentDistributionAuditEntry::query()->where('agency_id', $this->agency->id)->where('area', 'stage_rules')->orderBy('id')->get();
    }

    public function test_the_page_is_permission_gated_and_reads_in_plain_words(): void
    {
        RolePermission::create(['role' => $this->admin->role, 'permission_key' => 'deals_v2.view', 'scope' => 'agency']);
        PermissionService::clearCache();
        $this->actingAs($this->admin)->get(route('admin.settings.deal-distribution-rules.index'))->assertForbidden();
        $this->post(route('admin.settings.deal-distribution-rules.store'), $this->body())->assertForbidden();

        $this->allow();
        $this->get(route('admin.settings.deal-distribution-rules.index'))->assertOk()
            ->assertSee('Deal Distribution Rules')->assertSee('When a deal reaches a stage, send these documents to')
            ->assertSee('No documents are sent at a stage yet')->assertSee('Add a rule')
            ->assertSee('Who may receive')->assertSee('Send at a stage')->assertSee('data-dr-frame', false);
    }

    public function test_the_list_shows_stage_rules_only_with_search_filters_sort_and_pages(): void
    {
        $this->allow();
        $this->rule($this->s[0], 'otp', 'seller');
        $this->rule($this->s[1], 'fica', 'buyer', ['auto_on_stage_tick' => false]);
        $this->rule($this->s[1], 'mandate', 'transfer_attorney', ['is_active' => false]);
        // an "any stage" tick belongs to the matrix screen, never this list
        app(DocumentDistributionMatrix::class)->setTypeDistribution($this->agency->id, $this->t['otp'], ['bond_attorney']);

        $all = $this->get(route('admin.settings.deal-distribution-rules.index'))->assertOk();
        $this->assertSame(3, $all->viewData('rules')->total());
        $all->assertSee('Documents Signed')->assertSee('Offer Accepted')->assertSee('Switched off')->assertSee('Automatically')->assertSee('By hand')
            ->assertDontSee('Bond Attorney</td>', false);

        $q = fn (array $p) => $this->get(route('admin.settings.deal-distribution-rules.index', $p))->assertOk()->viewData('rules');
        $this->assertSame(1, $q(['q' => 'mandate'])->total());
        $this->assertSame(0, $q(['q' => 'lodged'])->total());                // a stage with no rules has no rows
        $this->assertSame(2, $q(['stage' => $this->s[1]])->total());
        $this->assertSame(1, $q(['party' => 'seller'])->total());
        $this->assertSame(1, $q(['q' => 'transferring'])->total());          // finds a party by its on-screen name
        $this->assertSame(2, $q(['status' => 'on'])->total());                // fica + otp are on
        $this->assertSame(1, $q(['status' => 'off'])->total());
        $this->assertSame(0, $q(['status' => 'archived'])->total());
        $this->assertSame(['OTP (Offer to Purchase)', 'Mandate', 'FICA'], $q(['sort' => 'document', 'dir' => 'desc'])->pluck('doc_label')->all());
        $this->assertSame('OTP (Offer to Purchase)', $q([])->first()->doc_label, 'default sort is pipeline stage order');

        // a filter that hides everything says so, differently from the true empty state
        $this->get(route('admin.settings.deal-distribution-rules.index', ['q' => 'nothing-like-this']))->assertSee('No rules match')->assertDontSee('No documents are sent at a stage yet');
    }

    public function test_the_list_pages_at_25(): void
    {
        $this->allow();
        foreach (range(1, 30) as $i) {
            $label = sprintf('Type %02d', $i);
            $id = DocumentType::create(['slug' => 'bulk-' . $i, 'label' => $label, 'sort_order' => 50 + $i, 'is_active' => true])->id;
            DealStageDocumentRule::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'pipeline_step_id' => $this->s[0], 'document_type_id' => $id, 'party_role' => 'seller', 'delivery_mode' => 'secure_link', 'auto_on_stage_tick' => true, 'is_active' => true]);
        }
        $this->get(route('admin.settings.deal-distribution-rules.index'))->assertSee('Showing 1&ndash;25 of 30 rules', false)->assertSee('Page 1 of 2');
        $this->get(route('admin.settings.deal-distribution-rules.index', ['page' => 2]))->assertSee('Showing 26&ndash;30 of 30 rules', false);
    }

    public function test_add_edit_archive_restore_each_write_an_audit_row(): void
    {
        $this->allow();
        $this->post(route('admin.settings.deal-distribution-rules.store'), $this->body())->assertSessionHas('status', 'Rule added.');
        $rule = DealStageDocumentRule::withoutGlobalScopes()->where('agency_id', $this->agency->id)->first();
        $this->assertSame([$this->s[1], $this->t['fica'], 'buyer', true], [(int) $rule->pipeline_step_id, (int) $rule->document_type_id, $rule->party_role, (bool) $rule->auto_on_stage_tick]);

        // edit: attachment, by hand, switched off
        $this->put(route('admin.settings.deal-distribution-rules.update', $rule->id), $this->body(['delivery_mode' => 'direct_attachment', 'auto_on_stage_tick' => '0', 'is_active' => '0']))->assertSessionHas('status', 'Rule saved.');
        $rule->refresh();
        $this->assertSame(['direct_attachment', false, false], [$rule->delivery_mode, (bool) $rule->auto_on_stage_tick, (bool) $rule->is_active]);
        // an edit that changes nothing is said so and writes nothing
        $this->put(route('admin.settings.deal-distribution-rules.update', $rule->id), $this->body(['delivery_mode' => 'direct_attachment', 'auto_on_stage_tick' => '0', 'is_active' => '0']))->assertSessionHas('status', 'Nothing was changed.');

        $this->delete(route('admin.settings.deal-distribution-rules.destroy', $rule->id))->assertSessionHas('status');
        $this->assertSoftDeleted('deal_stage_document_rules', ['id' => $rule->id]);
        $this->assertSame(1, $this->get(route('admin.settings.deal-distribution-rules.index', ['status' => 'archived']))->viewData('rules')->total());
        $this->assertSame(0, $this->get(route('admin.settings.deal-distribution-rules.index'))->viewData('rules')->total());

        $this->post(route('admin.settings.deal-distribution-rules.restore', $rule->id))->assertSessionHas('status', 'Rule restored.');
        $this->assertNull(DealStageDocumentRule::withoutGlobalScopes()->find($rule->id)->deleted_at);

        $entries = $this->audit();
        $this->assertCount(4, $entries, 'add, edit, archive, restore - and the no-op edit wrote nothing');
        $this->assertStringStartsWith('Added a rule: FICA to Buyer at Documents Signed', $entries[0]->summary);
        $this->assertStringStartsWith('Changed a rule', $entries[1]->summary);
        $this->assertContains('Delivered as: Secure link + PIN -> Email attachment', $entries[1]->changes);
        $this->assertContains('Status: Switched on -> Switched off', $entries[1]->changes);
        $this->assertStringStartsWith('Archived a rule', $entries[2]->summary);
        $this->assertStringStartsWith('Restored a rule', $entries[3]->summary);
        $this->assertSame($this->admin->id, (int) $entries[0]->user_id);

        $page = $this->get(route('admin.settings.deal-distribution-rules.index'));
        $page->assertSee('Last changed')->assertSee('Admin Anna')->assertSee('Restored a rule');
        // the matrix screen's own history is separate
        $this->assertSame(0, DocumentDistributionAuditEntry::where('agency_id', $this->agency->id)->where('area', 'matrix')->count());
    }

    public function test_a_duplicate_stage_document_party_is_refused_in_plain_words_and_an_archived_twin_comes_back(): void
    {
        $this->allow();
        $first = $this->rule($this->s[1], 'fica', 'buyer');
        $this->from('/back')->post(route('admin.settings.deal-distribution-rules.store'), $this->body())
            ->assertSessionHasErrors('rule');
        $this->assertStringContainsString('already exists', session('errors')->first('rule'));
        $this->assertSame(1, DealStageDocumentRule::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count());

        // editing a second rule into the first one's combination is refused too
        $second = $this->rule($this->s[1], 'otp', 'buyer');
        $this->from('/back')->put(route('admin.settings.deal-distribution-rules.update', $second->id), $this->body())->assertSessionHasErrors('rule');
        $this->assertSame($this->t['otp'], (int) $second->fresh()->document_type_id);

        // an archived twin is restored with the new settings instead of failing on the database key
        $first->delete();
        $this->post(route('admin.settings.deal-distribution-rules.store'), $this->body(['delivery_mode' => 'direct_attachment', 'auto_on_stage_tick' => '0']));
        $back = DealStageDocumentRule::withoutGlobalScopes()->find($first->id);
        $this->assertNull($back->deleted_at);
        $this->assertSame(['direct_attachment', false], [$back->delivery_mode, (bool) $back->auto_on_stage_tick]);
        $this->assertSame(2, DealStageDocumentRule::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count());

        // editing into an ARCHIVED rule's combination points at the archived rule
        $third = $this->rule($this->s[2], 'mandate', 'seller');
        $second->delete();
        $this->from('/back')->put(route('admin.settings.deal-distribution-rules.update', $third->id), $this->body(['pipeline_step_id' => $this->s[1], 'document_type_id' => $this->t['otp']]))->assertSessionHasErrors('rule');
        $this->assertStringContainsString('Restore the archived rule', session('errors')->first('rule'));
    }

    public function test_validation_and_another_agencys_stage_or_rule_is_a_404(): void
    {
        $this->allow();
        $this->from('/back')->post(route('admin.settings.deal-distribution-rules.store'), ['delivery_mode' => 'secure_link'])->assertSessionHasErrors(['pipeline_step_id', 'document_type_id', 'party_role']);
        $this->from('/back')->post(route('admin.settings.deal-distribution-rules.store'), $this->body(['party_role' => 'martian']))->assertSessionHasErrors('party_role');
        $this->from('/back')->post(route('admin.settings.deal-distribution-rules.store'), $this->body(['delivery_mode' => 'carrier_pigeon']))->assertSessionHasErrors('delivery_mode');

        $other = Agency::create(['name' => 'Other Realty', 'slug' => 'or-' . uniqid()]);
        $otherSteps = $this->stages($other->id, ['Their stage']);
        $this->post(route('admin.settings.deal-distribution-rules.store'), $this->body(['pipeline_step_id' => $otherSteps[0]]))->assertNotFound();

        // inserted directly: a model create while signed in is stamped with the SIGNED-IN user's agency
        $theirsId = DB::table('deal_stage_document_rules')->insertGetId(['agency_id' => $other->id, 'pipeline_step_id' => $otherSteps[0], 'document_type_id' => $this->t['fica'], 'party_role' => 'buyer', 'delivery_mode' => 'secure_link', 'auto_on_stage_tick' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $theirs = DealStageDocumentRule::withoutGlobalScopes()->find($theirsId);
        $this->put(route('admin.settings.deal-distribution-rules.update', $theirs->id), $this->body())->assertNotFound();
        $this->delete(route('admin.settings.deal-distribution-rules.destroy', $theirs->id))->assertNotFound();
        $this->post(route('admin.settings.deal-distribution-rules.restore', $theirs->id))->assertNotFound();
        $this->assertNull($theirs->fresh()->deleted_at);
        $this->assertSame(0, $this->get(route('admin.settings.deal-distribution-rules.index'))->viewData('rules')->total(), 'their rule is not listed');
    }

    public function test_it_never_lists_or_changes_the_any_stage_ticks_the_matrix_owns(): void
    {
        $this->allow();
        app(DocumentDistributionMatrix::class)->setTypeDistribution($this->agency->id, $this->t['fica'], ['buyer']);
        $tick = DealStageDocumentRule::withoutGlobalScopes()->where('agency_id', $this->agency->id)->whereNull('pipeline_step_id')->first();

        $this->assertSame(0, $this->get(route('admin.settings.deal-distribution-rules.index', ['status' => 'archived']))->viewData('rules')->total());
        $this->put(route('admin.settings.deal-distribution-rules.update', $tick->id), $this->body())->assertNotFound();
        $this->delete(route('admin.settings.deal-distribution-rules.destroy', $tick->id))->assertNotFound();
        $this->assertTrue($tick->fresh()->is_active);
    }

    public function test_a_row_warns_when_the_matrix_does_not_tick_it_and_both_screens_link_to_each_other(): void
    {
        $this->allow();
        $this->rule($this->s[1], 'fica', 'buyer');
        $this->get(route('admin.settings.deal-distribution-rules.index'))->assertSee('Not on the', false)->assertSee(route('admin.settings.document-distribution'), false);

        app(DocumentDistributionMatrix::class)->setTypeDistribution($this->agency->id, $this->t['fica'], ['buyer']);
        $this->get(route('admin.settings.deal-distribution-rules.index'))->assertDontSee('Not on the', false);

        $this->get(route('admin.settings.document-distribution'))->assertSee('data-dd-tabs', false)->assertSee('Send at a stage')->assertSee(route('admin.settings.deal-distribution-rules.index'), false);
    }

    public function test_the_ad_hoc_switch_still_works_and_the_page_has_the_fit_structure(): void
    {
        $this->allow();
        $this->post(route('admin.settings.deal-distribution-rules.adhoc-toggle'), ['adhoc_document_distribution_enabled' => '1'])->assertSessionHas('status');
        $this->assertTrue((bool) Agency::withoutGlobalScopes()->find($this->agency->id)->adhoc_document_distribution_enabled);

        $html = $this->get(route('admin.settings.deal-distribution-rules.index'))->getContent();
        $this->assertStringContainsString('.dr-frame { flex: 1 1 auto; min-height: 0; overflow: auto;', $html, 'the list scrolls inside its own frame');
        $this->assertStringContainsString('position: sticky; top: 0;', $html, 'header row is pinned');
        $this->assertStringContainsString('class="dr-page"', $html);
    }
}
