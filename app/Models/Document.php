<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    use SoftDeletes;

    protected $table = 'documents';

    protected $fillable = [
        'original_name', 'storage_path', 'disk', 'mime_type', 'size',
        'document_type_id', 'source_type', 'source_id', 'uploaded_by',
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
