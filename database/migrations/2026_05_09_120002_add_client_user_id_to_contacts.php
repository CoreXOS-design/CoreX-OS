<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('client_user_id')
                ->nullable()
                ->after('created_by_user_id')
                ->constrained('client_users')
                ->nullOnDelete();

            $table->index(['client_user_id', 'agency_id'], 'contacts_client_user_agency_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('contacts_client_user_agency_idx');
            $table->dropConstrainedForeignId('client_user_id');
        });
    }
};
