<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-447 follow-up, phase (d) — Subscription Agreement wording editor + versions.
 * Spec: .ai/specs/agency-timeline-and-platform-esign.md §11.14. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_esign_wording_versions', function (Blueprint $t) {
            $t->string('change_note', 500)->nullable()->after('version_date');
            $t->unsignedBigInteger('parent_version_id')->nullable()->after('template_id');
            $t->unsignedInteger('rev')->default(0)->after('is_published');           // edit counter (two-tab guard)
            $t->foreignId('published_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $t->softDeletes();                                                          // a discarded DRAFT only — never a published version
        });

        // Version 1.0 was seeded before change notes existed.
        DB::table('platform_esign_wording_versions')->whereNull('change_note')->update(['change_note' => 'First published version — the wording as signed off on 28 September 2026.']);

        Schema::create('platform_esign_wording_audit', function (Blueprint $t) {
            $t->id();
            $t->foreignId('template_id')->constrained('platform_esign_templates')->cascadeOnDelete();
            $t->unsignedBigInteger('version_id')->nullable()->index();
            $t->string('action', 40);
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('detail', 500)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_esign_wording_audit');
        Schema::table('platform_esign_wording_versions', function (Blueprint $t) {
            $t->dropForeign(['published_by']);
            $t->dropColumn(['change_note', 'parent_version_id', 'rev', 'published_by', 'deleted_at']);
        });
    }
};
