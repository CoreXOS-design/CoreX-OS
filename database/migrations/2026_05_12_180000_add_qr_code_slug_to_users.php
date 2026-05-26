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
        Schema::table('users', function (Blueprint $table) {
            $table->string('qr_code_slug', 16)->nullable()->unique()->after('id');
        });

        // Backfill: every existing user gets a unique 10-char slug.
        $alphabet = '23456789abcdefghjkmnpqrstuvwxyz'; // Crockford-ish (no 0/o/1/i/l)
        $ids = DB::table('users')->whereNull('qr_code_slug')->pluck('id');
        foreach ($ids as $id) {
            do {
                $slug = '';
                for ($i = 0; $i < 10; $i++) {
                    $slug .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                }
                $exists = DB::table('users')->where('qr_code_slug', $slug)->exists();
            } while ($exists);

            DB::table('users')->where('id', $id)->update(['qr_code_slug' => $slug]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['qr_code_slug']);
            $table->dropColumn('qr_code_slug');
        });
    }
};
