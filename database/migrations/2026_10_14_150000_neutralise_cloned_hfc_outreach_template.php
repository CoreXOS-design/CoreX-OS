<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cape walk, 14 Oct 2026: every agency created before the starter outreach text was made neutral holds a verbatim clone of Home Finders
 * Coastal's first template, whose opening line says "a registered estate agency on the KZN South Coast". Only that one phrase is replaced
 * (an agency's own edits to the rest of its template are kept), only for agencies other than agency 1, and only where the phrase is
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

        DB::table('seller_outreach_templates')
            ->where('agency_id', '!=', 1)
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
