<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-49 — agency-configurable marketing opt-out email-signature footer.
 *
 * The default wording (with the /unsubscribe link) is rendered by an accessor
 * on the Agency model when this column is blank, so the feature works out of
 * the box and the column only stores an explicit override.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->text('marketing_unsubscribe_footer')->nullable()->after('email_disclaimer');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('marketing_unsubscribe_footer');
        });
    }
};
