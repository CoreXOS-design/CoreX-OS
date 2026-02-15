<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_import_runs', function (Blueprint $table) {
            $table->id();

            // Who imported + scope
            $table->foreignId('imported_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();

            // Where it came from
            $table->string('source', 50)->default('propcon');
            $table->string('original_filename')->nullable();

            // Mapping metadata (no fixed template)
            $table->json('header_row')->nullable();       // detected headers
            $table->json('column_mapping')->nullable();   // required_field => header name
            $table->json('agent_mapping')->nullable();    // file agent string => user_id (forced completion)

            // Status
            $table->string('status', 30)->default('draft'); // draft|ready|applied|failed
            $table->text('error_message')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_import_runs');
    }
};
