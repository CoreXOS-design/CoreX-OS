<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->text('email_disclaimer')->nullable()->after('logo_path');
            $table->string('popi_url', 500)->nullable()->after('email_disclaimer');
        });

        // Seed HFC Coastal with the standard disclaimer
        DB::table('agencies')->where('slug', 'hfc-coastal')->update([
            'email_disclaimer' => 'This e-mail may contain confidential information and may be legally privileged and is intended only for the person to whom it is addressed. If you are not the intended recipient, you are notified that you may not use, distribute or copy this document in any manner whatsoever. Kindly also notify the sender immediately, and delete the e-mail. When addressed to clients of Home Finders Coastal ("the sending company") any opinion or advice contained in this e-mail is subject to the terms and conditions expressed in our standard terms of business or client engagement letter. Home Finders Coastal does not accept liability for any damage, loss or expense arising from this e-mail and/or from the accessing of any files attached to this e-mail.',
        ]);
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn(['email_disclaimer', 'popi_url']);
        });
    }
};
