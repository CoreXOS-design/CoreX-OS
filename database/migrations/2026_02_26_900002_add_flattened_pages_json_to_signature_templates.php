<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signature_templates', function (Blueprint $table) {
            if (!Schema::hasColumn('signature_templates', 'flattened_pages_json')) {
                $table->text('flattened_pages_json')->nullable()->after('signed_pdf_client_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('signature_templates', function (Blueprint $table) {
            $table->dropColumn('flattened_pages_json');
        });
    }
};
