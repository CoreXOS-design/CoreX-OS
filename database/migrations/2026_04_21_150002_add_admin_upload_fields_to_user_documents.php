<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_documents', function (Blueprint $table) {
            if (!Schema::hasColumn('user_documents', 'uploaded_by_admin')) {
                $table->boolean('uploaded_by_admin')->default(false)->after('uploaded_by');
            }
            if (!Schema::hasColumn('user_documents', 'admin_upload_reason')) {
                $table->text('admin_upload_reason')->nullable()->after('uploaded_by_admin');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_documents', function (Blueprint $table) {
            if (Schema::hasColumn('user_documents', 'uploaded_by_admin')) {
                $table->dropColumn('uploaded_by_admin');
            }
            if (Schema::hasColumn('user_documents', 'admin_upload_reason')) {
                $table->dropColumn('admin_upload_reason');
            }
        });
    }
};
