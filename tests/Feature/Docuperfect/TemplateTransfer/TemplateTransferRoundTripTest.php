<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\TemplateTransfer;

use App\Models\Docuperfect\FieldGroup;
use App\Models\Docuperfect\NamedField;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\TemplateSignatureZone;
use App\Models\Docuperfect\TemplateTransferLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Spec .ai/specs/esign-template-transfer.md §3, §4, §5, §11 — export -> import gives an
 * identical template in another agency; packages carry no identities; sent documents
 * are untouched; every import is a NEW agency-owned row.
 */
final class TemplateTransferRoundTripTest extends TestCase
{
    use RefreshDatabase;
    use TransferFixtures;

    private const COLS = ['signing_parties', 'cds_json', 'editor_state', 'fields_json', 'field_mappings', 'wizard_config', 'sections', 'insertable_blocks'];

    /** Replace every database id of a field group with a stable marker so two agencies' copies compare equal. */
    private function normalised(mixed $node, array $groupIds): mixed
    {
        if (! is_array($node)) {
            return $node;
        }
        $out = [];
        foreach ($node as $k => $v) {
            if ($k === 'fieldGroupId' && $v !== null) {
                $out[$k] = 'GROUP';
            } elseif ($k === 'typeKey' && is_string($v) && preg_match('/^fg:(\d+)$/', $v) && in_array((int) substr($v, 3), $groupIds, true)) {
                $out[$k] = 'fg:GROUP';
            } else {
                $out[$k] = $this->normalised($v, $groupIds);
            }
        }

        return $out;
    }

    public function test_round_trip_produces_an_identical_template_in_another_agency(): void
    {
        $hfc = $this->agency('Home Finders Coastal');
        $cape = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $src = $this->webTemplate($hfc, $owner);
        $srcGroupIds = FieldGroup::withoutGlobalScopes()->where('agency_id', $hfc->id)->pluck('id')->all();
        $this->assertNotEmpty($srcGroupIds, 'fixture must contain a real field group');

        $pkg = $this->export($src, $owner);
        [$copy] = $this->importInto($pkg['bytes'], $cape, $owner);

        // A NEW row, agency-owned by the chosen agency, owned by the importer, never ownerless.
        $this->assertNotSame($src->id, $copy->id);
        $this->assertSame($cape->id, (int) $copy->agency_id);
        $this->assertSame($owner->id, (int) $copy->owner_id);
        $this->assertNotNull($copy->agency_id);
        $this->assertNull($copy->archived_at);
        $this->assertSame($src->name, $copy->name);

        // Every settings column identical.
        foreach (['render_type', 'template_type', 'category', 'page_count', 'is_esign', 'party_mode', 'header_display', 'security_tier', 'allowed_delivery_modes', 'document_type_id'] as $col) {
            $this->assertEquals($src->{$col}, $copy->{$col}, "column {$col} differs");
        }

        // Every content column identical once the field-group id (per-agency) is normalised.
        $copyGroupIds = FieldGroup::withoutGlobalScopes()->where('agency_id', $cape->id)->pluck('id')->all();
        $this->assertCount(count($srcGroupIds), $copyGroupIds, 'the field group is recreated in the target agency');
        foreach (self::COLS as $col) {
            $this->assertEquals(
                $this->normalised($src->{$col}, $srcGroupIds),
                $this->normalised($copy->{$col}, $copyGroupIds),
                "content column {$col} differs after the round trip"
            );
        }

        // The id remap that the old seeders skipped: editor_state.mappings AND field_mappings both resolve
        // each field to the same contact/property column on both sides.
        $checked = 0;
        foreach (['field_mappings', 'editor_state.mappings'] as $path) {
            $a = data_get($src->toArray(), $path);
            $b = data_get($copy->toArray(), $path);
            foreach ($a as $tag => $m) {
                if (! empty($m['namedFieldId'])) {
                    $na = NamedField::find($m['namedFieldId']);
                    $nb = NamedField::find($b[$tag]['namedFieldId']);
                    $this->assertSame([$na->source_type, $na->source_column, $na->source_contact_type], [$nb->source_type, $nb->source_column, $nb->source_contact_type]);
                    $checked++;
                }
            }
        }
        $this->assertGreaterThan(10, $checked);

        // The agency's field group is the target agency's own, not a pointer into HFC's.
        $copyMapping = collect($copy->field_mappings)->firstWhere('mappingType', 'field_group');
        $this->assertContains((int) $copyMapping['fieldGroupId'], $copyGroupIds);
        $this->assertNotContains((int) $copyMapping['fieldGroupId'], $srcGroupIds);

        // The page file was regenerated on THIS system from the stored data, under the new id.
        $this->assertSame("docuperfect.web-templates.cds.template-{$copy->id}", $copy->blade_view);
        $this->assertFileExists(resource_path("views/docuperfect/web-templates/cds/template-{$copy->id}.blade.php"));

        // No branches, no compiled binding, no lease/pack links implied.
        $this->assertSame(0, DB::table('docuperfect_template_branches')->where('template_id', $copy->id)->count());
        $this->assertFalse((bool) $copy->compiled_serving);
        $this->assertNull($copy->compiled_family);
    }

