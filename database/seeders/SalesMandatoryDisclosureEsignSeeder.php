<?php

namespace Database\Seeders;

use App\Contracts\SyncableReferenceSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Reproduces the CDS "Sales Mandatory Disclosure" template — the LIVE
 * docuperfect_templates#123 row Johan built + verified (blade_view
 * docuperfect.web-templates.cds.template-123), captured byte-for-byte to
 * database/seeders/data/sales-mandatory-disclosure-esign.json so
 * demo:seed / migrate:fresh rebuilds it identically.
 *
 * This is the §19/§20-fixed CDS web template. It deliberately does NOT
 * reuse SalesMandatoryDisclosureSeeder, which targets a different blade
 * (docuperfect.web-templates.sales-mandatory-disclosure) WITHOUT this
 * session's per-document pagination / disclosure-key fixes.
 *
 * document_type_id is corrected to 11 (disclosure) in the capture — the
 * live row was mis-tagged 5 (Condition Report). Same find-or-create
 * named-field resolution as MarketingPermissionEsignSeeder so the
 * field_mappings ids stay stable across environments. Idempotent: updates
 * the existing active row, never duplicates.
 *
 * The blade view (resources/views/docuperfect/web-templates/cds/template-123
 * .blade.php) is committed in the repo — a DB reset never touches view files.
 */
class SalesMandatoryDisclosureEsignSeeder extends Seeder implements SyncableReferenceSeeder
{
    public const TEMPLATE_NAME = 'Sales Mandatory Disclosure';

    private const DATA_FILE = 'database/seeders/data/sales-mandatory-disclosure-esign.json';

    /**
     * Find-or-create a docuperfect_named_fields row by its source triple,
     * returning its id. Mirrors MarketingPermissionEsignSeeder::nf so source
     * resolution stays stable across environments without hard-coded ids.
     */
    private function nf(array $ref): int
    {
        $sourceType   = $ref['source_type'];
        $sourceColumn = $ref['source_column'];
        $contactType  = $ref['source_contact_type'] ?? null;

        $q = DB::table('docuperfect_named_fields')
            ->where('source_type', $sourceType)
            ->where('source_column', $sourceColumn)
            ->whereNull('deleted_at');
        $q = $contactType === null
            ? $q->whereNull('source_contact_type')
            : $q->where('source_contact_type', $contactType);

        $id = $q->value('id');
        if ($id) {
            return (int) $id;
        }

        return (int) DB::table('docuperfect_named_fields')->insertGetId([
            'name'                => $ref['name'] ?? ($sourceType . '.' . $sourceColumn),
            'field_type'          => $ref['field_type'] ?? 'text',
            'source_type'         => $sourceType,
            'source_column'       => $sourceColumn,
            'source_contact_type' => $contactType,
            'sort_order'          => 900,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    /**
     * Find-or-create the document_types row for $slug, return its id.
     * Mirrors nf(): never trust a hard-coded FK id (a fresh DB may not
     * have the type → FK violation, silently swallowed by safeSeed).
     */
    private function documentTypeId(string $slug, string $label, string $grouping = 'shared'): int
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

    public function run(): void
    {
        $path = base_path(self::DATA_FILE);
        if (! is_file($path)) {
            throw new \RuntimeException(static::class . ': missing data file ' . self::DATA_FILE);
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || empty($data['columns'])) {
            throw new \RuntimeException(static::class . ': data file is invalid JSON.');
        }

        // 1. Resolve every referenced named field on THIS database → old→new id map.
        $idMap = [];
        foreach (($data['named_field_refs'] ?? []) as $oldId => $ref) {
            $idMap[(int) $oldId] = $this->nf($ref);
        }

        // 2. Rewrite every namedFieldId in field_mappings to the resolved id.
        $fieldMappings = $data['field_mappings'] ?? [];
        $remap = function (&$node) use (&$remap, $idMap) {
            if (! is_array($node)) {
                return;
            }
            foreach ($node as $k => &$v) {
                if ($k === 'namedFieldId' && $v !== null && $v !== '' && isset($idMap[(int) $v])) {
                    $v = $idMap[(int) $v];
                } elseif (is_array($v)) {
                    $remap($v);
                }
            }
            unset($v);
        };
        $remap($fieldMappings);

        // 3. Upsert the template row (idempotent on the active, non-deleted row).
        $cols = $data['columns'];
        $row = array_merge($cols, [
            'cds_json'        => $data['cds_json']        ?? null,
            'fields_json'     => $data['fields_json']     ?? null,
            'signing_parties' => $data['signing_parties'] ?? null,
            'editor_state'    => $data['editor_state']    ?? null,
            'sections'        => ($data['sections'] ?? '') !== '' ? $data['sections'] : null,
            'field_mappings'  => json_encode($fieldMappings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'updated_at'      => now(),
        ]);
        // FK-safe: resolve document_type_id by stable SLUG (find-or-create),
        // overriding the fragile baked numeric id in the data file.
        $row['document_type_id'] = $this->documentTypeId('disclosure', 'Disclosure', 'shared');

        // 2026-08-24 (cc2, MDF live-deploy prep, per Johan) — attribution, added on top of
        // the captured JSON: `columns` never set agency_id (raw DB::table() insert, no
        // BelongsToAgency to auto-fill it — Template.php deliberately has none, see its own
        // 2026-08-15 docblock). Resolved by name, never hard-coded, so this stays correct
        // on any environment regardless of numeric id drift.
        //
        // is_global=1 (from `columns`, unchanged) is DELIBERATE, not an oversight this fix
        // should have caught: per cc6's 2026-08-24 tenant-isolation fix
        // (.ai/audits/2026-08-24-template-is-global-multitenancy-fix.md), is_global=true is
        // now understood as "every agency on the platform, forever" and was closed off as a
        // default on every USER-FACING template-creation path — but a standard regulatory
        // form every agency must receive identically (this one: PPA 22/2019 s70 / Regs 2022
        // s36) is exactly the legitimate, deliberate case that flag exists for. Confirmed
        // directly with cc6 before this was added: raw server-side writes (seeders,
        // migrations, artisan) were never in scope of that fix. agency_id is still stamped
        // here for correct attribution even though is_global bypasses the agency_id check
        // entirely at query time (Template::scopeVisibleTo() / isVisibleToAgency()).
        $row['agency_id'] = \App\Models\Agency::where('name', 'Home Finders Coastal')->value('id');

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
}
