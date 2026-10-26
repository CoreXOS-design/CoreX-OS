<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Models\Branch;
use App\Models\Docuperfect\NamedField;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\TemplateTransferLog;
use App\Models\RentalLeaseTemplate;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Docuperfect\TemplateTransfer\TransferFixtures;
use Tests\TestCase;

/**
 * "Copy template to another agency" — CoreX administrators only. Spec: .ai/specs/cds-reimport-from-word.md.
 * It is the template-package pipeline run in memory, so the source is the real captured
 * "Exclusive Authority to Sell" (real wording, real bindings, a real field group).
 */
final class TemplateAgencyCopyTest extends TestCase
{
    use RefreshDatabase;
    use TransferFixtures;

    private function copy(User $user, Template $t, $target, array $extra = [])
    {
        return $this->actingAs($user)->post(route('docuperfect.templates.copyToAgency', $t->id), ['agency_id' => $target->id] + $extra);
    }

    private function copyOf(int $agencyId): Template
    {
        return $this->trackBlade(Template::where('agency_id', $agencyId)->orderByDesc('id')->firstOrFail());
    }

    public function test_the_copy_is_a_working_new_template_in_the_target_agency_with_everything_intact(): void
    {
        $workshop = $this->agency('CoreX Workshop');
        $cape = $this->agency('Cape Town Lettings');
        $third = $this->agency('Third Agency');
        $owner = $this->owner();
        $src = $this->webTemplate($workshop, $owner, ['party_mode' => 'per_party', 'category' => 'rentals']);
        // Look lives in editor_state alongside the real tags/bindings; give the source a known look.
        $state = $src->editor_state;
        $state['document_look'] = 'source';
        $src->update(['editor_state' => $state, 'signing_parties' => ['owner_party', 'agent', 'acquiring_party']]);
        $src = $src->fresh();
        $namedFieldsBefore = NamedField::count();

        $this->copy($owner, $src, $cape)->assertRedirect()->assertSessionHas('status');

        $copy = $this->copyOf($cape->id);
        $this->assertNotSame($src->id, $copy->id, 'a NEW template id');
        $this->assertSame($cape->id, (int) $copy->agency_id);
        $this->assertSame($src->name, $copy->name);

        // Sections, tags/bindings, settings, look, signing parties intact (ids of field definitions are
        // remapped by stable key, so compare shape through the tag/binding structure, not raw ids).
        $this->assertEquals($src->cds_json, $copy->cds_json);
        $this->assertSame($src->editor_state['tagged_html'], $copy->editor_state['tagged_html']);
        $this->assertSame('source', $copy->editor_state['document_look']);
        $this->assertEquals($src->editor_state['tags'], $copy->editor_state['tags']);
        $this->assertSame(array_keys($src->editor_state['mappings']), array_keys($copy->editor_state['mappings']));
        foreach ($src->editor_state['mappings'] as $tagId => $m) {
            $c = $copy->editor_state['mappings'][$tagId];
            $this->assertSame($m['label'] ?? null, $c['label'] ?? null, "binding {$tagId} kept its label");
            $this->assertSame($m['mappingType'] ?? null, $c['mappingType'] ?? null);
            if (! str_starts_with((string) ($m['typeKey'] ?? ''), 'fg:')) {
                $this->assertSame($m['typeKey'] ?? null, $c['typeKey'] ?? null, "binding {$tagId} kept its type");
            }
        }
        $this->assertSame(['owner_party', 'agent', 'acquiring_party'], $copy->signing_parties);
        $this->assertSame($src->party_mode, $copy->party_mode);
        $this->assertSame($src->security_tier, $copy->security_tier);
        $this->assertSame($src->allowed_delivery_modes, $copy->allowed_delivery_modes);
        $this->assertSame((bool) $src->is_esign, (bool) $copy->is_esign);
        $this->assertSame($src->category, $copy->category);
        $this->assertSame($src->template_type, $copy->template_type);
        $this->assertNotEmpty($copy->blade_view, 'a working page file was generated for the new id');
        $this->assertSame($namedFieldsBefore, NamedField::count(), 'field definitions are matched by stable key, not duplicated');

        // The original is untouched; nothing reached a third agency.
        $this->assertEquals($src->fresh()->cds_json, $src->cds_json);
        $this->assertSame($workshop->id, (int) $src->fresh()->agency_id);
        $this->assertSame(0, Template::where('agency_id', $third->id)->count());
        $this->assertFalse($copy->isVisibleToAgency($third->id));
        $this->assertFalse($copy->isVisibleToAgency($workshop->id));
        $this->assertTrue($copy->isVisibleToAgency($cape->id));
    }

    public function test_one_audit_row_records_source_to_target(): void
    {
        $workshop = $this->agency('CoreX Workshop');
        $cape = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $src = $this->webTemplate($workshop, $owner);

        $this->copy($owner, $src, $cape);
        $copy = $this->copyOf($cape->id);

        $rows = TemplateTransferLog::all();
        $this->assertCount(1, $rows, 'one row for the whole copy — not an export row plus an import row');
        $row = $rows->first();
        $this->assertSame('copy', $row->direction);
        $this->assertSame('success', $row->outcome);
        $this->assertSame($workshop->id, (int) $row->source_agency_id);
        $this->assertSame($cape->id, (int) $row->target_agency_id);
        $this->assertSame($copy->id, (int) $row->template_id);
        $this->assertSame($owner->id, (int) $row->actor_user_id);
        $this->assertStringContainsString('Copied from template #' . $src->id, implode(' ', $row->warnings));
    }

