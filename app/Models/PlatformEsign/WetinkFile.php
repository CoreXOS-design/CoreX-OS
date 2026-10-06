<?php

namespace App\Models\PlatformEsign;

use Illuminate\Database\Eloquent\Model;

/** A page/scan of the hand-signed agreement uploaded by the agency (spec §11.8). Superseded files are kept. */
class WetinkFile extends Model
{
    protected $table = 'platform_esign_wetink_files';

    protected $fillable = ['document_id', 'batch', 'original_name', 'stored_path', 'mime', 'size', 'sha256', 'uploaded_ip', 'superseded_at'];

    protected $casts = ['superseded_at' => 'datetime'];

    public function document() { return $this->belongsTo(Document::class, 'document_id'); }

    public function isActive(): bool
    {
        return $this->superseded_at === null;
    }
}
