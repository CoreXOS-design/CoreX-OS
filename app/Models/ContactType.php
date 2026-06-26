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
     * Fixed parent types that do NOT map to an e-sign role — they exist purely
     * to categorise contacts (esign_role is null). Locked like the 4 e-sign
     * parents; together the six are the only top-level contact types.
     */
    public const EXTRA_PARENTS = ['Owner', 'Other'];

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

    /**
     * The four canonical parents, in display order. Matched on the canonical
     * NAME per role — NOT merely a canonical esign_role: un-normalised installs
     * carry rogue legacy types (e.g. "Buyer, Lead", "Seller, Owner") that the
     * old name-pattern migration also stamped with a canonical esign_role.
     * Those collapse into sub-tags only once `contacts:normalise-types` runs;
     * until then this scope still returns exactly the 4 true parents.
     */
    public function scopeCanonical($query)
    {
        return $query->whereIn('esign_role', array_keys(self::CANONICAL))
                     ->whereIn('name', array_values(self::CANONICAL))
                     ->orderBy('sort_order')->orderBy('id');
    }

    /**
     * All SIX fixed parent types, in display order: the 4 e-sign roles
     * (Seller/Buyer/Lessor/Lessee) plus the non-e-sign Owner/Other. This is the
     * set shown in Settings + the contact-type picker, and the only valid
     * parents a sub-tag or a contact assignment may reference.
     */
    public function scopeParents($query)
    {
        return $query->where(function ($q) {
            $q->where(function ($e) {
                $e->whereIn('esign_role', array_keys(self::CANONICAL))
                  ->whereIn('name', array_values(self::CANONICAL));
            })->orWhere(function ($x) {
                $x->whereNull('esign_role')->whereIn('name', self::EXTRA_PARENTS);
            });
        })->orderBy('sort_order')->orderBy('id');
    }

    /** IDs of the six fixed parents (global — no agency scope). */
    public static function parentIds(): array
    {
        return static::query()->parents()->pluck('id')->all();
    }

    /**
     * Whether this type is one of the six fixed parents (4 e-sign + Owner/Other).
     * Gates the "no add/rename/delete parent" lock in ContactTypeController.
     */
    public function isLocked(): bool
    {
        return in_array($this->esign_role, array_keys(self::CANONICAL), true)
            || (is_null($this->esign_role) && in_array($this->name, self::EXTRA_PARENTS, true));
    }
}
