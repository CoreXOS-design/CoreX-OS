<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

use App\Models\Concerns\BelongsToAgency;
class ContactSource extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id','name', 'color', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'contact_source_id');
    }
}
