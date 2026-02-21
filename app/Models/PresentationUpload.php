<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PresentationUpload extends Model
{
    protected $fillable = [
        'presentation_id',
        'uploaded_by_user_id',
        'type',
        'original_filename',
        'storage_path',
        'text_extracted',
        'extraction_json',
        'extraction_status',
    ];

    public function presentation()
    {
        return $this->belongsTo(Presentation::class);
    }

    public function soldComps()
    {
        return $this->hasMany(PresentationSoldComp::class, 'source_upload_id');
    }

    public function activeListings()
    {
        return $this->hasMany(PresentationActiveListing::class, 'source_upload_id');
    }
}
