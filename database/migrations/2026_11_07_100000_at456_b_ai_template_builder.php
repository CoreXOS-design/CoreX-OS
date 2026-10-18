<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-456 Part B — AI Template Builder (.ai/specs/at456-ai-tools-scheduler-and-spend-safety.md §B.4).
 *
 *  - property_ad_templates: mark AI-made rows + remember the idea that produced them.
 *  - agencies.ai_template_monthly_limit: how many ideas an agency may submit per
 *    calendar month (default 20). Owner-set on the AI usage agency page.
 *  - ai_template_generations: one row per idea submitted. Holds the validated
 *    options so "save this one" never trusts what the browser sends back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_ad_templates', function (Blueprint $table) {
            $table->boolean('ai_generated')->default(false);
            $table->text('ai_idea')->nullable();
        });

        Schema::table('agencies', function (Blueprint $table) {
            $table->unsignedInteger('ai_template_monthly_limit')->default(20);
        });

        Schema::create('ai_template_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('idea');
            $table->string('ad_size', 20);
            $table->string('property_type', 100)->nullable();
            // Private-disk path of the optional inspiration image; purged after 7 days.
            $table->string('reference_image_path', 255)->nullable();
            $table->timestamp('reference_image_purged_at')->nullable();
            // pending (in flight) | success | failed. Only success counts toward the monthly limit.
            $table->string('status', 10)->default('pending');
            $table->string('failure_reason', 255)->nullable();
            $table->decimal('cost_zar', 10, 4)->default(0);
            // The validated, fully-formed options: [{name, layout}]. Saved from here, never from the browser.
            $table->json('options_json')->nullable();
            // Option index => saved template id, so saving twice is idempotent.
            $table->json('saved_template_ids')->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'status', 'created_at'], 'idx_atg_agency_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_template_generations');

        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('ai_template_monthly_limit');
        });

        Schema::table('property_ad_templates', function (Blueprint $table) {
            $table->dropColumn(['ai_generated', 'ai_idea']);
        });
    }
};
