<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Finalisation is the POST-completion work (signed PDF, filing, contact
     * linking, completion emails) — separate from the signing status itself.
     * A legally completed signing must always keep reading STATUS_COMPLETED
     * even if this work later fails; these columns track that separate
     * lifecycle without touching `status`.
     */
    public function up(): void
    {
        Schema::table('signature_templates', function (Blueprint $table) {
            $table->string('finalization_status', 20)->nullable()->after('signed_pdf_client_path');
            $table->text('finalization_error')->nullable()->after('finalization_status');
            $table->unsignedSmallInteger('finalization_attempts')->default(0)->after('finalization_error');
            $table->timestamp('finalization_started_at')->nullable()->after('finalization_attempts');
            $table->timestamp('finalization_finished_at')->nullable()->after('finalization_started_at');
            $table->index('finalization_status');
        });
    }

    public function down(): void
    {
        Schema::table('signature_templates', function (Blueprint $table) {
            $table->dropIndex(['finalization_status']);
            $table->dropColumn([
                'finalization_status',
                'finalization_error',
                'finalization_attempts',
                'finalization_started_at',
                'finalization_finished_at',
            ]);
        });
    }
};
