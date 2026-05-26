<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ─── 1. Field schema for Marketing Permission v11 ───────────────────
        $fieldSchema = [
            ['key' => 'property.erf',            'label' => 'Erf / Unit',         'type' => 'text',         'filled_by' => 'agent', 'editable_by' => ['agent']],
            ['key' => 'property.street_address', 'label' => 'Street address',     'type' => 'text',         'filled_by' => 'agent', 'editable_by' => ['agent']],
            ['key' => 'property.suburb',         'label' => 'Suburb / Complex',   'type' => 'text',         'filled_by' => 'agent', 'editable_by' => ['agent']],
            ['key' => 'property.district',       'label' => 'District',           'type' => 'text',         'filled_by' => 'agent', 'editable_by' => ['agent']],
            ['key' => 'owner_1.full_name',       'label' => 'Owner 1 full name',  'type' => 'text',         'filled_by' => 'agent', 'editable_by' => ['agent', 'owner_party']],
            ['key' => 'owner_1.id_number',       'label' => 'Owner 1 ID',         'type' => 'id_za',        'filled_by' => 'agent', 'editable_by' => ['agent', 'owner_party']],
            ['key' => 'owner_1.email',           'label' => 'Owner 1 email',      'type' => 'email',        'filled_by' => 'agent', 'editable_by' => ['agent', 'owner_party']],
            ['key' => 'owner_1.cell',            'label' => 'Owner 1 cell',       'type' => 'phone_za',     'filled_by' => 'agent', 'editable_by' => ['agent', 'owner_party']],
            ['key' => 'owner_2.full_name',       'label' => 'Owner 2 full name',  'type' => 'text',         'filled_by' => 'agent', 'editable_by' => ['agent', 'owner_party'], 'required' => false],
            ['key' => 'owner_2.id_number',       'label' => 'Owner 2 ID',         'type' => 'id_za',        'filled_by' => 'agent', 'editable_by' => ['agent', 'owner_party'], 'required' => false],
            ['key' => 'owner_2.email',           'label' => 'Owner 2 email',      'type' => 'email',        'filled_by' => 'agent', 'editable_by' => ['agent', 'owner_party'], 'required' => false],
            ['key' => 'owner_2.cell',            'label' => 'Owner 2 cell',       'type' => 'phone_za',     'filled_by' => 'agent', 'editable_by' => ['agent', 'owner_party'], 'required' => false],
            ['key' => 'terms.transaction_type',  'label' => 'Sale or Lease',      'type' => 'enum',         'values' => ['sale', 'lease'], 'filled_by' => 'agent', 'editable_by' => ['agent']],
            ['key' => 'terms.price',             'label' => 'Price / Rental',     'type' => 'currency_zar', 'filled_by' => 'agent', 'editable_by' => ['agent', 'owner_party']],
            ['key' => 'terms.commission_pct',    'label' => 'Commission %',       'type' => 'percentage',   'filled_by' => 'agent', 'editable_by' => ['agent', 'owner_party']],
        ];

        // ─── 2. Update template-116 record ──────────────────────────────────
        if (!DB::table('docuperfect_templates')->where('id', 116)->exists()) {
            // Template 116 doesn't exist in this environment — skip the entire migration.
            // (Local dev DBs may not have the prod template seed data.)
            return;
        }
        DB::table('docuperfect_templates')->where('id', 116)->update([
            'name'                   => 'Marketing Permission v11',
            'template_type'          => 'mandate',
            'is_esign'               => true,
            'is_global'              => false,
            'document_type_id'       => 1,
            'category'               => 'sales',
            'party_mode'             => 'shared',
            'signing_parties'        => json_encode(['owner_party', 'agent']),
            'allowed_delivery_modes' => 'esign,wet_ink,download',
            'field_mappings'         => json_encode($fieldSchema),
            'updated_at'             => now(),
        ]);

        // ─── 3. Correct HFC agency record ───────────────────────────────────
        $hfc = DB::table('agencies')->where('id', 1)->first();
        $updates = [];

        if (stripos($hfc->trading_name ?? '', 'Pty') === false) {
            $updates['trading_name'] = 'Johan and Elize Properties (Pty) Ltd t/a Home Finders Coastal';
        }
        if (empty($hfc->phone_label)) {
            $updates['phone_label'] = 'Elize Reichel Cell';
        }
        if (empty($hfc->phone_secondary_label)) {
            $updates['phone_secondary_label'] = 'Johan Reichel Cell';
        }
        if (empty($hfc->phone_secondary)) {
            $updates['phone_secondary'] = '076 618 5578';
        }

        if (!empty($updates)) {
            $updates['updated_at'] = now();
            DB::table('agencies')->where('id', 1)->update($updates);
        }

        // ─── 4. Seed Buyer + Seller signing parties ─────────────────────────
        if (!DB::table('agency_signing_parties')->where('agency_id', 1)->where('name', 'Seller')->exists()) {
            DB::table('agency_signing_parties')->insert(
                ['agency_id' => 1, 'name' => 'Seller', 'sort_order' => 4, 'created_at' => now(), 'updated_at' => now()]
            );
        }
        if (!DB::table('agency_signing_parties')->where('agency_id', 1)->where('name', 'Buyer')->exists()) {
            DB::table('agency_signing_parties')->insert(
                ['agency_id' => 1, 'name' => 'Buyer', 'sort_order' => 5, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        // ─── 5. Create "Sales Mandate Pack" ─────────────────────────────────
        $packId = DB::table('web_packs')->where('agency_id', 1)
            ->where('name', 'Sales Mandate Pack')
            ->value('id');

        if (!$packId) {
            $packId = DB::table('web_packs')->insertGetId([
                'agency_id'  => 1,
                'created_by' => 22,
                'name'        => 'Sales Mandate Pack',
                'description' => 'Marketing Permission + Mandatory Disclosure + Addendum B',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        // Soft-delete existing items and re-insert clean set
        DB::table('web_pack_items')->where('web_pack_id', $packId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        $candidateItems = [
            ['template_id' => 116, 'sort_order' => 0,  'slot_label' => 'Marketing Permission v11'],
            ['template_id' => 117, 'sort_order' => 10, 'slot_label' => 'Sales Mandatory Disclosure'],
            ['template_id' => 119, 'sort_order' => 20, 'slot_label' => 'Sales Addendum B'],
        ];
        $existingTemplateIds = DB::table('docuperfect_templates')
            ->whereIn('id', array_column($candidateItems, 'template_id'))
            ->pluck('id')->all();

        $rows = [];
        foreach ($candidateItems as $item) {
            if (!in_array($item['template_id'], $existingTemplateIds, true)) {
                continue;
            }
            $rows[] = [
                'web_pack_id' => $packId,
                'template_id' => $item['template_id'],
                'sort_order'  => $item['sort_order'],
                'slot_type'   => 'required',
                'slot_group'  => null,
                'slot_label'  => $item['slot_label'],
                'created_at'  => now(),
                'updated_at'  => now(),
            ];
        }

        if (!empty($rows)) {
            DB::table('web_pack_items')->insert($rows);
        }
    }

    public function down(): void
    {
        // Restore template-116 to previous state
        DB::table('docuperfect_templates')->where('id', 116)->update([
            'name'                   => 'LETTING MARKETING PERMISSION',
            'template_type'          => 'cds',
            'is_esign'               => true,
            'is_global'              => true,
            'document_type_id'       => null,
            'category'               => null,
            'updated_at'             => now(),
        ]);

        // Remove Seller/Buyer from signing parties
        DB::table('agency_signing_parties')
            ->where('agency_id', 1)
            ->whereIn('name', ['Seller', 'Buyer'])
            ->delete();

        // Soft-delete the Sales Mandate Pack
        DB::table('web_packs')
            ->where('agency_id', 1)
            ->where('name', 'Sales Mandate Pack')
            ->update(['deleted_at' => now()]);
    }
};
