<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presentation_links', function (Blueprint $table) {
            $table->enum('extraction_status', ['pending', 'ok', 'failed'])->default('pending')->after('suburb');
            $table->json('extracted_json')->nullable()->after('extraction_status');
            $table->text('extraction_error')->nullable()->after('extracted_json');
            $table->timestamp('extracted_at')->nullable()->after('extraction_error');
            $table->json('override_json')->nullable()->after('extracted_at');
            $table->unsignedBigInteger('override_by_user_id')->nullable()->after('override_json');
            $table->timestamp('override_at')->nullable()->after('override_by_user_id');

            $table->foreign('override_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('presentation_links', function (Blueprint $table) {
            $table->dropForeign(['override_by_user_id']);
            $table->dropColumn([
                'extraction_status',
                'extracted_json',
                'extraction_error',
                'extracted_at',
                'override_json',
                'override_by_user_id',
                'override_at',
            ]);
        });
    }
};
