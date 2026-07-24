<?php

namespace App\Models\Compliance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One TFS list ingest run. This IS the list "version" — the XML carries none, so
 * (source_feed, fetched-at, content_sha256) is our version identity + freshness proof.
 */
class SanctionsListImport extends Model
{
    protected $fillable = [
        'source_feed', 'source_label', 'source_url', 'fetch_method', 'http_status',
        'content_sha256', 'file_bytes', 'source_filename', 'record_count',
        'individual_count', 'entity_count', 'status', 'error', 'list_published_at',
        'started_at', 'finished_at',
    ];

    protected $casts = [
        'list_published_at' => 'date',
        'started_at'        => 'datetime',
        'finished_at'       => 'datetime',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(SanctionsListEntry::class, 'import_id');
    }

    /** Newest SUCCESSFUL import for a feed (the version screening consults). */
    public static function latestSuccessful(string $sourceFeed): ?self
    {
        return static::where('source_feed', $sourceFeed)
            ->where('status', 'success')
            ->orderByDesc('finished_at')
            ->first();
    }
}
