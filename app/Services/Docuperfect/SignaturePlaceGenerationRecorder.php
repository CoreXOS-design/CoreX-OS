<?php

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignaturePlaceGeneration;
use App\Models\Docuperfect\SignatureRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Johan's signature-evidence rule (9 Sep 2026): a re-signature must never overwrite the original; every signing
 * is kept. The web (CDS) completion path stores each place's image in documents.web_template_data, keyed by place
 * (signatures[blockId], signed_initials[party][key]) and merged over on every completion — so a second signing of
 * the same place destroyed the first. This records every posted place as a numbered generation BEFORE that merge:
 *
 *   - the same image posted again (a returning signer re-submits what is already there) writes nothing;
 *   - a different image writes generation N+1, stamps N superseded, and puts one `signature_resigned` row in the
 *     audit trail (so the certificate lists every generation);
 *   - a document signed before this existed has its original in the JSON only: that original is first recovered as
 *     generation 1 (source legacy_json, time and IP not on record) so it is not lost when the new image replaces it.
 *
 * The JSON map keeps holding the latest generation, so rendering, the PDF and the certificate layout are unchanged.
 */
class SignaturePlaceGenerationRecorder
{
    /**
     * @param array<string,mixed> $posted         place key => data URI, exactly as the client posted them (signatures and initials)
     * @param array<string,mixed> $priorByKey     what the JSON map held for those keys BEFORE this completion
     * @param array{method?:string,adoption_id?:int,fill_all_event_id?:int} $provenance how the marks got there (default: drawn)
     * @return array<int, SignaturePlaceGeneration> the generations written by this call (including recovered originals)
     */
    public function record(Document $document, SignatureRequest $signingRequest, array $posted, array $priorByKey, Request $http, array $provenance = []): array
    {
        $written = [];

        foreach ($posted as $placeKey => $image) {
            $placeKey = (string) $placeKey;
            if ($placeKey === '' || ! is_string($image) || trim($image) === '') {
                continue;
            }

            $written = array_merge($written, DB::transaction(
                fn () => $this->recordOne($document, $signingRequest, $placeKey, $image, $priorByKey[$placeKey] ?? null, $http, $provenance)
            ));
        }

        return $written;
    }

    /** @return array<int, SignaturePlaceGeneration> */
    private function recordOne(Document $document, SignatureRequest $req, string $placeKey, string $image, mixed $prior, Request $http, array $provenance = []): array
    {
        $hash = hash('sha256', $image);
        $kind = str_contains($placeKey, '-init-') ? SignaturePlaceGeneration::KIND_INITIAL : SignaturePlaceGeneration::KIND_SIGNATURE;

        $latest = SignaturePlaceGeneration::forPlace($document->id, $placeKey)
            ->orderByDesc('generation')->lockForUpdate()->first();

        if ($latest && $latest->image_hash === $hash) {
            return []; // the same mark re-posted: nothing new happened
        }

        $out = [];

        // A document signed before generations existed: its only copy of the original is the JSON value about to be replaced.
        if (! $latest && is_string($prior) && trim($prior) !== '' && hash('sha256', $prior) !== $hash) {
            $latest = SignaturePlaceGeneration::create([
                'document_id' => $document->id,
                'signature_template_id' => $req->signature_template_id,
                'signature_request_id' => null,
                'place_key' => $placeKey,
                'kind' => $kind,
                'generation' => 1,
                'image' => $prior,
                'image_hash' => hash('sha256', $prior),
                'source' => SignaturePlaceGeneration::SOURCE_LEGACY_JSON,
            ]);
            $out[] = $latest;
        }

        $new = SignaturePlaceGeneration::create([
            'document_id' => $document->id,
            'signature_template_id' => $req->signature_template_id,
            'signature_request_id' => $req->id,
            'place_key' => $placeKey,
            'kind' => $kind,
            'generation' => $latest ? $latest->generation + 1 : 1,
            'image' => $image,
            'image_hash' => $hash,
            'signer_name' => $req->signer_name,
            'signer_email' => $req->signer_email,
            'ip_address' => $http->ip(),
            'user_agent' => $http->userAgent(),
            'signed_at' => now(),
            'source' => SignaturePlaceGeneration::SOURCE_WEB_COMPLETE,
            'method' => $provenance['method'] ?? SignaturePlaceGeneration::METHOD_DRAWN,
            'adoption_id' => $provenance['adoption_id'] ?? null,
            'fill_all_event_id' => $provenance['fill_all_event_id'] ?? null,
        ]);
        $out[] = $new;

        if ($latest) {
            $latest->update(['superseded_at' => now(), 'superseded_by_id' => $new->id]);

            SignatureAuditLog::create([
                'signature_template_id' => $req->signature_template_id,
                'signature_request_id' => $req->id,
                'action' => 'signature_resigned',
                'actor_type' => SignatureAuditLog::ACTOR_SIGNER,
                'actor_name' => $req->signer_name,
                'actor_email' => $req->signer_email,
                'actor_ip_address' => $http->ip(),
                'actor_user_agent' => $http->userAgent(),
                'metadata_json' => [
                    'place_key' => $placeKey,
                    'kind' => $kind,
                    'generation' => $new->generation,
                    'previous_generation' => $latest->generation,
                    'previous_signed_at' => $latest->signed_at?->toIso8601String(),
                    'previous_image_hash' => $latest->image_hash,
                    'image_hash' => $hash,
                    'previous_source' => $latest->source,
                    'method' => $new->method,
                ],
            ]);
        }

        return $out;
    }
}
