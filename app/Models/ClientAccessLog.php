<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class ClientAccessLog extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'client_user_id',
        'agency_id',
        'contact_id',
        'event',
        'meta',
        'ip',
        'user_agent',
        'device_name',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
