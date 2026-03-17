<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_matches', function (Blueprint $table) {
            $table->string('share_token', 64)->nullable()->unique()->after('id');
        });

        // Backfill existing rows (use DB query, not Eloquent, to avoid SoftDeletes scope issue during migration)
        $rows = \Illuminate\Support\Facades\DB::table('contact_matches')->whereNull('share_token')->get();
        foreach ($rows as $row) {
            \Illuminate\Support\Facades\DB::table('contact_matches')
                ->where('id', $row->id)
                ->update(['share_token' => Str::random(48)]);
        }
    }

    public function down(): void
    {
        Schema::table('contact_matches', function (Blueprint $table) {
            $table->dropColumn('share_token');
        });
    }
};
