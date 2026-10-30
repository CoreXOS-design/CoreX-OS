<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Attorney portal: each attorney contact chooses an emailed PIN each time, or a password (attorney-portal.md "Sign-in method"). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attorney_portal_accesses') || Schema::hasColumn('attorney_portal_accesses', 'sign_in_choice')) {
            return;
        }
        Schema::table('attorney_portal_accesses', function (Blueprint $table) {
            $table->string('sign_in_choice', 10)->nullable();   // null = not chosen yet, 'pin' | 'password'
            $table->string('password')->nullable();             // hashed; only when sign_in_choice = 'password'
            $table->timestamp('password_set_at')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('attorney_portal_accesses', 'sign_in_choice')) {
            Schema::table('attorney_portal_accesses', fn (Blueprint $t) => $t->dropColumn(['sign_in_choice', 'password', 'password_set_at']));
        }
    }
};
