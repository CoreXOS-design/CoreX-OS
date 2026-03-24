<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migrate existing contact_documents and property_files into the unified documents table.
 * Old tables kept for rollback safety. Drop in future cleanup migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // 1. Migrate contact_documents → documents + document_contacts + document_properties
        $contactDocs = DB::table('contact_documents')->whereNull('deleted_at')->get();

        foreach ($contactDocs as $cd) {
            $docId = DB::table('documents')->insertGetId([
                'original_name'    => $cd->original_name,
                'storage_path'     => $cd->storage_path,
                'disk'             => 'local',
                'mime_type'        => $cd->mime_type,
                'size'             => $cd->size ?? 0,
                'document_type_id' => $cd->document_type_id,
                'source_type'      => $cd->source_type ?? 'upload',
                'uploaded_by'      => $cd->uploaded_by_user_id,
                'created_at'       => $cd->created_at,
                'updated_at'       => $cd->updated_at,
            ]);

            // Link to contact
            DB::table('document_contacts')->insert([
                'document_id' => $docId,
                'contact_id'  => $cd->contact_id,
                'party_role'  => null,
                'created_at'  => $cd->created_at,
            ]);

            // Link to property if tagged
            if ($cd->property_id) {
                DB::table('document_properties')->insert([
                    'document_id' => $docId,
                    'property_id' => $cd->property_id,
                    'created_at'  => $cd->created_at,
                ]);
            }
        }

        // 2. Migrate property_files → documents + document_properties + document_contacts
        // Check for duplicates by storage_path (e-signed files may exist in both tables)
        $propertyFiles = DB::table('property_files')->whereNull('deleted_at')->get();

        foreach ($propertyFiles as $pf) {
            // Check if same storage_path already migrated from contact_documents
            $existing = DB::table('documents')->where('storage_path', $pf->path)->first();

            if ($existing) {
                $docId = $existing->id;
            } else {
                $docId = DB::table('documents')->insertGetId([
                    'original_name'    => $pf->name,
                    'storage_path'     => $pf->path,
                    'disk'             => 'public',
                    'mime_type'        => $pf->mime_type,
                    'size'             => $pf->size ?? 0,
                    'document_type_id' => $pf->document_type_id,
                    'source_type'      => $pf->source_type ?? 'upload',
                    'uploaded_by'      => $pf->user_id,
                    'created_at'       => $pf->created_at,
                    'updated_at'       => $pf->updated_at,
                ]);
            }

            // Link to property (if not already linked)
            $propLinked = DB::table('document_properties')
                ->where('document_id', $docId)
                ->where('property_id', $pf->property_id)
                ->exists();
            if (!$propLinked) {
                DB::table('document_properties')->insert([
                    'document_id' => $docId,
                    'property_id' => $pf->property_id,
                    'created_at'  => $pf->created_at,
                ]);
            }

            // Link to contact if tagged (and not already linked)
            if ($pf->contact_id) {
                $contactLinked = DB::table('document_contacts')
                    ->where('document_id', $docId)
                    ->where('contact_id', $pf->contact_id)
                    ->exists();
                if (!$contactLinked) {
                    DB::table('document_contacts')->insert([
                        'document_id' => $docId,
                        'contact_id'  => $pf->contact_id,
                        'party_role'  => null,
                        'created_at'  => $pf->created_at,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Data migration rollback: just truncate the new tables
        // Old tables are preserved as the source of truth for rollback
        DB::table('document_properties')->truncate();
        DB::table('document_contacts')->truncate();
        DB::table('documents')->delete(); // use delete not truncate due to FK
    }
};
