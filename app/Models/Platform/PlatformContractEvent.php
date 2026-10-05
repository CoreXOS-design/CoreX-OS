<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;

/** Append-only audit row for a platform contract. Spec §6.2. */
class PlatformContractEvent extends Model
{
    public $timestamps = false;

    protected $table = 'platform_contract_events';

    protected $fillable = ['envelope_id', 'event', 'detail', 'actor_user_id', 'ip'];

    protected $casts = ['created_at' => 'datetime'];
}
