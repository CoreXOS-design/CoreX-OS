<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/esign-signing-modes.md §5 (Build step 2). The adoption event — a signer drawing / typing their signature
 * and initial ONCE and agreeing to the stored wording — is its own append-only record. A re-adoption is a new
 * generation; the earlier row is stamped superseded, never edited or removed.
 *
 * signature_place_generations (built by the signature-evidence fix) gains the provenance of each placed mark:
 *   method            drawn | stamped_click | stamped_auto   (null on rows written before this existed = drawn)
 *   adoption_id       which adoption's mark was stamped (null for a drawn mark)
 *   fill_all_event_id the signature_audit_log row of the single "fill all" confirmation (signing mode 3 only)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('signature_adoptions')) {
            Schema::create('signature_adoptions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('signature_request_id');
                $table->unsignedBigInteger('signature_template_id')->nullable();
                $table->unsignedInteger('generation');
                $table->string('mode', 24);                          // adopt_then_stamp | adopt_and_fill_all
                $table->string('signer_name')->nullable();
                $table->string('signer_email')->nullable();
                $table->longText('signature_image');
                $table->longText('initial_image');
                $table->char('signature_image_hash', 64);
                $table->char('initial_image_hash', 64);
                $table->unsignedInteger('disclaimer_version');
                $table->text('disclaimer_text');                     // the exact words shown, never re-read from config
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamp('adopted_at');
                $table->timestamp('superseded_at')->nullable();
                $table->unsignedBigInteger('superseded_by_id')->nullable();
                $table->timestamps();

                $table->unique(['signature_request_id', 'generation'], 'sig_adoption_req_gen_unique');
                $table->index('signature_template_id', 'sig_adoption_template_idx');
            });
        }

        if (! Schema::hasColumn('signature_place_generations', 'method')) {
            Schema::table('signature_place_generations', function (Blueprint $table) {
                $table->string('method', 16)->nullable()->after('source');
                $table->unsignedBigInteger('adoption_id')->nullable()->after('method');
                $table->unsignedBigInteger('fill_all_event_id')->nullable()->after('adoption_id');
                $table->index('adoption_id', 'sig_place_gen_adoption_idx');
            });
        }
    }

    public function down(): void
    {
        // Intentionally one-way: removing adoption evidence would destroy it.
    }
};
