<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        $users = DB::table('users')->whereNotNull('agent_photo_path')->get();

        foreach ($users as $u) {
            $existing = DB::table('user_documents')
                ->where('user_id', $u->id)
                ->where('document_type', 'profile_photo')
                ->first();

            if ($existing) {
                continue;
            }

            if (!Storage::disk('public')->exists($u->agent_photo_path)) {
                continue;
            }

            DB::table('user_documents')->insert([
                'user_id'       => $u->id,
                'agency_id'     => $u->agency_id,
                'document_type' => 'profile_photo',
                'file_path'     => $u->agent_photo_path,
                'file_name'     => basename($u->agent_photo_path),
                'status'        => 'verified',
                'uploaded_by'   => $u->id,
                'verified_at'   => now(),
                'verified_by'   => $u->id,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
    }

    public function down(): void
    {
        // No revert — backfill only
    }
};
