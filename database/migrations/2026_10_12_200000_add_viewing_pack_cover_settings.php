<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Viewing Pack cover style (.ai/specs/viewing-pack.md §14).
 *
 * Five nullable agency settings beside the existing `viewing_pack_*` columns, plus a
 * small audit table. Idempotent (hasColumn / hasTable guards) so a re-run is safe.
 *
 *  - viewing_pack_cover_style        'standard' (today's cover) | 'classic_welcome'
 *  - viewing_pack_cover_slogan       blank => the agency tagline
 *  - viewing_pack_cover_website      blank => the agency website_url
 *  - viewing_pack_cover_phone        blank => the agency phone
 *  - viewing_pack_cover_accent_color blank => the style default (#C00000)
 *
 * No data is written here: every agency stays on 'standard' until it picks a style, and
 * no agency's values are seeded by code (multi-agency rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (! Schema::hasColumn('agencies', 'viewing_pack_cover_style')) {
                $table->string('viewing_pack_cover_style', 30)->default('standard')->after('viewing_pack_default_duration_minutes');
            }
            if (! Schema::hasColumn('agencies', 'viewing_pack_cover_slogan')) {
                $table->string('viewing_pack_cover_slogan', 255)->nullable()->after('viewing_pack_cover_style');
            }
            if (! Schema::hasColumn('agencies', 'viewing_pack_cover_website')) {
                $table->string('viewing_pack_cover_website', 255)->nullable()->after('viewing_pack_cover_slogan');
            }
            if (! Schema::hasColumn('agencies', 'viewing_pack_cover_phone')) {
                $table->string('viewing_pack_cover_phone', 64)->nullable()->after('viewing_pack_cover_website');
            }
            if (! Schema::hasColumn('agencies', 'viewing_pack_cover_accent_color')) {
                $table->string('viewing_pack_cover_accent_color', 20)->nullable()->after('viewing_pack_cover_phone');
            }
        });

        if (! Schema::hasTable('viewing_pack_cover_audit')) {
            Schema::create('viewing_pack_cover_audit', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id')->index();
                $table->unsignedBigInteger('changed_by_user_id')->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->timestamp('changed_at')->nullable();

                $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
                $table->foreign('changed_by_user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('viewing_pack_cover_audit');

        Schema::table('agencies', function (Blueprint $table) {
            foreach ([
                'viewing_pack_cover_accent_color',
                'viewing_pack_cover_phone',
                'viewing_pack_cover_website',
                'viewing_pack_cover_slogan',
                'viewing_pack_cover_style',
            ] as $col) {
                if (Schema::hasColumn('agencies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
