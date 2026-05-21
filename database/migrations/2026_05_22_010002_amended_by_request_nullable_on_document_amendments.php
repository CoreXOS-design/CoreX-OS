<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-Sign V3 Phase 1B (ES-9).
 *
 * Allow agent-initiated amendments to exist without a signing-request
 * reference. Per spec §7.5.4 the `added_via = 'agent_preparation'` path
 * lets the agent stage conditions before any party signs — there is no
 * SignatureRequest row to link from at that point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_amendments', function (Blueprint $table) {
            $table->unsignedBigInteger('amended_by_request_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reverting requires backfilling NULL rows with a valid id — skipped.
        // Leaving the column nullable is forward-safe.
    }
};
