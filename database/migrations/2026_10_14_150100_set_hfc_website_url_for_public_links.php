<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A property's public link is now the OWNING agency's own website, else CoreX's public property page (Cape walk, 14 Oct 2026: every agency's
 * "View listing" pointed at hfcoastal.co.za). Home Finders Coastal's links must not change, so its website address is written into its own
 * agency row once - only if blank, and never on the demo instance.
 */
return new class extends Migration {
    public function up(): void
    {
        if (strtolower((string) env('COREX_INSTANCE_ROLE')) === 'demo' || ! DB::getSchemaBuilder()->hasColumn('agencies', 'website_url')) {
            return;
        }

        $url = trim((string) env('PUBLIC_WEBSITE_URL', '')) ?: 'https://www.hfcoastal.co.za';

        DB::table('agencies')
            ->where('slug', 'hfc-coastal')
            ->where(fn ($q) => $q->whereNull('website_url')->orWhere('website_url', ''))
            ->update(['website_url' => $url]);
    }

    public function down(): void
    {
        // data - not reversed
    }
};
