<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Workshop;

use App\Models\Docuperfect\NamedField;
use App\Models\Docuperfect\Template;
use App\Models\RentalLeaseTemplate;
use App\Services\Docuperfect\Release\TemplateReleaseService;
use App\Services\Docuperfect\TemplateTransfer\TemplatePackage;
use App\Services\Docuperfect\TemplateTransfer\TemplatePackageImporter;
use App\Services\Docuperfect\TemplateTransfer\TemplateTransferException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * .ai/specs/esign-template-transfer.md §13.8 + §4 — a template whose fields use the "Lease" data source (HFC's residential and commercial
 * lease agreements) packages, imports and releases like any other; the importer accepts every data source the system has, from one list.
 */
final class TemplateLeaseSourceFieldPackageTest extends TestCase
{
    use RefreshDatabase;
    use WorkshopFixtures;

    private const MAP = ['rent' => 'monthly_rental', 'start_date' => 'lease_start', 'tenant_name' => 'tenant_name', 'landlord_name' => 'landlord_name', '_meta' => ['month_to_month' => true]];

    /** A lease-agreement-shaped template: one blank is bound to a Lease-sourced field, and the agency's lease link carries a field map. */
    private function leaseTemplate(\App\Models\Agency $agency, \App\Models\User $owner, bool $linked = true): Template
    {
        $t = $this->megaShaped($agency, $owner, ['name' => 'Lease Agreement - Residential']);
        $nf = NamedField::firstOrCreate(
            ['source_type' => 'lease', 'source_column' => 'monthly_rent', 'source_contact_type' => null],
            ['name' => 'Lease - Monthly rent', 'field_type' => 'text', 'sort_order' => 900]
        );
        $editor = $t->editor_state;
        $editor['mappings']['tag-c'] = ['label' => 'Monthly rent', 'party' => 'auto', 'typeKey' => 'sf:lease', 'mappingType' => 'named_field', 'namedFieldId' => $nf->id, 'sourceType' => 'lease', 'fieldGroupId' => null];
        $t->update(['editor_state' => $editor, 'field_mappings' => $editor['mappings']]);
        if ($linked) {
            RentalLeaseTemplate::withoutGlobalScopes()->create(['agency_id' => $agency->id, 'name' => 'Lease Agreement - Residential', 'docuperfect_template_id' => $t->id, 'category' => 'residential', 'is_active' => true, 'is_default' => true, 'field_map' => self::MAP]);
        }

        return $t->fresh();
    }

    public function test_a_lease_sourced_field_exports_and_imports_into_another_agency(): void
    {
        $hfc = $this->agency('Home Finders Coastal');
        $cape = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $bytes = $this->export($this->leaseTemplate($hfc, $owner), $owner)['bytes'];

        $defs = TemplatePackage::read($this->tmpFile($bytes))['data']['named_fields'];
        $this->assertContains('lease', array_column($defs, 'source_type'), 'the package carries the Lease data source as it is');

        [$copy] = $this->importInto($bytes, $cape, $owner);
        $this->assertSame($cape->id, (int) $copy->agency_id);
        $id = $copy->editor_state['mappings']['tag-c']['namedFieldId'];
        $nf = NamedField::find($id);
        $this->assertSame('lease', $nf->source_type);
        $this->assertSame('monthly_rent', $nf->source_column);
        $this->assertSame(1, NamedField::where('source_type', 'lease')->where('source_column', 'monthly_rent')->count(), 'matched to the existing definition, not duplicated');
    }

    public function test_a_lease_template_released_from_a_package_applies_its_link_and_keeps_the_lease_field_only_at_approval(): void
    {
        $hfc = $this->agency('Home Finders Coastal');
        $cape = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $bytes = $this->export($this->leaseTemplate($hfc, $owner), $owner)['bytes'];
        $svc = app(TemplateReleaseService::class);

        $loaded = app(TemplatePackageImporter::class)->load($this->tmpFile($bytes));
        $this->assertSame(['category' => 'residential', 'name' => 'Lease Agreement - Residential', 'mappings' => count(self::MAP)], app(TemplatePackageImporter::class)->preview($loaded, $cape)[0]['lease_link']);

        $release = $svc->releaseFromPackage($loaded[0], $cape, $owner);
        $copy = Template::withoutGlobalScopes()->find($release->template_id);
        $this->trackBlade($copy);
        $this->assertSame(0, RentalLeaseTemplate::withoutGlobalScopes()->where('agency_id', $cape->id)->count(), 'nothing linked while awaiting approval');

        $res = $svc->approve($release, $this->agencyAdmin($cape), true);
        $this->assertStringNotContainsString('could not be linked', (string) ($res['lease_message'] ?? ''));
        $row = RentalLeaseTemplate::withoutGlobalScopes()->where('agency_id', $cape->id)->where('is_active', true)->first();
        $this->assertSame((int) $copy->id, (int) $row->docuperfect_template_id);
        $this->assertSame('residential', $row->category);
        $this->assertSame(['rent' => 'monthly_rental', 'start_date' => 'lease_start', 'tenant_name' => 'tenant_name', 'landlord_name' => 'landlord_name'], array_diff_key($row->field_map, ['_meta' => 1]), 'the field map arrives as exported');
        $this->assertSame('lease', NamedField::find($copy->fresh()->editor_state['mappings']['tag-c']['namedFieldId'])->source_type);
    }

    public function test_a_data_source_the_system_does_not_have_is_still_refused_in_plain_words(): void
    {
        $hfc = $this->agency('Home Finders Coastal');
        $owner = $this->owner();
        $p = TemplatePackage::read($this->tmpFile($this->export($this->leaseTemplate($hfc, $owner), $owner)['bytes']));
        $data = $p['data'];
        $first = array_key_first($data['named_fields']);
        $data['named_fields'][$first]['source_type'] = 'timeshare';
        $bad = TemplatePackage::build($data, $p['files'], 'Lease Agreement - Residential', 'test')['bytes'];

        try {
            app(TemplatePackageImporter::class)->load($this->tmpFile($bad));
            $this->fail('expected a refusal');
        } catch (TemplateTransferException $e) {
            $this->assertStringContainsString('field definition in the package is damaged', implode(' ', array_merge([$e->getMessage()], $e->details)));
        }
    }

    public function test_the_shared_data_source_list_matches_the_database_column(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('The column is an ENUM on MySQL only.');
        }
        $type = DB::selectOne("SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'docuperfect_named_fields' AND COLUMN_NAME = 'source_type'")->t;
        preg_match_all("/'([^']+)'/", $type, $m);

        $this->assertEqualsCanonicalizing($m[1], NamedField::SOURCE_TYPES, 'a new data source is an ENUM migration plus one entry in NamedField::SOURCE_TYPES');
    }
}
