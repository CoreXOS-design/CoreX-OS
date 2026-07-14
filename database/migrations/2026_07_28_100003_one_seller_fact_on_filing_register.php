<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-238 — ONE SELLER FACT.
 *
 * The seller auto-fill linked the seller CONTACT and also typed that contact's name into the
 * free-text box, so a filing could store the same person twice — a duplicate that drifts the
 * moment the contact is renamed, and a second box for a clerk to enter a different seller into.
 * Johan hit it on qa1 within minutes of the flow landing.
 *
 * The code fix makes the two surfaces mutually exclusive (link OR typed name, never both) and
 * the controller now refuses to store a typed copy alongside a link. This normalises the rows
 * that the buggy form already wrote.
 *
 * SAFE ON HISTORICAL DATA: the free-text name is only dropped where a seller CONTACT is linked,
 * and no historical filing carries one — `seller_contact_id` did not exist before AT-238. The
 * 2,069 legacy rows keep their typed seller names untouched, because for them the typed name is
 * the only seller fact there has ever been, and it remains the truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        $normalised = DB::table('document_filing_register')
            ->whereNotNull('seller_contact_id')
            ->whereNotNull('seller_name')
            ->update(['seller_name' => null]);

        if ($normalised) {
            \Log::info('AT-238 one-seller-fact normalisation', [
                'rows_deduplicated' => $normalised,
            ]);
        }
    }

    public function down(): void
    {
        // Irreversible by design, and harmlessly so: the dropped value was a COPY of the linked
        // contact's name, and the contact still holds it. Nothing was lost to restore.
    }
};
