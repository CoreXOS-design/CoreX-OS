<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presentation_uploads', function (Blueprint $table) {
            $table->timestamp('extracted_at')->nullable()->after('extraction_status');
            $table->text('extraction_error')->nullable()->after('extracted_at');
            $table->json('override_json')->nullable()->after('extraction_error');
            $table->unsignedBigInteger('override_by_user_id')->nullable()->after('override_json');
            $table->timestamp('override_at')->nullable()->after('override_by_user_id');

            $table->foreign('override_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('presentation_uploads', function (Blueprint $table) {
            $table->dropForeign(['override_by_user_id']);
            $table->dropColumn([
                'extracted_at',
                'extraction_error',
                'override_json',
                'override_by_user_id',
                'override_at',
            ]);
        });
    }
};
