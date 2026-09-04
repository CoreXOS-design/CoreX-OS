<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-385 — the e-sign identity gate (Fill & Review) must accept a passport
 * number as well as an SA ID number: foreign nationals buy and sell on the
 * KZN coast routinely and hold no 13-digit SA ID. Neither `contacts` nor
 * `signature_requests` previously had anywhere to store one — only
 * `id_number` existed on both, and every validation rule on that field is
 * `required|string|min:3|max:20` (free text, no SA-ID-shape check), so a
 * SEPARATE typed column is added rather than overloading `id_number` — a
 * passport number is a distinct fact (One Source of Truth Per Data Point,
 * STANDARDS.md), and downstream compliance/FICA screens should be able to
 * tell which kind of document was captured.
 *
 * `contacts.passport_number` mirrors the existing `id_number` shape exactly
 * (varchar 20, nullable) — no format regex, matching id_number's own
 * unvalidated-free-text convention (accepted as-is by AT-385's investigation:
 * the /verify unlock is already a case-insensitive string compare, so a
 * passport works with zero downstream code change once the column exists).
 *
 * `signature_requests.signer_passport_number` mirrors `signer_id_number`.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (!Schema::hasColumn('contacts', 'passport_number')) {
                $table->string('passport_number', 20)->nullable()->after('id_number_source');
            }
        });

        Schema::table('signature_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('signature_requests', 'signer_passport_number')) {
                $table->string('signer_passport_number', 20)->nullable()->after('signer_id_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (Schema::hasColumn('contacts', 'passport_number')) {
                $table->dropColumn('passport_number');
            }
        });

        Schema::table('signature_requests', function (Blueprint $table) {
            if (Schema::hasColumn('signature_requests', 'signer_passport_number')) {
                $table->dropColumn('signer_passport_number');
            }
        });
    }
};
