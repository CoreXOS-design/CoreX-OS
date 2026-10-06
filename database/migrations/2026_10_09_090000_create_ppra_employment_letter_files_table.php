<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PPRA employment letter — wet-ink flow (.ai/specs/ppra-ffc-employment-letter.md §20).
 *
 * PIN signing is retired: the letter is printed, signed in wet ink outside CoreX and the signed copy is uploaded
 * from either the admin register or My Portal. The signed copy lives in ONE place — this table, reached only
 * through PpraEmploymentLetter::files()/currentFile() — so both screens show the same file with no sync step.
 * Re-uploads add a row (the newest is "current"); earlier rows stay as history. Soft deletes only.
 *
 * Additive: two new status values are appended; every legacy value stays in the enum (existing letters are test
 * data and simply display sensibly — see PpraEmploymentLetter::statusLabel()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppra_employment_letter_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('letter_id')->constrained('ppra_employment_letters')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('mime', 100)->nullable();
            $table->foreignId('uploaded_by_user_id')->constrained('users');
            $table->string('uploaded_via', 10)->comment('admin | portal — which screen the upload came from');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['letter_id', 'id']);
            $table->index(['agency_id', 'letter_id']);
        });

        DB::statement("ALTER TABLE ppra_employment_letters MODIFY COLUMN status ENUM("
            . "'draft','awaiting_agent_signature','awaiting_principal_signature','signed',"
            . "'awaiting_signed_copy','signed_copy_filed'"
            . ") NOT NULL DEFAULT 'awaiting_signed_copy'");
    }

    public function down(): void
    {
        // Letters on the new statuses fall back to the nearest legacy value before the enum is narrowed again.
        DB::table('ppra_employment_letters')->where('status', 'awaiting_signed_copy')->update(['status' => 'awaiting_agent_signature']);
        DB::table('ppra_employment_letters')->where('status', 'signed_copy_filed')->update(['status' => 'signed']);

        DB::statement("ALTER TABLE ppra_employment_letters MODIFY COLUMN status ENUM("
            . "'draft','awaiting_agent_signature','awaiting_principal_signature','signed'"
            . ") NOT NULL DEFAULT 'awaiting_agent_signature'");

        Schema::dropIfExists('ppra_employment_letter_files');
    }
};
