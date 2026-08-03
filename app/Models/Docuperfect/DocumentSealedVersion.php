<?php

namespace App\Models\Docuperfect;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * E-Sign P1 — one immutable, hash-chained snapshot of a document exactly as it
 * stood at a signing/approval hop. Append-only (no updated_at); the model refuses
 * any update so a sealed copy can never be mutated after the fact.
 *
 * content_hash = sha256((prev_hash ?? '') . sealed_html) — chaining each version to
 * the previous makes the whole trail tamper-evident (see verifyChain()).
 */
class DocumentSealedVersion extends Model
{
    protected $table = 'document_sealed_versions';

    // Write-once: created_at only, no updated_at.
    const UPDATED_AT = null;

    protected $fillable = [
        'document_id',
        'signature_template_id',
        'version',
        'event_type',
        'signer_identity',
        'signer_user_id',
        'actor_type',
        'actor_name',
        'actor_role',
        'sealed_html',
        'content_hash',
        'prev_hash',
        'ip_address',
        'user_agent',
        'agency_id',
    ];

    protected $casts = [
        'version'    => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * Immutability guard — a sealed copy is a legal record and may never change.
     * Mirrors ConditionInitial / the append-only audit log. Inserts pass; any
     * update throws.
     */
    public function save(array $options = [])
    {
        if ($this->exists) {
            throw new \DomainException('DocumentSealedVersion is append-only and cannot be updated.');
        }
        return parent::save($options);
    }

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function template()
    {
        return $this->belongsTo(SignatureTemplate::class, 'signature_template_id');
    }

    public function signer()
    {
        return $this->belongsTo(User::class, 'signer_user_id');
    }

    /** Next monotonic version number for a document. */
    public static function nextVersion(int $documentId): int
    {
        return (int) (static::where('document_id', $documentId)->max('version') ?? 0) + 1;
    }

    /** The most recently sealed version for a document (the chain head). */
    public static function latestFor(int $documentId): ?self
    {
        return static::where('document_id', $documentId)->orderByDesc('version')->first();
    }

    /** Compute the chained content hash for a given prior hash + content. */
    public static function computeHash(?string $prevHash, string $sealedHtml): string
    {
        return hash('sha256', ($prevHash ?? '') . $sealedHtml);
    }

    /**
     * Walk a document's sealed chain in order and verify every link. Returns
     * ['ok' => bool, 'count' => int, 'breaks' => [version, ...]]. A break means a
     * row's stored content_hash does not match sha256(prev_hash . sealed_html), or
     * its prev_hash does not equal the prior row's content_hash — i.e. tampering.
     */
    public static function verifyChain(int $documentId): array
    {
        $rows = static::where('document_id', $documentId)->orderBy('version')->get();
        $breaks = [];
        $expectedPrev = null;
        foreach ($rows as $row) {
            $recomputed = self::computeHash($row->prev_hash, $row->sealed_html);
            $linkOk = ($row->content_hash === $recomputed) && ($row->prev_hash === $expectedPrev);
            if (! $linkOk) {
                $breaks[] = (int) $row->version;
            }
            $expectedPrev = $row->content_hash;
        }
        return ['ok' => empty($breaks), 'count' => $rows->count(), 'breaks' => $breaks];
    }
}
