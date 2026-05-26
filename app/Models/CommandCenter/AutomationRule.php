<?php

namespace App\Models\CommandCenter;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class AutomationRule extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'name', 'description', 'is_active', 'is_system',
        'trigger_model', 'trigger_event', 'trigger_conditions',
        'action_type', 'action_config',
        'agency_id', 'branch_id', 'sort_order',
    ];

    protected $casts = [
        'is_active'          => 'boolean',
        'is_system'          => 'boolean',
        'trigger_conditions' => 'array',
        'action_config'      => 'array',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(AutomationLog::class, 'rule_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForTrigger($query, string $model, string $event)
    {
        return $query->where('trigger_model', $model)->where('trigger_event', $event);
    }
}
