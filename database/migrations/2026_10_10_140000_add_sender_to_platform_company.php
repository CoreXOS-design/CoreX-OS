<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform Company Profile — the address and name every Platform E-Sign / Subscription Agreement email is sent FROM.
 * Until now those emails took the box-wide MAIL_FROM_* (an agency's address on QA1). They now send from the RR Technologies
 * company record. Backfilled here (not in a seeder) so every environment has the values after `migrate`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('platform_company', 'send_from_address')) {
            Schema::table('platform_company', function (Blueprint $t) {
                $t->string('send_from_address', 255)->nullable()->after('email_accounts');
                $t->string('send_from_name', 150)->nullable()->after('send_from_address');
            });
        }
        DB::table('platform_company')->whereNull('send_from_address')->update(['send_from_address' => 'admin@corexos.co.za']);
        DB::table('platform_company')->whereNull('send_from_name')->update(['send_from_name' => 'CoreX OS — RR Technologies']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('platform_company', 'send_from_address')) {
            Schema::table('platform_company', function (Blueprint $t) {
                $t->dropColumn(['send_from_address', 'send_from_name']);
            });
        }
    }
};
