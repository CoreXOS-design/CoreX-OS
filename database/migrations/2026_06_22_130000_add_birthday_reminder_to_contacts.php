<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Birthday reminders are opt-in per contact. When true, the contact's
     * birthday surfaces both as an annual calendar entry (PeopleCalendarSource)
     * and as an in-app reminder on the day (ScanContactNotifications).
     * Default false — agents no longer get unsolicited birthday notifications.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('birthday_reminder')->default(false)->after('birthday');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('birthday_reminder');
        });
    }
};
