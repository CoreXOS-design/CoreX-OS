<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-508 (P1) — .ai/specs/at-508-mobile-rentals.md §13.
 * Tenants and owners can switch off the purely INFORMATIONAL phone notifications (never the mandatory ones).
 *   - rental_portal_settings.push_informational_default : the agency's default for those (null = on).
 *   - rental_push_preferences                           : one person's own override for one category.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_portal_settings', function (Blueprint $table) {
            $table->boolean('push_informational_default')->nullable()->after('notify_tenant_on_status_change');
        });

        Schema::create('rental_push_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_user_id')->constrained('client_users')->cascadeOnDelete();
            $table->string('category', 64);
            $table->boolean('enabled');
            $table->timestamps();

            $table->unique(['client_user_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_push_preferences');
        Schema::table('rental_portal_settings', function (Blueprint $table) {
            $table->dropColumn('push_informational_default');
        });
    }
};
