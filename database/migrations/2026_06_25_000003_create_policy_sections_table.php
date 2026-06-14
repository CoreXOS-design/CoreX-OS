<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Policy sections (AT-29). Mirrors rmcp_sections (with agency_id from
 * creation, unlike RMCP which added it later). Ordered, individually
 * acknowledgeable sections of a policy version; body_html supports
 * {{variable}} mail-merge. The `acknowledgement`-type section supplies
 * the final declaration text. See spec §3.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('policy_version_id')->constrained('policy_versions')->cascadeOnDelete();

            $table->enum('section_type', ['section', 'schedule', 'annexure', 'acknowledgement'])
                  ->default('section');
            $table->unsignedInteger('display_order');
            $table->string('section_number', 20);
            $table->string('title', 500);
            $table->longText('body_html');

            $table->boolean('requires_acknowledgement')->default(true);
            $table->string('acknowledgement_prompt', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['policy_version_id', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_sections');
    }
};
