<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->json('whistleblow_approver_user_ids')->nullable()->after('feedback_recipients');
            $table->string('whistleblow_compliance_officer_email')->nullable()->after('whistleblow_approver_user_ids');
            $table->string('whistleblow_ppra_recipient_email')->nullable()->default('complaints@theppra.org.za')->after('whistleblow_compliance_officer_email');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn([
                'whistleblow_approver_user_ids',
                'whistleblow_compliance_officer_email',
                'whistleblow_ppra_recipient_email',
            ]);
        });
    }
};
