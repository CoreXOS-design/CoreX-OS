<?php

namespace Database\Seeders;

use App\Contracts\SyncableReferenceSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * "Addendum B" — a STANDALONE agency addendum e-sign template (heading
 * "ADDENDUM B" + the EXTRA INFORMATION table: registered building plans (Y/N)
 * and the four Certificates of Compliance — Electrical, Electrical Fence, Gas,
 * Entomology — each Y/N + a "when issued" date; plus a Seller / Purchaser /
 * Property Practitioner / Co-signature block).
 *
 * It is a SEPARATE single document (NOT appended to the Mandatory Disclosure);
 * web packs compose it alongside the mandate + MDF as an independent doc.
 *
 * Blade: resources/views/docuperfect/web-templates/cds/template-120.blade.php.
 * Idempotent: find-or-create the active row by blade_view (stable even across
 * a display-name rename — see 2026-08-31: the row used to be named
 * "HFC Addendum B", which is wrong for is_global=1 content every agency gets,
 * not just HFC; keying on name would have left that old row orphaned instead
 * of renaming it). document_type resolved by stable slug (find-or-create) so
 * the FK never breaks on a fresh DB.
 */
class HfcAddendumBEsignSeeder extends Seeder implements SyncableReferenceSeeder
{
    public const TEMPLATE_NAME = 'Addendum B';
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
            // is_global=1 is DELIBERATE, not an oversight — same reasoning as
            // SalesMandatoryDisclosureEsignSeeder (see its 2026-08-24 comment): this is a
            // standard HFC-authored compliance addendum every agency using it should get
            // identically, confirmed with cc6 as outside the scope of their 2026-08-24
            // tenant-isolation fix (raw server-side writes were never in scope; only
            // user-facing creation paths were). agency_id stamped for correct attribution
            // even though is_global bypasses the agency_id check at query time.
            'is_global'             => 1,
            'is_esign'              => 1,
            'party_mode'            => 'shared',
            'header_display'        => 'first_page',
            'signing_parties'       => json_encode(['owner_party', 'acquiring_party', 'agent']),
            'field_mappings'        => json_encode([]),
            'agency_id'             => \App\Models\Agency::where('name', 'Home Finders Coastal')->value('id'),
            'updated_at'            => now(),
        ];

        // blade_view is NOT unique on its own — a separate, unrelated template
        // ("Seller Mandatory Addendum") renders through this same blade file —
        // so the lookup must also pin the name (old OR new, for the rename to
        // stay idempotent) or it can silently overwrite that other row instead.
        $existingId = DB::table('docuperfect_templates')
            ->where('blade_view', self::BLADE)
            ->where('template_type', 'cds')
            ->whereIn('name', ['HFC Addendum B', self::TEMPLATE_NAME])
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
