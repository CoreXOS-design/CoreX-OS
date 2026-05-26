<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Canonical property type set. Order matters for display.
     */
    private array $canonical = [
        'House',
        'Apartment / Flat',
        'Townhouse',
        'Vacant Land / Plot',
        'Farm',
        'Commercial Property',
        'Industrial Property',
    ];

    /**
     * Legacy name -> canonical name. Used to migrate property rows and
     * to recognise existing setting items that should be renamed instead
     * of soft-deleted.
     */
    private array $aliasMap = [
        'House'             => 'House',
        'Apartment'         => 'Apartment / Flat',
        'Flat'              => 'Apartment / Flat',
        'Studio Apartment'  => 'Apartment / Flat',
        'Penthouse'         => 'Apartment / Flat',
        'Townhouse'         => 'Townhouse',
        'Cluster'           => 'Townhouse',
        'Duet'              => 'Townhouse',
        'Duplex'            => 'Townhouse',
        'Vacant Land'       => 'Vacant Land / Plot',
        'Plot'              => 'Vacant Land / Plot',
        'Farm'              => 'Farm',
        'Small Holding'     => 'Farm',
        'Smallholding'      => 'Farm',
        'Commercial'        => 'Commercial Property',
        'Commercial Property' => 'Commercial Property',
        'Industrial'        => 'Industrial Property',
        'Industrial Property' => 'Industrial Property',
    ];

    public function up(): void
    {
        $now = now();

        // --- 1. property_setting_items (group=property_type) ---------------
        if (Schema::hasTable('property_setting_items')) {
            $items = DB::table('property_setting_items')
                ->where('group', 'property_type')
                ->whereNull('deleted_at')
                ->get();

            $kept = []; // canonical name => row id

            foreach ($items as $row) {
                $canonical = $this->aliasMap[$row->name] ?? null;

                if ($canonical !== null && ! isset($kept[$canonical])) {
                    DB::table('property_setting_items')
                        ->where('id', $row->id)
                        ->update([
                            'name'       => $canonical,
                            'sort_order' => array_search($canonical, $this->canonical, true),
                            'is_default' => 1,
                            'active'     => 1,
                            'updated_at' => $now,
                        ]);
                    $kept[$canonical] = $row->id;
                } else {
                    DB::table('property_setting_items')
                        ->where('id', $row->id)
                        ->update([
                            'active'     => 0,
                            'deleted_at' => $now,
                            'updated_at' => $now,
                        ]);
                }
            }

            foreach ($this->canonical as $i => $name) {
                if (isset($kept[$name])) {
                    continue;
                }
                DB::table('property_setting_items')->insert([
                    'group'      => 'property_type',
                    'name'       => $name,
                    'sort_order' => $i,
                    'is_default' => 1,
                    'active'     => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // --- 2. property_type_options (per-agency) -------------------------
        if (Schema::hasTable('property_type_options')) {
            $agencies = DB::table('property_type_options')
                ->whereNull('deleted_at')
                ->distinct()
                ->pluck('agency_id');

            foreach ($agencies as $agencyId) {
                $rows = DB::table('property_type_options')
                    ->where('agency_id', $agencyId)
                    ->whereNull('deleted_at')
                    ->get();

                $keptSlugs = [];

                foreach ($rows as $row) {
                    $canonical = $this->aliasMap[$row->name] ?? null;
                    $canonicalSlug = $canonical ? Str::slug($canonical) : null;

                    if ($canonical !== null && ! isset($keptSlugs[$canonicalSlug])) {
                        DB::table('property_type_options')
                            ->where('id', $row->id)
                            ->update([
                                'name'          => $canonical,
                                'slug'          => $canonicalSlug,
                                'display_order' => array_search($canonical, $this->canonical, true) + 1,
                                'is_active'     => 1,
                                'updated_at'    => $now,
                            ]);
                        $keptSlugs[$canonicalSlug] = $row->id;
                    } else {
                        DB::table('property_type_options')
                            ->where('id', $row->id)
                            ->update([
                                'is_active'  => 0,
                                'deleted_at' => $now,
                                'updated_at' => $now,
                            ]);
                    }
                }

                foreach ($this->canonical as $i => $name) {
                    $slug = Str::slug($name);
                    if (isset($keptSlugs[$slug])) {
                        continue;
                    }
                    DB::table('property_type_options')->insert([
                        'agency_id'     => $agencyId,
                        'name'          => $name,
                        'slug'          => $slug,
                        'display_order' => $i + 1,
                        'is_active'     => 1,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]);
                }
            }
        }

        // --- 3. Existing properties.property_type values -------------------
        if (Schema::hasTable('properties') && Schema::hasColumn('properties', 'property_type')) {
            foreach ($this->aliasMap as $from => $to) {
                if ($from === $to) {
                    continue;
                }
                DB::table('properties')
                    ->where('property_type', $from)
                    ->update(['property_type' => $to]);
            }
        }
    }

    public function down(): void
    {
        // Irreversible normalisation. No-op.
    }
};
