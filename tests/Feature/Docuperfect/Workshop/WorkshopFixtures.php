<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Workshop;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\NamedField;
use App\Models\Docuperfect\Template;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Tests\Feature\Docuperfect\TemplateTransfer\TransferFixtures;

/**
 * Shared fixtures for the e-sign document workshop tests (readiness, demo, release). Built on the template-transfer fixtures
 * (high template ids so a test row never overwrites a committed page snapshot) and shaped like Mega's real lease: a Word-imported
 * template whose blanks are bound through editor_state.mappings and whose signature / initial places name their signer in
 * mapping.parties ("Lessor", "Lessee", "Agent").
 */
trait WorkshopFixtures
{
    use TransferFixtures;

    protected function namedField(string $name = 'Property address', string $column = 'address'): NamedField
    {
        return NamedField::firstOrCreate(
            ['source_type' => 'property', 'source_column' => $column, 'source_contact_type' => null],
            ['name' => $name, 'field_type' => 'text', 'sort_order' => 900]
        );
    }

    /** A user of $agency who can manage templates; $role grants the keys as a real agency role row would. */
    protected function agencyManager(Agency $agency, array $extraKeys = [], string $role = 'admin'): User
    {
        PermissionService::forceProductionPosture();
        $branch = Branch::firstOrCreate(['agency_id' => $agency->id, 'name' => 'HQ']);
        Role::firstOrCreate(['name' => $role, 'agency_id' => $agency->id], ['label' => ucfirst($role)]);
        foreach (array_merge(['manage_templates', 'access_docuperfect'], $extraKeys) as $key) {
            RolePermission::updateOrCreate(['role' => $role, 'permission_key' => $key, 'agency_id' => $agency->id], []);
        }
        PermissionService::clearCache();
        Role::clearCache();

        return User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => $role, 'is_active' => true]);
    }

    /** An agency's administrator who may open the approval and settings screens (the keys a real admin role carries). */
    protected function agencyAdmin(Agency $agency): User
    {
        return $this->agencyManager($agency, ['templates.approve_release', 'esign.settings'], 'admin');
    }

    /** A user of $agency holding $role with the approval key (so the setting, not the role grant, decides). */
    protected function agencyUserWithRole(Agency $agency, string $role, array $keys = ['templates.approve_release']): User
    {
        return $this->agencyManager($agency, $keys, $role);
    }

    /** Verified, then released to $client by $owner. Returns [release, copy]. */
    protected function releasedCopy(\App\Models\Docuperfect\Template $source, Agency $client, User $owner): array
    {
        $svc = app(\App\Services\Docuperfect\Release\TemplateReleaseService::class);
        $svc->verify($source, $owner, true);
        $release = $svc->release($source->fresh(), $client, $owner);
        $copy = Template::withoutGlobalScopes()->find($release->template_id);
        $this->trackBlade($copy);

        return [$release, $copy];
    }

    /** A Mega-shaped web template: 2 bound blanks, 1 typed-by-hand blank, 3 signature places (Lessor, Lessee, Agent), 2 initial places. */
    protected function megaShaped(Agency $agency, User $owner, array $over = [], array $editorOver = [], array $cdsOver = []): Template
    {
        $nf = $this->namedField();
        $tags = [
            ['id' => 'tag-a', 'type' => 'input', 'label' => '[INPUT 1]', 'number' => 1],
            ['id' => 'tag-b', 'type' => 'input', 'label' => '[INPUT 2]', 'number' => 2],
            ['id' => 'tag-c', 'type' => 'input', 'label' => '[INPUT 3]', 'number' => 3],
            ['id' => 'tag-s1', 'type' => 'signature', 'label' => '[SIGNATURE 1]', 'number' => 4],
            ['id' => 'tag-s2', 'type' => 'signature', 'label' => '[SIGNATURE 2]', 'number' => 5],
            ['id' => 'tag-s3', 'type' => 'signature', 'label' => '[SIGNATURE 3]', 'number' => 6],
            ['id' => 'tag-i1', 'type' => 'initial', 'label' => '[INITIAL 1]', 'number' => 7],
            ['id' => 'tag-i2', 'type' => 'initial', 'label' => '[INITIAL 2]', 'number' => 8],
        ];
        $mappings = [
            'tag-a' => ['label' => 'Property Address', 'party' => 'auto', 'typeKey' => 'sf:property', 'mappingType' => 'named_field', 'namedFieldId' => $nf->id, 'sourceType' => 'property', 'fieldGroupId' => null],
            'tag-b' => ['label' => 'Description', 'party' => 'auto', 'typeKey' => 'sf:property', 'mappingType' => 'named_field', 'namedFieldId' => $nf->id, 'sourceType' => 'property', 'fieldGroupId' => null],
            'tag-c' => ['label' => 'Pet deposit', 'party' => 'auto', 'typeKey' => 'sf:manual', 'mappingType' => 'manual', 'namedFieldId' => null, 'fieldGroupId' => null],
            'tag-s1' => ['parties' => ['Lessor'], 'variant' => 'sig_full'],
            'tag-s2' => ['parties' => ['Lessee'], 'variant' => 'sig_full'],
            'tag-s3' => ['parties' => ['Agent'], 'variant' => 'sig_full'],
            'tag-i1' => ['parties' => ['Lessor']],
            'tag-i2' => ['parties' => ['Lessee']],
        ];
        $tagged = '<p>Property: <span class="doc-tag" data-tag-id="tag-a">[INPUT 1]</span></p><p>Description: <span class="doc-tag" data-tag-id="tag-b">[INPUT 2]</span></p>'
            . '<p>Pet deposit: <span class="doc-tag" data-tag-id="tag-c">[INPUT 3]</span></p><p>Lease wording.</p>'
            . '<p>Lessor <span class="doc-tag doc-tag-sig" data-tag-id="tag-s1">[SIGNATURE 1]</span> Lessee <span class="doc-tag doc-tag-sig" data-tag-id="tag-s2">[SIGNATURE 2]</span> Agent <span class="doc-tag doc-tag-sig" data-tag-id="tag-s3">[SIGNATURE 3]</span></p>'
            . '<p>Initials <span class="doc-tag doc-tag-ini" data-tag-id="tag-i1">[INITIAL 1]</span> <span class="doc-tag doc-tag-ini" data-tag-id="tag-i2">[INITIAL 2]</span></p>';
        $editor = array_merge(['tags' => $tags, 'mappings' => $mappings, 'tagged_html' => $tagged, 'document_look' => 'source', 'signature_block' => 'document'], $editorOver);
        $cds = array_merge([
            'title' => 'Mega Lease', 'sections' => [['type' => 'table'], ['type' => 'page_break']],
            'page_header' => ['lines' => [['size' => 10, 'text' => 'Mega Rent Lease – {PROPERTY} – {SURNAME} – {YEAR}', 'align' => 'center']]],
            'page_footer' => ['rule' => true, 'lines' => [['size' => 8, 'text' => 'VIPS CC t/a Mega Rent | Reg No CK2003/088713/23', 'right' => 'Page {PAGE}']]],
            'parse_report' => ['line' => 'Everything the Word file prints was carried over (46,139 characters of text read).', 'dropped' => [], 'read_chars' => 46139, 'text_chars' => 46139, 'unread_chars' => 0],
        ], $cdsOver);

        return Template::create(array_merge([
            'name' => 'Mega Lease Agreement 2026', 'template_type' => 'cds', 'render_type' => 'web', 'category' => 'rentals', 'is_esign' => true,
            'party_mode' => 'shared', 'header_display' => 'none', 'security_tier' => 'standard', 'page_count' => 0,
            'agency_id' => $agency->id, 'owner_id' => $owner->id, 'is_global' => true,
            'document_type_id' => $this->documentType()->id,
            'blade_view' => 'docuperfect.templates.readiness',
            'signing_parties' => ['owner_party', 'agent', 'acquiring_party'],
            'cds_json' => $cds, 'editor_state' => $editor, 'field_mappings' => $mappings,
            'fields_json' => [['key' => '', 'type' => 'text', 'label' => 'Property Address', 'assignedTo' => 'agent']],
        ], $over));
    }

    /** Build the template's real page file (what saving it in the builder does), so the demo has a compiled page to draw. Tracked for clean-up. */
    protected function compiled(Template $t): Template
    {
        app(\App\Services\Docuperfect\WebTemplateBladeEnsurer::class)->regenerate($t);
        $t = $t->fresh();
        $this->trackBlade($t);

        return $t;
    }

    /** The row the readiness report must never change. */
    protected function templateSnapshot(Template $t): array
    {
        return \DB::table('docuperfect_templates')->where('id', $t->id)->first() ? (array) \DB::table('docuperfect_templates')->where('id', $t->id)->first() : [];
    }
}
