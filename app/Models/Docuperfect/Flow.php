<?php

namespace App\Models\Docuperfect;

use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Flow extends Model
{
    use SoftDeletes;

    protected $table = 'flows';

    protected $fillable = [
        'type',
        'template_id',
        'user_id',
        'property_id',
        'contact_id',
        'current_step',
        'step_data',
        'status',
        'completed_at',
    ];

    protected $casts = [
        'step_data' => 'array',
        'completed_at' => 'datetime',
    ];

    public function template()
    {
        return $this->belongsTo(Template::class, 'template_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function property()
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function getStepDataFor(string $key): ?array
    {
        return $this->step_data[$key] ?? null;
    }

    public function setStepDataFor(string $key, array $data): void
    {
        $stepData = $this->step_data ?? [];
        $stepData[$key] = $data;
        $this->step_data = $stepData;
        $this->save();
    }
}
