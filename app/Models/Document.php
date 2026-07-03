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

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->storage_path);
    }

    public function downloadResponse()
    {
        return Storage::disk($this->disk)->download($this->storage_path, $this->original_name);
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
