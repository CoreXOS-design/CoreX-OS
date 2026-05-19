<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Reproduces docuperfect_field_groups from a byte-for-byte capture of the
 * real nexus_os groups (database/seeders/data/field-groups.json) so
 * demo:seed builds them on a fresh nexus_os_demo identically.
 *
 * Two hard-coded-id hazards are removed:
 *  1. Named fields are resolved by the (source_type, source_column,
 *     source_contact_type) TRIPLE via nf() — find-or-create, never a
 *     hard-coded named_field_id. (Identical to MarketingPermissionEsignSeeder.)
 *  2. The group `id` is PRESERVED from the fixture. e-sign template
 *     fixtures reference a group by fieldGroupId (e.g. Marketing Permission
 *     + Exclusive Authority use fieldGroupId=8 → "Seller Name Surname ID").
 *     Keeping the id stable means those references resolve on any fresh DB
 *     without rewriting the template captures.
 *
 * Idempotent: each group is upserted by its stable id (find-or-create, NO
 * truncate — re-run never duplicates, never wipes user-created groups).
 * created_at is set only on insert (raw query builder has no timestamp
 * magic; a NULL created_at breaks the Field Groups screen).
 */
class FieldGroupSeeder extends Seeder
{
    private const DATA_FILE = 'database/seeders/data/field-groups.json';

    /**
     * Find-or-create a docuperfect_named_fields row by its source triple,
     * returning its id. Mirrors MarketingPermissionEsignSeeder::nf so
     * resolution is stable across environments without hard-coded ids.
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
            throw new \RuntimeException('FieldGroupSeeder: missing data file ' . self::DATA_FILE);
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || empty($data['groups'])) {
            throw new \RuntimeException('FieldGroupSeeder: data file is invalid JSON.');
        }

        // Any user for created_by (global groups). No users → nothing to do.
        $userId = DB::table('users')->where('is_active', 1)->value('id')
            ?? DB::table('users')->value('id');
        if (! $userId) {
            return;
        }

        foreach ($data['groups'] as $g) {
            // Resolve each member field by its stable triple → this DB's id.
            $resolvedFields = [];
            foreach (($g['fields'] ?? []) as $f) {
                $resolvedFields[] = [
                    'named_field_id' => $this->nf($f['nf']),
                    'label_override' => $f['label_override'] ?? null,
                ];
            }

            $values = [
                'name'        => $g['name'],
                'description' => $g['description'] ?? null,
                'fields'      => json_encode($resolvedFields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'layout'      => $g['layout'] ?? 'horizontal',
                'is_global'   => ! empty($g['is_global']),
                'agency_id'   => $g['agency_id'] ?? null,
                'sort_order'  => (int) ($g['sort_order'] ?? 0),
                'created_by'  => $userId,
                'updated_at'  => now(),
            ];

            // Idempotent + id-stable: key by the fixture id so e-sign
            // template fieldGroupId references stay valid. created_at only
            // on insert (NULL created_at breaks the Field Groups screen).
            $exists = DB::table('docuperfect_field_groups')->where('id', $g['id'])->exists();
            if ($exists) {
                DB::table('docuperfect_field_groups')->where('id', $g['id'])->update($values);
            } else {
                DB::table('docuperfect_field_groups')->insert(
                    $values + ['id' => $g['id'], 'created_at' => now()]
                );
            }
        }
    }
}