    public function test_the_lease_link_and_field_map_are_copied_only_when_ticked(): void
    {
        $workshop = $this->agency('CoreX Workshop');
        $cape = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $src = $this->webTemplate($workshop, $owner);
        $map = ['rent' => ['field' => 'monthly_rental', 'label' => null, 'required' => false], 'deposit' => ['field' => 'deposit_amount', 'label' => null, 'required' => false]];
        $source = RentalLeaseTemplate::withoutAgencyStamping(fn () => RentalLeaseTemplate::create([
            'agency_id' => $workshop->id, 'name' => 'Mega Lease 2026', 'docuperfect_template_id' => $src->id,
            'category' => 'residential', 'is_active' => true, 'field_map' => $map, 'is_default' => true,
        ]));

        // Not ticked: no lease row in the target agency.
        $this->copy($owner, $src, $cape);
        $this->assertSame(0, RentalLeaseTemplate::withoutGlobalScopes()->where('agency_id', $cape->id)->count());

        // Ticked: a row in the target agency, pointing at the NEW template, field map as it was.
        $this->copy($owner, $src, $cape, ['copy_lease_field_map' => 1]);
        $second = $this->copyOf($cape->id);
        $rows = RentalLeaseTemplate::withoutGlobalScopes()->where('agency_id', $cape->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($second->id, (int) $rows[0]->docuperfect_template_id);
        $this->assertEquals($map, $rows[0]->field_map);
        $this->assertSame('residential', $rows[0]->category);
        $this->assertTrue($rows[0]->is_default, 'the target agency had no default, so this becomes it');

        // The workshop's own lease row is untouched.
        $source->refresh();
        $this->assertSame($src->id, (int) $source->docuperfect_template_id);
        $this->assertSame($workshop->id, (int) $source->agency_id);
        $this->assertSame(1, RentalLeaseTemplate::withoutGlobalScopes()->where('agency_id', $workshop->id)->count());
    }

    public function test_a_name_already_taken_in_the_target_becomes_a_numbered_copy_and_nothing_is_replaced(): void
    {
        $workshop = $this->agency('CoreX Workshop');
        $cape = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $src = $this->webTemplate($workshop, $owner);

        $this->copy($owner, $src, $cape);
        $first = $this->copyOf($cape->id);
        $this->copy($owner, $src, $cape);

        $names = Template::where('agency_id', $cape->id)->orderBy('id')->pluck('name')->all();
        $this->assertSame([$src->name, $src->name . ' v2'], $names);
        $this->assertSame($src->name, $first->fresh()->name, 'the first copy was not replaced');
    }

    public function test_only_a_corex_administrator_can_copy(): void
    {
        PermissionService::forceProductionPosture();
        $workshop = $this->agency('CoreX Workshop');
        $cape = $this->agency('Cape Town Lettings');
        $branch = Branch::create(['agency_id' => $workshop->id, 'name' => 'HQ']);
        Role::create(['name' => 'admin', 'label' => 'Administrator', 'agency_id' => $workshop->id]);
        foreach (['templates.transfer', 'manage_templates', 'access_docuperfect'] as $key) {
            RolePermission::updateOrCreate(['role' => 'admin', 'permission_key' => $key, 'agency_id' => $workshop->id], []);
        }
        PermissionService::clearCache();
        $admin = User::factory()->create(['agency_id' => $workshop->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
        $src = $this->webTemplate($workshop, $admin);

        // Even with the key granted, an agency admin is refused (owner_only), and nothing is created.
        $this->actingAs($admin)->postJson(route('docuperfect.templates.copyToAgency', $src->id), ['agency_id' => $cape->id])->assertForbidden();
        $this->assertSame(0, Template::where('agency_id', $cape->id)->count());
        $this->assertSame(0, TemplateTransferLog::count());

        // …and the builder shows an agency admin no copy control.
        $this->assertStringNotContainsString('Copy to another agency', $this->actingAs($admin)->get(route('docuperfect.templates.index'))->getContent());
    }

    public function test_copying_to_the_agency_it_is_already_in_is_refused_in_plain_language(): void
    {
        $workshop = $this->agency('CoreX Workshop');
        $owner = $this->owner();
        $src = $this->webTemplate($workshop, $owner);

        $this->copy($owner, $src, $workshop)->assertSessionHasErrors('copy_to_agency');
        $this->assertSame(1, Template::where('agency_id', $workshop->id)->where('name', $src->name)->count());
        $this->assertSame(0, TemplateTransferLog::count());
    }

    public function test_an_agency_that_does_not_exist_is_refused(): void
    {
        $workshop = $this->agency('CoreX Workshop');
        $owner = $this->owner();
        $src = $this->webTemplate($workshop, $owner);

        $this->actingAs($owner)->post(route('docuperfect.templates.copyToAgency', $src->id), ['agency_id' => 999999])->assertSessionHasErrors('agency_id');
        $this->actingAs($owner)->post(route('docuperfect.templates.copyToAgency', $src->id), [])->assertSessionHasErrors('agency_id');
    }
}
