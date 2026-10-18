<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cape walk, 14 Oct 2026: every agency created before the starter outreach text was made neutral holds a verbatim clone of Home Finders
 * Coastal's first template, whose opening line says "a registered estate agency on the KZN South Coast". Only that one phrase is replaced
 * (an agency's own edits to the rest of its template are kept), only for agencies other than Home Finders Coastal (found by slug), and only where the phrase is
 * still there. HFC's own rows are untouched.
 */
return new class extends Migration {
    private const OLD = ' — a registered estate agency on the KZN South Coast.';
    private const NEW = ', a registered estate agency working in {property_town}.';

    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('seller_outreach_templates')) {
            return;
        }

        // Audit fix 2026-10-18: HFC is found by slug (as 150100/150200 do), not assumed to be agency id 1.
        // Identical outcome on databases where HFC is id 1 (all that have already run this).
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
