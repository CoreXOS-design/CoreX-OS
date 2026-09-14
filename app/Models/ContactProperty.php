<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The real contact<->property link. A custom Pivot model (not an anonymous
 * pivot array) specifically so this can be soft-deleted — matches
 * DealProperty's own shape and reasoning exactly (app/Models/DealProperty.php).
 *
 * Johan: "we have to fix it. corex is a no delete system." Unlinking a
 * contact from a property keeps the row; it never disappears. Full
 * investigation: .ai/specs/rental-applications.md, "The contact_property
 * hard-delete fix".
 *
 * One contact holds exactly one role per property, ever (Johan, confirmed:
 * "contact should not be placed on the same property as different roles.
 * if that scenario happens the contact will be changed") — the unique
 * index on (contact_id, property_id) enforces this and is left untouched.
 * Every write path restores the one existing row (trashed or not) rather
 * than inserting a second one — see ContactPropertyLinker.
 */
class ContactProperty extends Pivot
{
    use SoftDeletes;

    protected $table = 'contact_property';

    public $incrementing = true;

    protected $fillable = [
        'contact_id', 'property_id', 'role', 'is_primary', 'source',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];
}
