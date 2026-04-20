<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rmcp_section_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rmcp_acknowledgement_id')->constrained('rmcp_acknowledgements')
                  ->cascadeOnDelete();
            $table->foreignId('rmcp_section_id')->constrained('rmcp_sections')
                  ->cascadeOnDelete();

            $table->boolean('acknowledged')->default(false);
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledgement_response', 100)->nullable();
            $table->ipAddress('ip_address')->nullable();

            $table->timestamps();

            $table->unique(['rmcp_acknowledgement_id', 'rmcp_section_id'], 'rmcp_sec_ack_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rmcp_section_acknowledgements');
    }
};
