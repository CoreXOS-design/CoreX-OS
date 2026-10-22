<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-498 (Johan, 21 Oct 2026) — a proxy no longer signs for EVERYONE else in the role: the agent picks who. Stored as the
 * list of parties the proxy does NOT sign for (recipient_local_keys), so NULL / empty means "everyone else" — exactly
 * what every existing proxy row meant — and nothing needs back-filling. See App\Support\Docuperfect\ProxyCoverage.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('signature_requests', 'proxy_excludes')) {
            Schema::table('signature_requests', function (Blueprint $table) {
                $table->json('proxy_excludes')->nullable()->after('is_proxy');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('signature_requests', 'proxy_excludes')) {
            Schema::table('signature_requests', function (Blueprint $table) {
                $table->dropColumn('proxy_excludes');
            });
        }
    }
};
