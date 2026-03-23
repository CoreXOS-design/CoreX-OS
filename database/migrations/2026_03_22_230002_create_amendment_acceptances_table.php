<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amendment_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('amendment_id')->constrained('document_amendments')->cascadeOnDelete();
            $table->foreignId('signature_request_id')->constrained()->cascadeOnDelete();
            $table->boolean('accepted')->default(false);
            $table->boolean('rejected')->default(false);
            $table->text('rejection_reason')->nullable();
            $table->text('initial_image')->nullable(); // Base64 initial
            $table->timestamps();

            $table->unique(['amendment_id', 'signature_request_id']);
            $table->index(['signature_request_id']);
        });

        // Add document_version and other_conditions_text to signature_templates for tracking
        if (!Schema::hasColumn('signature_templates', 'document_version')) {
            Schema::table('signature_templates', function (Blueprint $table) {
                $table->unsignedInteger('document_version')->default(1)->after('status');
                $table->text('other_conditions_text')->nullable()->after('sections_json');
                $table->string('amendment_status')->nullable()->after('document_version');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('amendment_acceptances');

        if (Schema::hasColumn('signature_templates', 'document_version')) {
            Schema::table('signature_templates', function (Blueprint $table) {
                $table->dropColumn(['document_version', 'other_conditions_text', 'amendment_status']);
            });
        }
    }
};
