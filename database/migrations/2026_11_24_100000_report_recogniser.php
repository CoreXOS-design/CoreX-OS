<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Report recogniser — AI read + learned layouts. Spec: .ai/specs/mic-complete-spec.md §8.9.
 *
 *  - market_reports: how the report was actually read, who/what read it, and the
 *    super-admin "resolved" marker for the queue.
 *  - market_report_ai_reads: the AI's raw answer cached by file hash, so the same
 *    file is never paid for twice. GLOBAL on purpose (no agency_id): it is the model's
 *    reading of the file's own bytes, not agency data.
 *  - market_report_learned_layouts: a publisher's layout (fingerprint + field mapping)
 *    saved after a successful AI read, so the next report in that layout is read for
 *    free. GLOBAL on purpose — it holds phrases and column positions, never figures.
 *  - market_report_types: the 'learned_layout' row (backfilled here — seeders do not run
 *    on a git-pull deploy).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('market_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('market_reports', 'read_method')) {
                // parser = a built-in CMA Info parser; learned = a saved layout; ai = read by AI; unread = nothing could read it.
                $table->string('read_method', 20)->default('parser')->after('parser_version');
                $table->string('read_publisher', 160)->nullable()->after('read_method');
                $table->text('ai_read_note')->nullable()->after('read_publisher');
                $table->decimal('ai_read_confidence', 4, 3)->nullable()->after('ai_read_note');
                $table->unsignedBigInteger('learned_layout_id')->nullable()->after('ai_read_confidence');
                $table->timestamp('resolved_at')->nullable()->after('learned_layout_id');
                $table->unsignedBigInteger('resolved_by_user_id')->nullable()->after('resolved_at');
                $table->index(['read_method', 'resolved_at'], 'idx_mr_read_method_resolved');
            }
        });

        if (!Schema::hasTable('market_report_ai_reads')) {
            Schema::create('market_report_ai_reads', function (Blueprint $table) {
                $table->id();
                $table->char('file_hash', 64);
                $table->string('prompt_version', 20);
                $table->string('model', 80)->nullable();
                $table->json('payload');
                $table->unsignedInteger('input_tokens')->default(0);
                $table->unsignedInteger('output_tokens')->default(0);
                $table->decimal('cost_zar', 10, 4)->default(0);
                $table->unsignedBigInteger('first_report_id')->nullable();
                $table->timestamps();
                $table->unique(['file_hash', 'prompt_version'], 'uq_mrar_hash_prompt');
            });
        }

        if (!Schema::hasTable('market_report_learned_layouts')) {
            Schema::create('market_report_learned_layouts', function (Blueprint $table) {
                $table->id();
                $table->string('name', 160);
                $table->string('publisher', 160)->nullable();
                $table->string('status', 20)->default('learned'); // learned | approved (retired = soft-deleted)
                $table->char('fingerprint', 64);
                $table->json('anchors');
                $table->json('mapping');
                $table->string('report_kind', 40)->nullable();
                $table->decimal('selftest_score', 4, 3)->nullable();
                $table->unsignedInteger('times_used')->default(0);
                $table->timestamp('last_used_at')->nullable();
                $table->unsignedBigInteger('learned_from_report_id')->nullable();
                $table->unsignedBigInteger('learned_from_agency_id')->nullable(); // audit only
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('approved_by_user_id')->nullable();
                $table->unsignedBigInteger('archived_by_user_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index('fingerprint', 'idx_mrll_fingerprint');
                $table->index(['status', 'deleted_at'], 'idx_mrll_status');
            });
        }

        if (!DB::table('market_report_types')->where('key', 'learned_layout')->exists()) {
            DB::table('market_report_types')->insert([
                'key'                  => 'learned_layout',
                'display_name'         => 'Learned layout',
                'parser_class'         => 'App\\Services\\MarketReports\\Parsers\\LearnedLayoutParser',
                'expected_fields_json' => json_encode(['comps[]', 'suburb_stats[]', 'valuation']),
                'auto_approve'         => false,
                'sample_file_path'     => null,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
        }

        // Reports already flagged "not recognised" before this feature are now visible in the queue.
        DB::statement(
            "UPDATE market_reports SET read_method = 'unread'
              WHERE read_method = 'parser'
                AND JSON_UNQUOTE(JSON_EXTRACT(spot_check_results, '$.flagged_by')) = 'unrecognized_document'"
        );
    }

    public function down(): void
    {
        // Additive, never destructive: nothing is dropped on rollback.
    }
};
