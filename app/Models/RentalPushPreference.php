<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AT-508 (P1) — one tenant/owner's own on/off for ONE switchable push category. Person-level (a ClientUser spans agencies);
 * the agency default lives on rental_portal_settings.push_informational_default. Mandatory categories never get a row.
 */
class RentalPushPreference extends Model
{
    protected $fillable = ['client_user_id', 'category', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];
}
