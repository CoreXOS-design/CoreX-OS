<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContactType extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'color', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }
}
