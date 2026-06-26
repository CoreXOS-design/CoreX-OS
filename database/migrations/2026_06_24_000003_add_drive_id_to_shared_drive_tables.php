<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shared_drive_folders', function (Blueprint $table) {
            $table->unsignedBigInteger('drive_id')->nullable()->after('agency_id');
            $table->foreign('drive_id')->references('id')->on('shared_drives')->nullOnDelete();
            $table->index(['agency_id', 'drive_id']);
        });

        Schema::table('shared_drive_files', function (Blueprint $table) {
            $table->unsignedBigInteger('drive_id')->nullable()->after('agency_id');
            $table->foreign('drive_id')->references('id')->on('shared_drives')->nullOnDelete();
            $table->index(['agency_id', 'drive_id']);
        });

        $this->backfillDefaultDrives();
    }

    /**
     * Every pre-v2 folder/file belonged to the single implicit drive. Create a
     * default "General" drive per agency that has any such rows, then stamp the
     * rows onto it so nothing is orphaned. Fresh agencies with no rows get their
     * General drive lazily on first visit (SharedDriveService::ensureDefaultDrive).
     */
    private function backfillDefaultDrives(): void
    {
        $agencyIds = DB::table('shared_drive_folders')->distinct()->pluck('agency_id')
            ->merge(DB::table('shared_drive_files')->distinct()->pluck('agency_id'))
            ->unique()
            ->values();

        foreach ($agencyIds as $agencyId) {
            // Resolve a valid creator: a user in this agency, else any user.
            $creatorId = $agencyId !== null
                ? DB::table('users')->where('agency_id', $agencyId)->value('id')
                : null;
            $creatorId = $creatorId ?: DB::table('users')->value('id');

            if (!$creatorId) {
                // No users at all — there can be no real data either; skip.
                continue;
            }

            $driveId = DB::table('shared_drives')->insertGetId([
                'agency_id'          => $agencyId,
                'name'               => 'General',
                'is_restricted'      => false,
                'is_default'         => true,
                'created_by_user_id' => $creatorId,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            $matchAgency = fn ($q) => $agencyId === null
                ? $q->whereNull('agency_id')
                : $q->where('agency_id', $agencyId);

            $matchAgency(DB::table('shared_drive_folders'))->update(['drive_id' => $driveId]);
            $matchAgency(DB::table('shared_drive_files'))->update(['drive_id' => $driveId]);
        }
    }

    public function down(): void
    {
        Schema::table('shared_drive_folders', function (Blueprint $table) {
            $table->dropForeign(['drive_id']);
            $table->dropIndex(['agency_id', 'drive_id']);
            $table->dropColumn('drive_id');
        });

        Schema::table('shared_drive_files', function (Blueprint $table) {
            $table->dropForeign(['drive_id']);
            $table->dropIndex(['agency_id', 'drive_id']);
            $table->dropColumn('drive_id');
        });
    }
};
