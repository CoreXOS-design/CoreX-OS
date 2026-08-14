<?php

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentSealedVersion;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureTemplate;
use Illuminate\Support\Facades\Log;

/**
 * E-Sign P1 — seals an immutable, hash-chained snapshot of a document at each
 * signing/approval hop ("save each copy as it got signed"). PASSIVE and FAIL-OPEN:
 * a seal failure is logged and swallowed — it must NEVER interrupt or alter the
 * signing pipeline. Nothing here mutates existing state; it only appends rows to
 * document_sealed_versions and one linked entry to signature_audit_log.
 */
class DocumentSealService
{
    /** The event that sealed a copy — kept as constants so seal points read clearly. */
    public const EVENT_CANDIDATE_SIGNED       = 'candidate_signed';
    public const EVENT_AUTHORISER_COSIGNED    = 'authoriser_cosigned';
    public const EVENT_RECIPIENT_SIGNED       = 'recipient_signed';
    public const EVENT_SIGNED                  = 'signed';   // generic per-hop sign/initial apply
    public const EVENT_CANDIDATE_FINAL_APPROVED = 'candidate_final_approved';
    public const EVENT_COMPLETED               = 'completed';

    /**
     * Seal the document's CURRENT canonical (baked) HTML as the next version.
     *
     * @param array{
     *   signer_identity?: ?string, signer_user_id?: ?int, actor_type?: ?string,
     *   actor_name?: ?string, actor_role?: ?string, actor_email?: ?string,
     *   ip?: ?string, ua?: ?string, request_id?: ?int, agency_id?: ?int,
     *   template?: ?\App\Models\Docuperfect\SignatureTemplate
     * } $ctx
     */
    public function seal(Document $document, string $eventType, array $ctx = []): ?DocumentSealedVersion
    {
        try {
            $webData = is_array($document->web_template_data) ? $document->web_template_data : [];
            // The sealed content is the baked canonical HTML at this hop (falls back to
            // the merged HTML). Nothing to seal if the document has no rendered body yet.
            $sealedHtml = (string) ($webData['canonical_html'] ?? $webData['merged_html'] ?? '');
            if ($sealedHtml === '') {
                return null;
            }

            $template = $ctx['template'] ?? $document->signatureTemplate ?? null;

            $prev     = DocumentSealedVersion::latestFor($document->id);
            $prevHash = $prev?->content_hash;
            $version  = DocumentSealedVersion::nextVersion($document->id);
            $hash     = DocumentSealedVersion::computeHash($prevHash, $sealedHtml);

            $sealed = DocumentSealedVersion::create([
                'document_id'           => $document->id,
                'signature_template_id' => $template?->id,
                'version'               => $version,
                'event_type'            => $eventType,
                'signer_identity'       => $ctx['signer_identity'] ?? null,
                'signer_user_id'        => $ctx['signer_user_id'] ?? null,
                'actor_type'            => $ctx['actor_type'] ?? null,
                'actor_name'            => $ctx['actor_name'] ?? null,
                'actor_role'            => $ctx['actor_role'] ?? null,
                'sealed_html'           => $sealedHtml,
                'content_hash'          => $hash,
                'prev_hash'             => $prevHash,
                'ip_address'            => $ctx['ip'] ?? null,
                'user_agent'            => $ctx['ua'] ?? null,
                'agency_id'             => $ctx['agency_id'] ?? ($template->agency_id ?? null),
            ]);

            // Append a linked audit entry (metadata carries the sealed-version link).
            if ($template instanceof SignatureTemplate) {
                try {
                    SignatureAuditLog::log(
                        $template,
                        'document_sealed',
                        $ctx['actor_type'] ?? SignatureAuditLog::ACTOR_SYSTEM,
                        $ctx['actor_name'] ?? 'System',
                        $ctx['actor_email'] ?? null,
                        $ctx['signer_user_id'] ?? null,
                        $ctx['request_id'] ?? null,
                        $ctx['ip'] ?? null,
                        $ctx['ua'] ?? null,
                        [
                            'event_type'        => $eventType,
                            'sealed_version_id' => $sealed->id,
                            'version'           => $version,
                            'content_hash'      => $hash,
                            'prev_hash'         => $prevHash,
                            'signer_identity'   => $ctx['signer_identity'] ?? null,
                        ],
                        $hash
                    );
                } catch (\Throwable $e) {
                    Log::warning('DocumentSealService: audit link write failed', ['document_id' => $document->id, 'error' => $e->getMessage()]);
                }
            }

            return $sealed;
        } catch (\Throwable $e) {
            // FAIL-OPEN — never let sealing break the signing pipeline.
            Log::warning('DocumentSealService: seal failed (non-fatal)', [
                'document_id' => $document->id ?? null,
                'event_type'  => $eventType,
                'error'       => $e->getMessage(),
            ]);
            return null;
        }
    }
}
