<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Soft-delete the placeholder training courses that were stubs
        // before the RMCP acknowledgement module existed
        DB::table('training_courses')
            ->whereIn('title', ['FICA Compliance Training', 'RMCP Overview'])
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);
    }

    public function down(): void
    {
        DB::table('training_courses')
            ->whereIn('title', ['FICA Compliance Training', 'RMCP Overview'])
            ->update(['deleted_at' => null]);
    }
};
