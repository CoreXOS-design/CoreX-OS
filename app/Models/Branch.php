<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'name',
        'code',
        'agency_id',
        'trading_name',
        'tagline',
        'address',
        'phone',
        'phone_label',
        'phone_secondary',
        'phone_secondary_label',
        'fax',
        'email',
        'reg_no',
        'vat_no',
        'ffc_no',
        'fic_no',
        'logo_path',
    ];

    /**
     * Returns contact detail value — branch value if set,
     * otherwise falls back to Agency value.
     */
    public function contactDetail(string $field): ?string
    {
        return $this->{$field} ?? $this->agency->{$field} ?? null;
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
