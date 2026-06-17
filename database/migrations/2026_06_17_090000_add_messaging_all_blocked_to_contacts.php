<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-50 gate fix — distinguish "marketing opted out" from "all messages stopped".
 *
 * Before this column the two opt-out depths were indistinguishable in stored
 * state (both set messaging_opt_out_at + every channel boolean), so a
 * marketing-only opt-out wrongly read as "All messages stopped". This boolean
 * is the discriminator: false = marketing-only (transactional channels stay
 * open), true = full stop. Only ever raised by a "stop all" action on a contact
 * NOT in a live transaction; cleared on opt-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (!Schema::hasColumn('contacts', 'messaging_all_blocked')) {
                $table->boolean('messaging_all_blocked')
                    ->default(false)
                    ->after('messaging_opt_out_source')
                    ->comment('AT-50: true = all messages stopped; false = marketing-only opt-out (transactional still allowed).');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (Schema::hasColumn('contacts', 'messaging_all_blocked')) {
                $table->dropColumn('messaging_all_blocked');
            }
        });
    }
};
