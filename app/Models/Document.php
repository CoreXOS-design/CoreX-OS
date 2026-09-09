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
