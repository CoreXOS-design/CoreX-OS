<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * AT-450 round 3 - the Title Deed settings screen. Four on/off ticks that really meant "required" (PIN, signature, ID photo,
 * authority letter) become explicit three-way values (required | optional | not_used); "Sign on our screen" becomes its own
 * Yes / No; every change to a setting is kept in a history table; each receipt remembers which proofs were Required / Optional /
 * Not used at the moment of handover so the record can say "not captured" truthfully.
 *
 * Existing choices are carried over faithfully (spec section 15.2):
 *   pin_required              on -> required   off -> not_used     (off meant: no PIN is issued at all)
 *   signature_required        on -> required   off -> optional     (off meant: the box was offered, marked optional)
 *   collector_id_photo_required on -> required off -> not_used     (off meant: the photo field was not shown)
 *   authority_letter_required on -> required   off -> optional     (off meant: a letter was still accepted where it applied)
 * A NULL (the agency never chose) stays NULL and resolves to the new code default at read time.
 */
return new class extends Migration
{
    private const MAP = [
        'pin_required' => ['pin_mode', 'required', 'not_used'],
        'signature_required' => ['signature_mode', 'required', 'optional'],
        'collector_id_photo_required' => ['collector_id_photo_mode', 'required', 'not_used'],
        'authority_letter_required' => ['authority_letter_mode', 'required', 'optional'],
    ];

    public function up(): void
    {
        Schema::table('title_deed_settings', function (Blueprint $table) {
            $table->string('pin_mode', 12)->nullable()->after('agency_id');
            $table->string('signature_mode', 12)->nullable()->after('pin_mode');
            $table->string('collector_id_photo_mode', 12)->nullable()->after('signature_mode');
            $table->string('authority_letter_mode', 12)->nullable()->after('collector_id_photo_mode');
            $table->boolean('sign_on_screen_enabled')->nullable();
        });

        foreach (self::MAP as $old => [$new, $on, $off]) {
            DB::table('title_deed_settings')->where($old, 1)->update([$new => $on]);
            DB::table('title_deed_settings')->where($old, 0)->update([$new => $off]);
        }

        Schema::table('title_deed_settings', function (Blueprint $table) {
            $table->dropColumn(array_keys(self::MAP));
        });

        Schema::table('title_deed_receipts', function (Blueprint $table) {
            // {"pin":"required|optional|not_used","signature":...,"id_photo":...,"letter":...} as they stood at the handover.
            $table->json('proof_modes')->nullable();
            // A skipped (Optional) or unused signature has no way of signing: the column is empty then, never a guess.
            $table->string('signed_via', 10)->nullable()->default(null)->change();
        });

        Schema::create('title_deed_setting_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->string('setting_key', 60);
            $table->string('from_value', 120)->nullable();
            $table->string('to_value', 120)->nullable();
            $table->unsignedBigInteger('changed_by_user_id')->nullable();
            $table->string('source', 12)->default('screen');   // screen | wizard
            $table->timestamp('created_at')->nullable();

            $table->index(['agency_id', 'created_at'], 'td_setting_hist_agency_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('title_deed_setting_history');

        DB::table('title_deed_receipts')->whereNull('signed_via')->update(['signed_via' => 'screen']);
        Schema::table('title_deed_receipts', function (Blueprint $table) {
            $table->string('signed_via', 10)->default('screen')->nullable(false)->change();
            $table->dropColumn('proof_modes');
        });

        Schema::table('title_deed_settings', function (Blueprint $table) {
            foreach (array_keys(self::MAP) as $old) {
                $table->boolean($old)->nullable();
            }
        });
        foreach (self::MAP as $old => [$new, $on]) {
            DB::table('title_deed_settings')->where($new, $on)->update([$old => 1]);
            DB::table('title_deed_settings')->whereNotNull($new)->where($new, '!=', $on)->update([$old => 0]);
        }
        Schema::table('title_deed_settings', function (Blueprint $table) {
            $table->dropColumn(['pin_mode', 'signature_mode', 'collector_id_photo_mode', 'authority_letter_mode', 'sign_on_screen_enabled']);
        });
    }
};
