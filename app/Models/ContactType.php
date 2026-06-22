<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ContactType extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'color', 'sort_order', 'is_active', 'esign_role'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    /**
     * The four fixed, system-locked parents — esign_role => display name.
     * Contact types collapse to exactly these (AT-79). Each is permanently
     * bound to its e-sign role so the signing wizard's 1:1 role mapping holds.
     */
    public const CANONICAL = [
        'seller' => 'Seller',
        'buyer'  => 'Buyer',
        'lessor' => 'Lessor',
        'lessee' => 'Lessee',
    ];

    /**
     * Primary-type mirror relation (contacts.contact_type_id). Retained for the
     * many existing readers + the e-sign reverse-mapping. Parent membership for
     * NEW work lives in the contact_contact_type pivot (see Contact::parentTypes).
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /** Contacts assigned this parent via the multi-parent pivot. */
    public function contactsTyped(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_contact_type')
                    ->withTimestamps();
    }

    /** Custom sub-tags nested under this parent (agency-scoped). */
    public function subTags(): HasMany
    {
        return $this->hasMany(ContactTag::class, 'contact_type_id')
                    ->orderBy('sort_order')->orderBy('name');
    }

    public function scopeForEsignRole($query, string $role)
    {
        return $query->where('esign_role', $role);
    }

    /** The four canonical parents, in display order. */
    public function scopeCanonical($query)
    {
        return $query->whereIn('esign_role', array_keys(self::CANONICAL))
                     ->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Whether this type is one of the four fixed parents. All real contact
     * types are now parents, so this gates the "no add/rename/delete parent"
     * lock enforced in ContactTypeController.
     */
    public function isLocked(): bool
    {
        return in_array($this->esign_role, array_keys(self::CANONICAL), true);
    }
}
