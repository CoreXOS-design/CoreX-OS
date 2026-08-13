<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Contact enhancement foundation — the entity <-> natural-person
 * representative link. Spec: .ai/specs/contact-entity-type.md §4.2.
 *
 * Extends Pivot (not a plain Model) so Contact::representatives()/
 * representedEntities()'s ->using(ContactRepresentative::class) applies
 * this class's casts (is_primary => boolean) to the loaded pivot instance —
 * a plain Model doesn't implement Pivot's fromRawAttributes() and similar,
 * which ->using() requires. This table has its own real auto-increment
 * `id` (not a composite key), so $incrementing is turned back on.
 *
 * representative_contact_id must reference a Contact with
 * type=Contact::TYPE_NATURAL_PERSON — validated at the write path
 * (App\Http\Controllers\CoreX\ContactController), not enforced in schema.
 */
class ContactRepresentative extends Pivot
{
    use SoftDeletes;

    public $incrementing = true;

    protected $fillable = [
        'entity_contact_id',
        'representative_contact_id',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function entityContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'entity_contact_id');
    }

    public function representativeContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'representative_contact_id');
    }
}
