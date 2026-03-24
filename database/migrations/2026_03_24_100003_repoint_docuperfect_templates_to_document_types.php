<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Build mapping: old docuperfect_document_types.id → new document_types.id
        if (!Schema::hasTable('docuperfect_document_types')) {
            return;
        }

        $oldTypes = DB::table('docuperfect_document_types')->get();

        // Map docuperfect names to the slugs used in document_types
        $nameToSlug = [
            'Mandates'           => 'mandate',
            'OTPs'               => 'offer_to_purchase',
            'Addendums'          => 'addendum',
            'Condition Reports'  => 'condition_report',
            'FICA'               => 'fica',
            'Rental Agreements'  => 'rental_agreement',
            'Other'              => 'other',
        ];

        $idMap = []; // old_id => new_id
        foreach ($oldTypes as $old) {
            $slug = $nameToSlug[$old->name] ?? Str::slug($old->name, '_');
            $newRow = DB::table('document_types')->where('slug', $slug)->first();
            if ($newRow) {
                $idMap[$old->id] = $newRow->id;
            }
        }

        // --- Repoint docuperfect_templates ---
        // Drop the old FK first
        Schema::table('docuperfect_templates', function (Blueprint $table) {
            $table->dropForeign(['document_type_id']);
        });

        // Update the ID values
        foreach ($idMap as $oldId => $newId) {
            DB::table('docuperfect_templates')
                ->where('document_type_id', $oldId)
                ->update(['document_type_id' => $newId]);
        }

        // Null out any unmapped values
        $oldIds = array_keys($idMap);
        if (!empty($oldIds)) {
            DB::table('docuperfect_templates')
                ->whereNotNull('document_type_id')
                ->whereNotIn('document_type_id', array_values($idMap))
                ->update(['document_type_id' => null]);
        }

        // Add new FK
        Schema::table('docuperfect_templates', function (Blueprint $table) {
            $table->foreign('document_type_id')->references('id')->on('document_types')->onDelete('set null');
        });

        // --- Repoint docuperfect_pack_slots ---
        if (Schema::hasColumn('docuperfect_pack_slots', 'document_type_id')) {
            Schema::table('docuperfect_pack_slots', function (Blueprint $table) {
                $table->dropForeign(['document_type_id']);
            });

            foreach ($idMap as $oldId => $newId) {
                DB::table('docuperfect_pack_slots')
                    ->where('document_type_id', $oldId)
                    ->update(['document_type_id' => $newId]);
            }

            if (!empty($oldIds)) {
                DB::table('docuperfect_pack_slots')
                    ->whereNotNull('document_type_id')
                    ->whereNotIn('document_type_id', array_values($idMap))
                    ->update(['document_type_id' => null]);
            }

            Schema::table('docuperfect_pack_slots', function (Blueprint $table) {
                $table->foreign('document_type_id')->references('id')->on('document_types')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        // Revert FKs back to docuperfect_document_types
        if (!Schema::hasTable('docuperfect_document_types')) {
            return;
        }

        Schema::table('docuperfect_templates', function (Blueprint $table) {
            $table->dropForeign(['document_type_id']);
        });

        // Reverse map: document_types slug → docuperfect_document_types id
        $nameToSlug = [
            'Mandates'           => 'mandate',
            'OTPs'               => 'offer_to_purchase',
            'Addendums'          => 'addendum',
            'Condition Reports'  => 'condition_report',
            'FICA'               => 'fica',
            'Rental Agreements'  => 'rental_agreement',
            'Other'              => 'other',
        ];

        $reverseMap = [];
        $oldTypes = DB::table('docuperfect_document_types')->get();
        foreach ($oldTypes as $old) {
            $slug = $nameToSlug[$old->name] ?? \Illuminate\Support\Str::slug($old->name, '_');
            $newRow = DB::table('document_types')->where('slug', $slug)->first();
            if ($newRow) {
                $reverseMap[$newRow->id] = $old->id;
            }
        }

        foreach ($reverseMap as $newId => $oldId) {
            DB::table('docuperfect_templates')
                ->where('document_type_id', $newId)
                ->update(['document_type_id' => $oldId]);
        }

        Schema::table('docuperfect_templates', function (Blueprint $table) {
            $table->foreign('document_type_id')->references('id')->on('docuperfect_document_types')->onDelete('set null');
        });

        if (Schema::hasColumn('docuperfect_pack_slots', 'document_type_id')) {
            Schema::table('docuperfect_pack_slots', function (Blueprint $table) {
                $table->dropForeign(['document_type_id']);
            });

            foreach ($reverseMap as $newId => $oldId) {
                DB::table('docuperfect_pack_slots')
                    ->where('document_type_id', $newId)
                    ->update(['document_type_id' => $oldId]);
            }

            Schema::table('docuperfect_pack_slots', function (Blueprint $table) {
                $table->foreign('document_type_id')->references('id')->on('docuperfect_document_types')->onDelete('set null');
            });
        }
    }
};
