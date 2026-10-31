<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** MIC 5.10 (AT-519): which size the file's own R/m2 uses - `deeds` (register-backed), `advert` (lead advert) or `file` (file record). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mic_file_context', function (Blueprint $table) {
            $table->string('own_size_source', 6)->nullable()->after('own_size_basis');
        });
    }

    public function down(): void
    {
        Schema::table('mic_file_context', function (Blueprint $table) {
            $table->dropColumn('own_size_source');
        });
    }
};