    public function test_the_package_carries_no_agency_user_or_environment_identity(): void
    {
        $hfc = $this->agency('Home Finders Coastal');
        $owner = $this->owner();
        $src = $this->webTemplate($hfc, $owner);

        $pkg = $this->export($src, $owner);
        $parts = $this->rewriteZip($pkg['bytes'], fn ($e) => $e);
        $this->assertNotEmpty($parts);

        $zip = new \ZipArchive();
        $zip->open($this->tmpFile($pkg['bytes']));
        $json = $zip->getFromName('template.json');
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();
        $data = json_decode($json, true);

        $this->assertSame('corex-template-package', $manifest['format']);
        $this->assertSame(1, $manifest['format_version']);

        foreach (['agency_id', 'owner_id', 'created_by', 'blade_view', 'is_global', 'compiled_family', 'compiled_serving', 'branch_id'] as $forbidden) {
            $this->assertStringNotContainsString('"' . $forbidden . '"', $json, "package must not carry {$forbidden}");
        }
        $this->assertStringNotContainsString($hfc->name, $json);
        $this->assertStringNotContainsString($owner->email, $json);
        $this->assertStringNotContainsString('template-' . $src->id, $json);

        // No raw numeric named-field / field-group id survives anywhere in the content.
        $walk = function ($n) use (&$walk) {
            if (is_array($n)) {
                foreach ($n as $k => $v) {
                    if (in_array($k, ['namedFieldId', 'named_field_id', 'fieldGroupId', 'field_group_id'], true) && $v !== null) {
                        $this->assertIsString($v);
                        $this->assertStringStartsWith('@', $v);
                    }
                    if ($k === 'typeKey' && is_string($v) && str_starts_with($v, 'fg:')) {
                        $this->assertStringStartsWith('fg:@', $v);
                    }
                    $walk($v);
                }
            }
        };
        $walk($data['template']);
        $this->assertNotEmpty($data['named_fields']);
        $this->assertNotEmpty($data['field_groups']);
        $this->assertSame('mandate', $data['template']['document_type']['slug']);
    }

