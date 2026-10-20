<?php

use App\Services\PlatformEsign\Agreement\AgreementContent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Publishes the band-priced CoreX Subscription Agreement (spec agency-timeline-and-platform-esign §11.5a, Johan 2026-10-19)
 * as a NEW wording version on top of the current one — published versions are immutable, so agreements already sent keep
 * the version (and the prices) they were sent with. Idempotent: a no-op when the current version is already band-priced.
 *
 * Refuses (and so stops the deploy, loudly) while a wording draft is open: a draft copied from the old pricing, published
 * afterwards, would bring the old prices back. Publish or discard the draft, then run the migration again.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database that has never seeded the agreement seeds the band pricing itself on first use (AgreementContent::ensureSeeded).
        if (! Schema::hasTable('platform_esign_wording_versions') || ! AgreementContent::template()) {
            return;
        }
        app(AgreementContent::class)->publishBandPricing(null);
    }

    public function down(): void
    {
        // Published versions are never removed: agreements may already be pinned to it.
    }
};
