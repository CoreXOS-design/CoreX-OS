<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rmcp_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rmcp_version_id')->constrained('rmcp_versions')->cascadeOnDelete();

            $table->enum('section_type', ['section', 'schedule', 'annexure', 'acknowledgement'])
                  ->default('section');
            $table->unsignedInteger('display_order');
            $table->string('section_number', 20);
            $table->string('title', 500);
            $table->longText('body_html');

            // Acknowledgement requirements per section (feeds Prompt H)
            $table->boolean('requires_acknowledgement')->default(true);
            $table->string('acknowledgement_prompt', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['rmcp_version_id', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rmcp_sections');
    }
};
