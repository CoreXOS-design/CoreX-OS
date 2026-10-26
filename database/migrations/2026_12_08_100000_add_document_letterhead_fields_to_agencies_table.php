<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two letterhead details an agency had no place to type:
 *  - legal_name        the registered company line of the document footer ("VIPS cc t/a Mega Rent Real Estate"); blank = the trading name;
 *  - document_website  the website line printed on documents. Deliberately NOT agencies.website_url, which drives the public-listing redirect.
 * Reg no, PPRA number, VAT no, address, phone, e-mail and logo already exist. Spec: .ai/specs/agency-letterhead.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (! Schema::hasColumn('agencies', 'legal_name')) {
                $table->string('legal_name', 255)->nullable()->after('trading_name');
            }
            if (! Schema::hasColumn('agencies', 'document_website')) {
                $table->string('document_website', 255)->nullable()->after('legal_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            foreach (['legal_name', 'document_website'] as $column) {
                if (Schema::hasColumn('agencies', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
