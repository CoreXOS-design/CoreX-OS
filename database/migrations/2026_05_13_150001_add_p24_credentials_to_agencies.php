<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $t) {
            $t->string('p24_username')->nullable()->after('p24_agency_label');
            $t->text('p24_password')->nullable()->after('p24_username'); // encrypted
            $t->string('p24_user_group_id')->nullable()->after('p24_password');
            $t->boolean('p24_enabled')->default(false)->after('p24_user_group_id');
            $t->timestamp('p24_locations_synced_at')->nullable()->after('p24_enabled');
            $t->text('p24_last_sync_error')->nullable()->after('p24_locations_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $t) {
            $t->dropColumn([
                'p24_username', 'p24_password', 'p24_user_group_id',
                'p24_enabled', 'p24_locations_synced_at', 'p24_last_sync_error',
            ]);
        });
    }
};
