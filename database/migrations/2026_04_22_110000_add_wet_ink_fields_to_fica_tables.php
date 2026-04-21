<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fica_submissions', function (Blueprint $table) {
            if (!Schema::hasColumn('fica_submissions', 'intake_type')) {
                $table->enum('intake_type', ['online', 'wet_ink'])
                    ->default('online')
                    ->after('status');
            }
            if (!Schema::hasColumn('fica_submissions', 'wet_ink_received_date')) {
                $table->date('wet_ink_received_date')
                    ->nullable()
                    ->after('intake_type');
            }
            if (!Schema::hasColumn('fica_submissions', 'wet_ink_confirmed_by')) {
                $table->foreignId('wet_ink_confirmed_by')
                    ->nullable()
                    ->after('wet_ink_received_date')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });

        Schema::table('fica_documents', function (Blueprint $table) {
            if (!Schema::hasColumn('fica_documents', 'deleted_at')) {
                $table->softDeletes();
            }
            if (!Schema::hasColumn('fica_documents', 'uploaded_by')) {
                $table->foreignId('uploaded_by')
                    ->nullable()
                    ->after('reviewed_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('fica_submissions', function (Blueprint $table) {
            $table->dropForeign(['wet_ink_confirmed_by']);
            $table->dropColumn(['intake_type', 'wet_ink_received_date', 'wet_ink_confirmed_by']);
        });

        Schema::table('fica_documents', function (Blueprint $table) {
            $table->dropForeign(['uploaded_by']);
            $table->dropColumn(['deleted_at', 'uploaded_by']);
        });
    }
};
