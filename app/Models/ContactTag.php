<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

use App\Models\Concerns\BelongsToAgency;
class ContactTag extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id','name', 'color', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_tag')
                    ->withTimestamps();
    }
}
