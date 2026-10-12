<?php

namespace App\Models\TitleDeeds;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One owner on a title deed record. `name_snapshot` keeps the name readable if the contact is later archived. */
class TitleDeedOwner extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'title_deed_owners';

    protected $fillable = ['agency_id', 'title_deed_id', 'contact_id', 'name_snapshot'];

    public function deed(): BelongsTo
    {
        return $this->belongsTo(TitleDeed::class, 'title_deed_id')->withTrashed();
    }

    /** The live contact, if it still exists. An archived / deleted contact reads as null here; use $displayName. */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function getDisplayNameAttribute(): string
    {
        $contact = $this->relationLoaded('contact') ? $this->contact : $this->contact()->first();
        if ($contact) {
            $name = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return $this->name_snapshot !== '' ? $this->name_snapshot : 'Owner #' . $this->contact_id;
    }
}
