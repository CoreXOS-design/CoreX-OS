<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * oversight_nudges.subject() is a polymorphic relation whose subject can be an
 * integer-keyed model (Deal, Property, Contact, CommandTask, User) OR a
 * UUID-keyed notification (subject_type = 'notification'). The original column
 * was unsignedBigInteger, so writing a notification UUID threw
 * "SQLSTATE[HY000]: 1366 Incorrect integer value" and crashed the hourly
 * OversightDigestJob on every unread-notification row.
 *
 * Widen the column to a nullable string so it holds both key shapes. Existing
 * integer values remain valid (MySQL compares the string column to integer
 * bindings transparently), so no data backfill is needed.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('oversight_nudges', function (Blueprint $table) {
            // Drop the composite index before altering the column type, then
            // recreate it — some MySQL versions refuse an in-place type change
            // on an indexed column.
            $table->dropIndex(['subject_type', 'subject_id']);
        });

        Schema::table('oversight_nudges', function (Blueprint $table) {
            $table->string('subject_id')->nullable()->change();
        });

        Schema::table('oversight_nudges', function (Blueprint $table) {
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::table('oversight_nudges', function (Blueprint $table) {
            $table->dropIndex(['subject_type', 'subject_id']);
        });

        Schema::table('oversight_nudges', function (Blueprint $table) {
            $table->unsignedBigInteger('subject_id')->nullable()->change();
        });

        Schema::table('oversight_nudges', function (Blueprint $table) {
            $table->index(['subject_type', 'subject_id']);
        });
    }
};
