<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * HFC "Addendum B" — a STANDALONE agency addendum e-sign template (heading
 * "ADDENDUM B" + the EXTRA INFORMATION table: registered building plans (Y/N)
 * and the four Certificates of Compliance — Electrical, Electrical Fence, Gas,
 * Entomology — each Y/N + a "when issued" date; plus a Seller / Purchaser /
 * Property Practitioner / Co-signature block).
 *
 * It is a SEPARATE single document (NOT appended to the Mandatory Disclosure);
 * web packs compose it alongside the mandate + MDF as an independent doc.
 *
 * Blade: resources/views/docuperfect/web-templates/cds/template-120.blade.php.
 * Idempotent: find-or-create the active row by name; document_type resolved by
 * stable slug (find-or-create) so the FK never breaks on a fresh DB.
 */
class HfcAddendumBEsignSeeder extends Seeder
{
    public const TEMPLATE_NAME = 'HFC Addendum B';
    private const BLADE = 'docuperfect.web-templates.cds.template-120';

    public function run(): void
    {
        $documentTypeId = $this->documentTypeId('addendum', 'Addendum', 'shared');

        $row = [
            'name'                  => self::TEMPLATE_NAME,
            'template_type'         => 'cds',
            'render_type'           => 'web',
            'blade_view'            => self::BLADE,
            'category'              => 'sales',
            'document_type_id'      => $documentTypeId,
            'page_count'            => 1,
            'allowed_delivery_modes' => 'esign,wet_ink,download',
            'is_global'             => 1,
            'is_esign'              => 1,
            'party_mode'            => 'shared',
            'header_display'        => 'first_page',
            'signing_parties'       => json_encode(['owner_party', 'acquiring_party', 'agent']),
            'field_mappings'        => json_encode([]),
            'updated_at'            => now(),
        ];

        $existingId = DB::table('docuperfect_templates')
            ->where('name', self::TEMPLATE_NAME)
            ->where('template_type', 'cds')
            ->whereNull('deleted_at')
            ->value('id');

        if ($existingId) {
            DB::table('docuperfect_templates')->where('id', $existingId)->update($row);
        } else {
            DB::table('docuperfect_templates')->insert($row + ['created_at' => now()]);
        }
    }

    private function documentTypeId(string $slug, string $label, string $grouping): int
    {
        $id = DB::table('document_types')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($id) {
            return (int) $id;
        }

        return (int) DB::table('document_types')->insertGetId([
            'slug'       => $slug,
            'label'      => $label,
            'grouping'   => $grouping,
            'is_active'  => 1,
            'sort_order' => (int) DB::table('document_types')->max('sort_order') + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
