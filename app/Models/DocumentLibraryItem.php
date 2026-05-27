<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class DocumentLibraryItem extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'uploaded_by_user_id',
        'original_name',
        'stored_path',
        'mime_type',
        'bytes',
        'doc_type',
        'title',
        'description',
        'tags_json',
        'is_enabled',
    ];

    protected $casts = [
        'bytes'      => 'integer',
        'is_enabled' => 'boolean',
        'tags_json'  => 'array',
    ];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function presentations()
    {
        return $this->belongsToMany(Presentation::class, 'presentation_document_library_items')
            ->withPivot('attached_by_user_id', 'note')
            ->withTimestamps();
    }
}
