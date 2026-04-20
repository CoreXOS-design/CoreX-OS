<?php

use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        $disk = Storage::disk('public');
        $counts = ['ffc' => 0, 'photo' => 0, 'skipped' => 0];

        // Bypass agency scope for backfill — we need all users
        $users = User::queryWithoutAgencyScope()
            ->where(function ($q) {
                $q->whereNotNull('ffc_certificate_path')
                  ->orWhereNotNull('agent_photo_path');
            })
            ->get(['id', 'agency_id', 'ffc_certificate_path', 'agent_photo_path', 'created_at', 'updated_at']);

        foreach ($users as $user) {
            // FFC certificate
            if ($user->ffc_certificate_path && $disk->exists($user->ffc_certificate_path)) {
                DB::table('user_documents')->insert([
                    'user_id'       => $user->id,
                    'agency_id'     => $user->agency_id,
                    'document_type' => 'ffc_certificate',
                    'file_path'     => $user->ffc_certificate_path,
                    'file_name'     => basename($user->ffc_certificate_path),
                    'status'        => 'pending',
                    'uploaded_by'   => $user->id,
                    'created_at'    => $user->updated_at ?? $user->created_at,
                    'updated_at'    => $user->updated_at ?? $user->created_at,
                ]);
                $counts['ffc']++;
            } elseif ($user->ffc_certificate_path) {
                $counts['skipped']++;
            }

            // Profile photo
            if ($user->agent_photo_path && $disk->exists($user->agent_photo_path)) {
                DB::table('user_documents')->insert([
                    'user_id'       => $user->id,
                    'agency_id'     => $user->agency_id,
                    'document_type' => 'profile_photo',
                    'file_path'     => $user->agent_photo_path,
                    'file_name'     => basename($user->agent_photo_path),
                    'status'        => 'verified',
                    'verified_at'   => $user->updated_at ?? $user->created_at,
                    'verified_by'   => $user->id,
                    'uploaded_by'   => $user->id,
                    'created_at'    => $user->updated_at ?? $user->created_at,
                    'updated_at'    => $user->updated_at ?? $user->created_at,
                ]);
                $counts['photo']++;
            }
        }

        // Log backfill results (visible in migration output)
        logger()->info('UserDocument backfill complete', $counts);
    }

    public function down(): void
    {
        // Safe no-op — rows remain if rolled back; users can re-upload
    }
};
