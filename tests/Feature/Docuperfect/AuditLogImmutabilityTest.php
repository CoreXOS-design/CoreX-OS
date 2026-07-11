<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\ESignConsentLog;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * P0-4 — the signing audit trail must be evidence, not a convenience log.
 *
 * Ceremony §6: the tracker is what we put in front of a principal or the Ombud to prove
 * who held a document and for how long. SignatureAuditLog was append-only by CONVENTION
 * only — it carried SoftDeletes and overrode nothing, so any caller could edit or delete
 * a row and the "evidence" would quietly change.
 *
 * These tests assert the REJECTION. Note test_save_after_mutation_is_rejected in
 * particular: overriding update() alone does NOT stop save(), so a model that merely
 * mirrors the update()/delete() overrides is still editable. That is the hole these
 * guards close, on both evidence logs.
 */
final class AuditLogImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_audit_row_can_still_be_written(): void
    {
        $log = $this->seedAuditRow();

        $this->assertDatabaseHas('signature_audit_log', [
            'id' => $log->id,
            'action' => SignatureAuditLog::ACTION_SENT,
        ]);
    }

    public function test_update_is_rejected(): void
    {
        $log = $this->seedAuditRow();

        $this->expectException(DomainException::class);
        $log->update(['action' => SignatureAuditLog::ACTION_COMPLETED]);
    }

    public function test_delete_is_rejected(): void
    {
        $log = $this->seedAuditRow();

        $this->expectException(DomainException::class);
        $log->delete();
    }

    /**
     * The hole that overriding update() does NOT close: save() on an existing model goes
     * straight to performUpdate() and never routes through update(). Without the
     * `updating` event guard, this is how an audit row stays quietly editable.
     */
    public function test_save_after_mutation_is_rejected(): void
    {
        $log = $this->seedAuditRow();
        $log->action = SignatureAuditLog::ACTION_DECLINED;

        $this->expectException(DomainException::class);
        $log->save();
    }

    /** destroy() resolves the model then calls delete() — the guard must hold there too. */
    public function test_destroy_is_rejected(): void
    {
        $log = $this->seedAuditRow();

        $this->expectException(DomainException::class);
        SignatureAuditLog::destroy($log->id);
    }

    /** The evidence survives every attempt above — the row is still there, unchanged. */
    public function test_the_row_survives_every_attempt(): void
    {
        $log = $this->seedAuditRow();

        foreach ([
            fn () => $log->update(['action' => SignatureAuditLog::ACTION_COMPLETED]),
            fn () => $log->delete(),
            fn () => SignatureAuditLog::destroy($log->id),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('The audit trail accepted a mutation it should have refused.');
            } catch (DomainException) {
                // expected
            }
        }

        $this->assertDatabaseHas('signature_audit_log', [
            'id' => $log->id,
            'action' => SignatureAuditLog::ACTION_SENT,
        ]);
        $this->assertSame(SignatureAuditLog::ACTION_SENT, $log->fresh()->action);
    }

    /** SoftDeletes is gone — an audit row must not be hideable behind deleted_at either. */
    public function test_soft_deletes_is_not_in_play(): void
    {
        $this->assertNotContains(
            'Illuminate\Database\Eloquent\SoftDeletes',
            class_uses_recursive(SignatureAuditLog::class),
            'An audit row must not be soft-deletable — hidden evidence is deleted evidence.'
        );
    }

    // ── The same class of hole on the FICA consent log ──

    public function test_consent_log_rejects_save_after_mutation(): void
    {
        $consent = $this->seedConsentRow();

        $consent->consent_text = 'I never agreed to this.';

        $this->expectException(RuntimeException::class);
        $consent->save();
    }

    public function test_consent_log_rejects_delete(): void
    {
        $consent = $this->seedConsentRow();

        $this->expectException(RuntimeException::class);
        $consent->delete();
    }

    // ── Helpers ──

    private function seedConsentRow(): ESignConsentLog
    {
        return ESignConsentLog::create([
            'document_id'         => $this->seedDocumentId(),
            'id_number_entered'   => '8203155009087',   // encrypted by the model mutator
            'consent_text'        => 'I agree to sign this document electronically.',
            'consent_accepted_at' => now(),
            'ip_address'          => '102.65.14.7',
            'user_agent'          => 'Mozilla/5.0 (Linux; Android 13; SM-A536B)',
            'document_hash'       => Str::random(64),
            'created_at'          => now(),
        ]);
    }

    private function seedAuditRow(): SignatureAuditLog
    {
        $sigTmpl = SignatureTemplate::create([
            'document_id' => $this->seedDocumentId(),
            'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_AWAITING_SELLER,
            'created_by' => $this->userId,
        ]);

        return SignatureAuditLog::log(
            $sigTmpl,
            SignatureAuditLog::ACTION_SENT,
            SignatureAuditLog::ACTOR_SYSTEM,
            'System',
            metadata: ['signer_name' => 'Thandeka Mkhize'],
        );
    }

    private int $userId = 0;

    private function seedDocumentId(): int
    {
        $this->userId = (int) DB::table('users')->insertGetId([
            'name' => 'Elize van Wyk', 'email' => 'elize-' . Str::random(6) . '@hfcoastal.co.za',
            'password' => bcrypt('p'), 'role' => 'agent',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $docTmpl = DocuperfectTemplate::create([
            'name' => 'Exclusive Authority To Sell (V10)',
            'render_type' => 'web',
            'template_type' => 'cds',
            'category' => 'sales',
            'signing_parties' => ['owner_party'],
            'field_mappings' => [],
            'owner_id' => $this->userId,
        ]);

        return (int) Document::create([
            'name' => 'EATS — 14 Marine Drive, Shelly Beach',
            'document_type' => 'agreement',
            'owner_id' => $this->userId,
            'template_id' => $docTmpl->id,
            'web_template_data' => ['merged_html' => '<div>body</div>'],
        ])->id;
    }
}
