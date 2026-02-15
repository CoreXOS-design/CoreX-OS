<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->unsignedInteger('deal_no')->nullable()->after('id');
            $table->index('deal_no');
        });

        // Backfill existing records with sequential numbers (oldest first)
        $rows = DB::table('deals')->orderBy('id')->pluck('id')->all();
        $n = 1;
        foreach ($rows as $id) {
            DB::table('deals')->where('id', $id)->update(['deal_no' => $n]);
            $n++;
        }
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex(['deal_no']);
            $table->dropColumn('deal_no');
        });
    }
};
