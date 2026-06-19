<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SharedDriveFile extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'folder_id',
        'original_name',
        'stored_path',
        'mime_type',
        'extension',
        'bytes',
        'uploaded_by_user_id',
    ];

    protected $casts = [
        'bytes' => 'integer',
    ];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(SharedDriveFolder::class, 'folder_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** True when the file can be previewed inline in the browser. */
    public function isViewableInline(): bool
    {
        $ext = strtolower((string) $this->extension);
        return in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    }

    /** True for image types (rendered as <img>, vs PDF in an iframe). */
    public function isImage(): bool
    {
        return in_array(strtolower((string) $this->extension), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    }

    /** Human-readable size, e.g. "2.4 MB". */
    public function getHumanSizeAttribute(): string
    {
        $bytes = (int) $this->bytes;
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return round($value, 1) . ' ' . $units[$i];
    }
}
