<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('opt_out_email')->default(false)->after('purged_reason');
            $table->boolean('opt_out_sms')->default(false)->after('opt_out_email');
            $table->boolean('opt_out_whatsapp')->default(false)->after('opt_out_sms');
            $table->boolean('opt_out_call')->default(false)->after('opt_out_whatsapp');
            $table->timestamp('last_consent_check_at')->nullable()->after('opt_out_call');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['opt_out_email', 'opt_out_sms', 'opt_out_whatsapp', 'opt_out_call', 'last_consent_check_at']);
        });
    }
};
