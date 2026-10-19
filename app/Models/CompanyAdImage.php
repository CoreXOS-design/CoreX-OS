<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A picture an agency uploaded to place IN a company ad (.ai/specs/company-ads.md §4).
 * Public disk, under company-ads/{agency_id}/, served host-relative so html2canvas can export it.
 */
class CompanyAdImage extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = ['agency_id', 'user_id', 'path', 'width', 'height'];

    protected $casts = [
        'width'  => 'integer',
        'height' => 'integer',
    ];

    /** Host-relative URL (same-origin on every host, html2canvas-safe). */
    public function url(): string
    {
        return '/' . ltrim(parse_url(Storage::disk('public')->url($this->path), PHP_URL_PATH) ?: '', '/');
    }
}
