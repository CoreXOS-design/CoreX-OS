<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->boolean('require_external_access_authorization')
                ->default(false)
                ->after('is_demo');
            $table->index('require_external_access_authorization', 'agencies_req_ext_auth_idx');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropIndex('agencies_req_ext_auth_idx');
            $table->dropColumn('require_external_access_authorization');
        });
    }
};
