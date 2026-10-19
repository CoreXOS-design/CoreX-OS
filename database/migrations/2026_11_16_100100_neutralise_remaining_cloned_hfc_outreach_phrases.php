<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit fix 2026-10-18 (B-L1). 2026_10_14_150000 neutralised one HFC phrase in cloned outreach templates. The same
 * seeder (HfcConsentTemplatesSeeder) holds two more that every non-HFC clone still carries:
 * "a PPRA-registered agency on the KZN South Coast" (Soft Introduction - Future Seller, Area Updates - Market & Buyer Demand).
 * Only that phrase is replaced (an agency's own edits to the rest of its template are kept), only for agencies other than
 * Home Finders Coastal (found by slug), and only where the phrase is still there. HFC's own rows are untouched.
 */
return new class extends Migration {
    private const OLD = ', a PPRA-registered agency on the KZN South Coast.';
    private const NEW = ', a PPRA-registered agency working in {property_town}.';

    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('seller_outreach_templates')) {
            return;
        }

        $hfcIds = DB::table('agencies')->where('slug', 'hfc-coastal')->pluck('id')->all();

        DB::table('seller_outreach_templates')
            ->when($hfcIds !== [], fn ($q) => $q->whereNotIn('agency_id', $hfcIds))
            ->where('body', 'like', '%' . self::OLD . '%')
            ->orderBy('id')
            ->get(['id', 'body'])
            ->each(function ($row) {
                DB::table('seller_outreach_templates')->where('id', $row->id)->update([
                    'body' => str_replace(self::OLD, self::NEW, (string) $row->body),
                ]);
            });
    }

    public function down(): void
    {
        // data neutralisation - not reversed
    }
};
