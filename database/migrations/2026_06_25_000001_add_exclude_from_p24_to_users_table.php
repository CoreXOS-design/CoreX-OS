<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-agent opt-out from Property24. When true the agent is published=false /
     * status=Inactive on P24 (so they vanish from the portal) and is never
     * attached as a contact agent on syndicated listings. Default false keeps
     * every existing agent visible on P24 exactly as before.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('exclude_from_p24')->default(false)->after('show_on_website');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('exclude_from_p24');
        });
    }
};