    public function test_the_same_package_imports_into_two_further_agencies(): void
    {
        $hfc = $this->agency('Home Finders Coastal');
        $cape = $this->agency('Cape Town Lettings');
        $kzn = $this->agency('Durban North Realty');
        $owner = $this->owner();
        $pkg = $this->export($this->webTemplate($hfc, $owner), $owner);

        [$a] = $this->importInto($pkg['bytes'], $cape, $owner);
        [$b] = $this->importInto($pkg['bytes'], $kzn, $owner);

        $this->assertSame($cape->id, (int) $a->agency_id);
        $this->assertSame($kzn->id, (int) $b->agency_id);
        $this->assertNotSame($a->id, $b->id);

        // Agency isolation: each copy is reachable only from its own agency; a third agency never sees it.
        $this->assertTrue($a->isVisibleToAgency($cape->id));
        $this->assertFalse($a->isVisibleToAgency($kzn->id));
        $this->assertFalse($a->isVisibleToAgency($hfc->id));
        $this->assertFalse($b->isVisibleToAgency($cape->id));
        $this->assertNotNull($a->agency_id, 'never ownerless / shared');

        $kznUser = User::factory()->create(['agency_id' => $kzn->id, 'role' => 'agent']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $a->assertAccessibleBy($kznUser);
    }

    public function test_pdf_template_round_trip_carries_images_and_signature_positions(): void
    {
        Storage::fake();
        $hfc = $this->agency('Home Finders Coastal');
        $cape = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $src = $this->pdfTemplate($hfc, $owner, 3);

        $pkg = $this->export($src, $owner);
        [$copy] = $this->importInto($pkg['bytes'], $cape, $owner);

        $this->assertSame('pdf', $copy->render_type);
        $this->assertSame(3, (int) $copy->page_count);
        $this->assertNull($copy->blade_view);
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(Storage::get("docuperfect/templates/{$src->id}/page-{$i}.png"), Storage::get("docuperfect/templates/{$copy->id}/page-{$i}.png"));
        }
        $this->assertEquals($src->fields_json, $copy->fields_json);
        $this->assertEquals($src->signing_parties, $copy->signing_parties);
        $zones = TemplateSignatureZone::where('template_id', $copy->id)->orderBy('sort_order')->get();
        $this->assertCount(2, $zones);
        $this->assertSame(['owner_party'], $zones[0]->assigned_parties);
        $this->assertSame('Landlord', $zones[0]->label);
        $this->assertEquals(10, (float) $zones[0]->x_position);
    }

    public function test_sent_documents_and_the_source_template_are_byte_identical_after_export_and_import(): void
    {
        $hfc = $this->agency('Home Finders Coastal');
        $cape = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $src = $this->webTemplate($hfc, $owner);
        $doc = $this->sentDocument($src, $owner, $hfc);

        $snapshot = fn () => [
            'template' => (array) DB::table('docuperfect_templates')->where('id', $src->id)->first(),
            'document' => (array) DB::table('docuperfect_documents')->where('id', $doc->id)->first(),
            'documents_total' => DB::table('docuperfect_documents')->count(),
            'groups' => FieldGroup::withoutGlobalScopes()->where('agency_id', $hfc->id)->count(),
        ];
        $before = $snapshot();

        $pkg = $this->export($src, $owner);
        $this->assertSame($before, $snapshot(), 'export must write nothing to the template or its documents');

        $this->importInto($pkg['bytes'], $cape, $owner);
        $this->importInto($pkg['bytes'], $hfc, $owner, [0 => 'new_copy']);   // even back into the SAME agency (name clash path)
        $this->assertSame($before['template'], $snapshot()['template']);
        $this->assertSame($before['document'], $snapshot()['document']);
        $this->assertSame($before['documents_total'], $snapshot()['documents_total']);

        $frozen = json_decode($snapshot()['document']['web_template_data'], true);
        $this->assertSame('<p>FROZEN wording for 14 Marine Drive</p>', $frozen['merged_html']);
    }

    public function test_export_and_import_are_audited(): void
    {
        $hfc = $this->agency('Home Finders Coastal');
        $cape = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $src = $this->webTemplate($hfc, $owner);

        $pkg = $this->export($src, $owner);
        [$copy] = $this->importInto($pkg['bytes'], $cape, $owner);

        $exp = TemplateTransferLog::where('direction', 'export')->firstOrFail();
        $this->assertSame($owner->id, (int) $exp->actor_user_id);
        $this->assertSame($pkg['checksum'], $exp->package_checksum);
        $this->assertSame($hfc->id, (int) $exp->source_agency_id);

        $imp = TemplateTransferLog::where('direction', 'import')->where('outcome', 'success')->firstOrFail();
        $this->assertSame($owner->id, (int) $imp->actor_user_id);
        $this->assertSame('Johan Owner', $imp->actor_name);
        $this->assertSame($pkg['checksum'], $imp->package_checksum, 'source package checksum is recorded');
        $this->assertSame($cape->id, (int) $imp->target_agency_id);
        $this->assertSame('Cape Town Lettings', $imp->target_agency_name);
        $this->assertSame($copy->id, (int) $imp->template_id);
        $this->assertNotNull($imp->created_at);

        // insert-only
        $this->expectException(\DomainException::class);
        $imp->outcome = 'failed';
        $imp->save();
    }
}
