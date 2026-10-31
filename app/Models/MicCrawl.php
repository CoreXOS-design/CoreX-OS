<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One extension crawl (session) of a portal search, with what it covered (mic-work-tab.md 11.3.A). Agency-scoped by explicit queries in CrawlRecords. */
class MicCrawl extends Model
{
    protected $table = 'mic_crawls';

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime', 'finished_at' => 'datetime', 'gone_applied_at' => 'datetime',
        'is_complete' => 'boolean', 'is_filtered' => 'boolean',
        'pages_read' => 'integer', 'pages_total' => 'integer', 'adverts_seen' => 'integer',
    ];

    public function suburbs(): HasMany
    {
        return $this->hasMany(MicCrawlSuburb::class, 'mic_crawl_id');
    }

    /** A crawl the Gone rule and the "complete crawl within N days" helper may rely on. */
    public function isUsable(): bool
    {
        return $this->is_complete && ! $this->is_filtered && $this->adverts_seen > 0;
    }
}
