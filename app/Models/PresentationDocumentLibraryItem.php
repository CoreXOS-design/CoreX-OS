<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class PresentationDocumentLibraryItem extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'presentation_id',
        'document_library_item_id',
        'attached_by_user_id',
        'note',
    ];

    public function presentation()
    {
        return $this->belongsTo(Presentation::class);
    }

    public function documentLibraryItem()
    {
        return $this->belongsTo(DocumentLibraryItem::class);
    }

    public function attachedBy()
    {
        return $this->belongsTo(User::class, 'attached_by_user_id');
    }
}
