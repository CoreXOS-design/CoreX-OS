<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    use SoftDeletes, BelongsToAgency, BelongsToBranch;

    protected $table = 'documents';

    protected $fillable = [
        'agency_id',
        'branch_id',
        'original_name', 'storage_path', 'disk', 'mime_type', 'size',
        'document_type_id', 'source_type', 'source_id', 'uploaded_by',
        'deal_id', // AT-158 WS3 (D4) — DR2 deal anchor
    ];

    protected $casts = ['size' => 'integer'];

    // ── Relationships ──

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'document_contacts')
            ->withPivot('party_role')
            ->withTimestamps();
    }

    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'document_properties')
            ->withTimestamps();
    }

    /** AT-392 — rental applications this document is REFERENCED on, distinct from its one filing home (source_type/source_id). */
    public function rentalApplications(): BelongsToMany
    {
        return $this->belongsToMany(RentalApplication::class, 'rental_application_document')
            ->withPivot('attached_by')
            ->withTimestamps();
    }

    /**
     * AT-158 WS3 (D4) — the DR2 deal this document is filed against (if any).
     * Nullable: most documents are not deal-anchored; a deleted deal clears
     * the anchor (nullOnDelete) rather than orphaning the file.
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(\App\Models\DealV2\DealV2::class, 'deal_id');
    }

    // ── Helpers ──

    /**
     * A stored display name may never contain a path separator.
     *
     * Symfony's Content-Disposition builder rejects "/" and "\\" outright
     * (HeaderUtils::makeDisposition), so a document whose original_name carries
     * one throws InvalidArgumentException on EVERY download or inline view of
     * it — the file's bytes are fine, its name is simply unusable. That is a
     * 500 "Something went wrong" at all 11+ serve call-sites at once, not at
     * the one that happened to be clicked.
     *
     * The names came from the PDF splitter, which composes
     * "Subject · DocType · Date.pdf" — and one document-type label is literally
     * "IDs / Identity", so the slash landed inside the filename. Sanitising at
     * the model means no writer anywhere can put a broken name in the table,
     * whatever composes it. Paired with the splitter sanitising its own
     * baseName so its duplicate-name check compares the stored form.
     */
    public static function sanitizeOriginalName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        return trim(str_replace(['/', '\\'], '-', $name));
    }

    public function setOriginalNameAttribute($value): void
    {
        $this->attributes['original_name'] = static::sanitizeOriginalName($value);
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->storage_path);
    }

    /**
     * AT-173 — decrypted bytes for this document. Enveloped (encrypted) files are
     * decrypted; legacy plaintext passes through unchanged. Every byte-reader of a
     * potentially-encrypted Document (currently source_type='fica') MUST go through
     * this (or downloadResponse), never read the raw file directly.
     */
    public function decryptedContents(): ?string
    {
        $raw = Storage::disk($this->disk)->get($this->storage_path);

        return app(\App\Services\Security\MediaCipher::class)->decrypt($raw);
    }

    public function downloadResponse()
    {
        // AT-173 — stream decrypted through PHP (a plaintext file is streamed as-is).
        $bytes = $this->decryptedContents();

        return response()->streamDownload(
            function () use ($bytes) {
                echo $bytes;
            },
            $this->original_name,
            ['Content-Type' => $this->mime_type ?: 'application/octet-stream']
        );
    }

    /**
     * The Content-Type this document may be served INLINE as, or null when it may not be.
     *
     * Deliberately a whitelist that returns a FIXED string rather than echoing the stored
     * mime_type: an inline response renders in the app's own origin, so a row whose mime_type
     * says `text/html` (or an `.svg`, which is a script carrier) would be stored XSS. Only PDF
     * and raster images are viewable; everything else falls back to download.
     * Spec: .ai/specs/document-inline-view.md §5.2
     */
    public function inlineMimeType(): ?string
    {
        $mime = strtolower(trim((string) $this->mime_type));
        $ext  = strtolower(pathinfo((string) $this->original_name, PATHINFO_EXTENSION));

        // mime_type is authoritative when present; legacy rows with a null/blank mime
        // (and generic octet-stream uploads) fall back to the file's own extension.
        $key = match (true) {
            $mime === 'application/pdf'                  => 'pdf',
            $mime === 'image/jpeg' || $mime === 'image/jpg' => 'jpg',
            $mime === 'image/png'                        => 'png',
            $mime === 'image/gif'                        => 'gif',
            $mime === 'image/webp'                       => 'webp',
            $mime === '' || $mime === 'application/octet-stream' => $ext,
            default                                      => null,
        };

        return match ($key) {
            'pdf'          => 'application/pdf',
            'jpg', 'jpeg'  => 'image/jpeg',
            'png'          => 'image/png',
            'gif'          => 'image/gif',
            'webp'         => 'image/webp',
            default        => null,
        };
    }

    /**
     * May this document be opened in the browser instead of downloaded?
     * Drives whether a View affordance renders for the row at all.
     */
    public function isViewableInline(): bool
    {
        return $this->inlineMimeType() !== null;
    }

    /**
     * Inline (in-browser) counterpart of downloadResponse().
     *
     * Goes through decryptedContents() for the same reason downloadResponse() does (AT-173):
     * an enveloped FICA document must never be handed to the browser as cipher bytes. Callers
     * MUST check isViewableInline() first — a non-viewable document has no safe Content-Type,
     * so this refuses rather than guessing.
     */
    public function inlineResponse()
    {
        $contentType = $this->inlineMimeType();

        abort_if($contentType === null, 404, 'This document cannot be viewed in the browser.');

        return response($this->decryptedContents(), 200, [
            'Content-Type'            => $contentType,
            'Content-Disposition'     => 'inline; filename="' . addslashes($this->original_name) . '"',
            'X-Content-Type-Options'  => 'nosniff',
        ]);
    }

    public function getHumanSizeAttribute(): string
    {
        $bytes = (int) $this->size;
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type ?? '', 'image/');
    }
}
