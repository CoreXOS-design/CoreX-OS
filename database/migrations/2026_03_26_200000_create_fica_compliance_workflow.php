<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── Compliance Officers table ──
        Schema::create('fica_compliance_officers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->cascadeOnDelete();
            $table->dateTime('assigned_at');
            $table->timestamps();
        });

        // ── New columns on fica_submissions for two-stage approval ──
        Schema::table('fica_submissions', function (Blueprint $table) {
            // Agent verification
            $table->foreignId('agent_verified_by')->nullable()->after('reviewer_notes')->constrained('users')->nullOnDelete();
            $table->dateTime('agent_verified_at')->nullable()->after('agent_verified_by');
            $table->json('agent_verification_data')->nullable()->after('agent_verified_at');
            $table->text('agent_notes')->nullable()->after('agent_verification_data');

            // Compliance officer verification
            $table->foreignId('co_verified_by')->nullable()->after('agent_notes')->constrained('users')->nullOnDelete();
            $table->dateTime('co_verified_at')->nullable()->after('co_verified_by');
            $table->json('co_verification_data')->nullable()->after('co_verified_at');
            $table->text('co_notes')->nullable()->after('co_verification_data');
            $table->longText('co_signature_data')->nullable()->after('co_notes');
        });

        // ── Update status enum to include agent_approved ──
        // Cross-driver via schema builder (MySQL prod + SQLite test DB).
        Schema::table('fica_submissions', function (Blueprint $table) {
            $table->enum('status', ['draft', 'submitted', 'under_review', 'agent_approved', 'corrections_requested', 'approved', 'rejected'])
                ->default('draft')
                ->change();
        });

        // ── Grandfather existing approved submissions ──
        // MySQL-only (uses CONCAT); only relevant to existing production data.
        // The SQLite test DB has no pre-existing rows here, so skip.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::table('fica_submissions')
            ->where('status', 'approved')
            ->whereNull('co_verified_by')
            ->update([
                'agent_verified_by'   => DB::raw('verified_by'),
                'agent_verified_at'   => DB::raw('verified_at'),
                'agent_notes'         => DB::raw("CONCAT(COALESCE(reviewer_notes, ''), '\n[Pre-CO workflow — auto-migrated]')"),
                'co_verified_by'      => DB::raw('verified_by'),
                'co_verified_at'      => DB::raw('verified_at'),
                'co_notes'            => 'Pre-CO workflow — grandfathered on migration',
            ]);
    }

    public function down(): void
    {
        Schema::table('fica_submissions', function (Blueprint $table) {
            $table->enum('status', ['draft', 'submitted', 'under_review', 'corrections_requested', 'approved', 'rejected'])
                ->default('draft')
                ->change();
        });

        Schema::table('fica_submissions', function (Blueprint $table) {
            $table->dropForeign(['agent_verified_by']);
            $table->dropForeign(['co_verified_by']);
            $table->dropColumn([
                'agent_verified_by', 'agent_verified_at', 'agent_verification_data', 'agent_notes',
                'co_verified_by', 'co_verified_at', 'co_verification_data', 'co_notes', 'co_signature_data',
            ]);
        });

        Schema::dropIfExists('fica_compliance_officers');
    }
};
