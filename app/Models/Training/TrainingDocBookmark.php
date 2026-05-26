<?php

namespace App\Models\Training;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingDocBookmark extends Model
{
    protected $table = 'training_doc_bookmarks';

    protected $fillable = [
        'user_id', 'doc_id', 'section_anchor', 'note',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function doc(): BelongsTo
    {
        return $this->belongsTo(TrainingDoc::class, 'doc_id');
    }
}
