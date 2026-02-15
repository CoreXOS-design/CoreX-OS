<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_definitions', function (Blueprint $table) {
            $table->id();

            // who can see it: global or branch
            $table->string('scope', 20)->default('global'); // global | branch
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // what is the activity called
            $table->string('name');

            // weight + sort order
            $table->decimal('weight', 10, 2)->default(1.00);
            $table->integer('sort_order')->default(100);

            // enable/disable (soft control)
            $table->boolean('is_enabled')->default(true);

            $table->timestamps();

            // Prevent duplicates per scope/branch
            $table->unique(['scope', 'branch_id', 'name'], 'activity_definitions_scope_branch_name_unique');
            $table->index(['scope', 'branch_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_definitions');
    }
};
