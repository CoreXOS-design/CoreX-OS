<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->boolean('fica_required')->default(false)->after('signing_method');
            $table->unsignedBigInteger('contact_id')->nullable()->after('fica_required');
            $table->unsignedBigInteger('fica_submission_id')->nullable()->after('contact_id');

            $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
            $table->foreign('fica_submission_id')->references('id')->on('fica_submissions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropForeign(['contact_id']);
            $table->dropForeign(['fica_submission_id']);
            $table->dropColumn(['fica_required', 'contact_id', 'fica_submission_id']);
        });
    }
};
