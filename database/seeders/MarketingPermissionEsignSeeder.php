<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Reproduces the CDS "Marketing Permission Esign" template (the one Johan
 * built + verified in the CDS builder on local) so demo:seed / migrate:fresh
 * rebuilds it identically instead of losing it as untracked DB data.
 *
 * The exact local state of docuperfect_templates#125 (cds_json, fields_json,
 * field_mappings, signing_parties, editor_state, all flags) is captured in
 * database/seeders/data/marketing-permission-esign.json. namedFieldId values
 * in that capture are LOCAL ids; on a fresh DB those ids differ, so this
 * seeder find-or-creates each referenced named field by its (source_type,
 * source_column, source_contact_type) triple and rewrites the field_mappings
 * references to the resolved ids. That keeps Fill & Review population, the
 * seller loop, and the commission/price Step-4 linkage working exactly as on
 * local. Idempotent: updates the existing active row, never duplicates.
 *
 * The blade view (resources/views/docuperfect/web-templates/cds/template-125
 * .blade.php) is committed in the repo — a DB reset never touches view files.
 */
class MarketingPermissionEsignSeeder extends Seeder
{
    public const TEMPLATE_NAME = 'Marketing Permission Esign';

    private const DATA_FILE = 'database/seeders/data/marketing-permission-esign.json';

    /**
     * Find-or-create a docuperfect_named_fields row by its source triple,
     * returning its id. Mirrors MarketingPermissionV6Seeder::nf so source
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

    public function run(): void
    {
        $path = base_path(self::DATA_FILE);
        if (! is_file($path)) {
            throw new \RuntimeException('MarketingPermissionEsignSeeder: missing data file ' . self::DATA_FILE);
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || empty($data['columns'])) {
            throw new \RuntimeException('MarketingPermissionEsignSeeder: data file is invalid JSON.');
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
