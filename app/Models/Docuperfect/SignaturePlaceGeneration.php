<?php

namespace App\Models\Docuperfect;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One version of one signature / initial place on a web (CDS) document — the append-only evidence behind
 * documents.web_template_data's signature map (which always holds the LATEST generation, so rendering is
 * unchanged). Johan's signature-evidence rule (9 Sep 2026): a re-signature never overwrites the original.
 *
 * Never edited, never deleted. The only permitted change is stamping the row superseded when a newer
 * generation is written (superseded_at / superseded_by_id) — the same pattern as RentalInspectionSignature.
 */
class SignaturePlaceGeneration extends Model
{
    public const KIND_SIGNATURE = 'signature';
    public const KIND_INITIAL = 'initial';

    public const SOURCE_WEB_COMPLETE = 'web_complete';
    /** The original recovered from the JSON map when a pre-existing document is re-signed (its time/IP were never kept). */
    public const SOURCE_LEGACY_JSON = 'legacy_json';

    protected $table = 'signature_place_generations';

    protected $fillable = [
        'document_id', 'signature_template_id', 'signature_request_id', 'place_key', 'kind', 'generation',
        'image', 'image_hash', 'signer_name', 'signer_email', 'ip_address', 'user_agent', 'signed_at', 'source',
        'superseded_at', 'superseded_by_id',
    ];

    protected $casts = [
        'generation' => 'integer',
        'signed_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $row) {
            $changed = array_keys($row->getDirty());
            if (array_diff($changed, ['superseded_at', 'superseded_by_id', 'updated_at']) !== []) {
                throw new LogicException('A signature generation is evidence and cannot be edited — write a new generation.');
            }
        });

        static::deleting(function () {
            throw new LogicException('A signature generation is evidence and cannot be deleted.');
        });
    }

    public function scopeForPlace($query, int $documentId, string $placeKey)
    {
        return $query->where('document_id', $documentId)->where('place_key', $placeKey);
    }
}
