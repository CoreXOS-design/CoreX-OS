<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactMatchFeedback extends Model
{
    public const REACTION_INTERESTED     = 'interested';
    public const REACTION_NOT_INTERESTED = 'not_interested';
    public const REACTION_SAVED          = 'saved';

    protected $table = 'contact_match_feedback';

    protected $fillable = [
        'contact_match_id',
        'property_id',
        'reaction',
        'note',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(ContactMatch::class, 'contact_match_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
