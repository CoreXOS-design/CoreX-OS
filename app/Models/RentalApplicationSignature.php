<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalApplicationSignature extends Model
{
    protected $fillable = [
        'rental_application_id', 'kind', 'signature_path', 'signed_at', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
    ];

    public function rentalApplication(): BelongsTo
    {
        return $this->belongsTo(RentalApplication::class);
    }
}
