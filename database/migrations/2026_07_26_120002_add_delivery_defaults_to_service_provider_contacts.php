<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-228 — per-contact delivery preference ("attorney X always wants link-via-email").
 * Nullable = fall back to the matrix rule's delivery_mode + the agency channel default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_service_provider_contacts', function (Blueprint $table) {
            $table->enum('default_delivery_mode', ['secure_link', 'direct_attachment'])->nullable()->after('phone');
            $table->enum('default_channel', ['email', 'whatsapp'])->nullable()->after('default_delivery_mode');
        });
    }

    public function down(): void
    {
        Schema::table('agency_service_provider_contacts', function (Blueprint $table) {
            $table->dropColumn(['default_delivery_mode', 'default_channel']);
        });
    }
};
