<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/** Append-only record of a contact's role on a property in a finished cycle. */
class PropertyContactRoleHistory extends Model
{
    use BelongsToAgency;

    protected $table = 'property_contact_role_history';

    protected $guarded = ['id'];

    protected $casts = ['ended_at' => 'datetime'];
}
