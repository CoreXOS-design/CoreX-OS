<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rmcp_compliance_officers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('full_name', 200);
            $table->string('id_number', 20)->nullable();
            $table->string('cell', 50)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('title', 100)->default('FICA Compliance Officer');

            $table->date('appointed_on');
            $table->date('ended_on')->nullable();
            $table->foreignId('appointed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('appointment_notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'ended_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rmcp_compliance_officers');
    }
};
