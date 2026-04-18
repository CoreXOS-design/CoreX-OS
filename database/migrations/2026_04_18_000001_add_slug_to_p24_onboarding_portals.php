<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('p24_onboarding_portals', function (Blueprint $table) {
            $table->string('slug', 160)->nullable()->after('token');
        });

        // Backfill a slug for any existing portal so the new URL works
        // without the admin having to re-create them.
        $portals = DB::table('p24_onboarding_portals')->get(['id', 'label', 'token']);
        $used = [];
        foreach ($portals as $p) {
            $base = Str::slug($p->label ?? '') ?: 'portal-' . $p->id;
            $slug = $base;
            $i = 2;
            while (isset($used[$slug]) || DB::table('p24_onboarding_portals')->where('slug', $slug)->exists()) {
                $slug = $base . '-' . $i++;
            }
            $used[$slug] = true;
            DB::table('p24_onboarding_portals')->where('id', $p->id)->update(['slug' => $slug]);
        }

        Schema::table('p24_onboarding_portals', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('p24_onboarding_portals', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
