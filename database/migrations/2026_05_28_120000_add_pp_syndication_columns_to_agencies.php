<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $t) {
            $t->boolean('pp_enabled')->default(false)->after('p24_last_sync_error');
            $t->string('pp_username')->nullable()->after('pp_enabled');
            $t->text('pp_password')->nullable()->after('pp_username'); // encrypted
            $t->string('pp_branch_guid', 64)->nullable()->after('pp_password');
            $t->string('pp_wsdl')->nullable()->after('pp_branch_guid');
            $t->boolean('pp_sandbox')->default(true)->after('pp_wsdl');
            $t->string('pp_image_base_url')->nullable()->after('pp_sandbox');
            $t->text('pp_webhook_secret')->nullable()->after('pp_image_base_url'); // encrypted
            $t->text('pp_last_sync_error')->nullable()->after('pp_webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $t) {
            $t->dropColumn([
                'pp_enabled', 'pp_username', 'pp_password', 'pp_branch_guid',
                'pp_wsdl', 'pp_sandbox', 'pp_image_base_url',
                'pp_webhook_secret', 'pp_last_sync_error',
            ]);
        });
    }
};
