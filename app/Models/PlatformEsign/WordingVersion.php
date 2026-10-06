<?php

namespace App\Models\PlatformEsign;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One immutable published version of a web document's wording + rates (spec §11.2).
 * A sent document pins a version and always renders it; editing wording = a new version, never an update.
 */
class WordingVersion extends Model
{
    protected $table = 'platform_esign_wording_versions';

    protected $fillable = ['template_id', 'version', 'version_date', 'content_json', 'rates_json', 'layout_json', 'is_published', 'published_at', 'created_by'];

    protected $casts = [
        'version_date' => 'date', 'content_json' => 'array', 'rates_json' => 'array', 'layout_json' => 'array',
        'is_published' => 'boolean', 'published_at' => 'datetime',
    ];

    public function template() { return $this->belongsTo(Template::class, 'template_id')->withTrashed(); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    /** "Version 1.0 — 28 September 2026" */
    public function label(): string
    {
        return 'Version ' . $this->version . ' — ' . $this->version_date->format('j F Y');
    }
}
