<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature 2 (ad-hoc "Send docs to…") — agency-level on/off switch for ad-hoc document
 * distribution to a FREE-TEXT email address from Email Parties. DEFAULT OFF: each agency
 * opts in. When off, the "Send documents to any email" affordance is hidden; when on, it shows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->boolean('adhoc_document_distribution_enabled')->default(false)->after('split_branches_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('adhoc_document_distribution_enabled');
        });
    }
};
