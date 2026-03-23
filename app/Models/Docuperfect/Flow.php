<?php

namespace App\Models\Docuperfect;

use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'pack_id',
        'pack_type',
        'flow_sequence',
        'parent_flow_id',
        'pack_status',
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

    public function signingParties(): HasMany
    {
        return $this->hasMany(ESignSigningParty::class, 'flow_id')
            ->orderBy('signing_order');
    }

    /**
     * Parent flow (first flow in a pack chain).
     */
    public function parentFlow()
    {
        return $this->belongsTo(self::class, 'parent_flow_id');
    }

    /**
     * Child flows in the same pack chain.
     */
    public function packFlows()
    {
        return $this->hasMany(self::class, 'parent_flow_id')
            ->orderBy('flow_sequence');
    }

    /**
     * All flows in the same pack (by pack_id).
     */
    public function scopeForPack($query, $packId, ?string $packType = null)
    {
        $q = $query->where('pack_id', $packId);
        if ($packType) {
            $q->where('pack_type', $packType);
        }
        return $q->orderBy('flow_sequence');
    }

    /**
     * Check if this is part of a pack flow.
     */
    public function isPackFlow(): bool
    {
        return !empty($this->pack_id);
    }

    /**
     * Get the next flow in the pack chain.
     */
    public function nextPackFlow(): ?self
    {
        if (!$this->isPackFlow()) {
            return null;
        }

        return self::where('pack_id', $this->pack_id)
            ->where('pack_type', $this->pack_type)
            ->where('flow_sequence', '>', $this->flow_sequence)
            ->orderBy('flow_sequence')
            ->first();
    }

    /**
     * Get shared data from the parent (first) flow in the pack.
     */
    public function getSharedPackData(): array
    {
        $sourceFlow = $this->parent_flow_id
            ? $this->parentFlow
            : $this;

        if (!$sourceFlow) {
            return [];
        }

        $stepData = $sourceFlow->step_data ?? [];

        return [
            'property' => $stepData['property'] ?? [],
            'recipients' => $stepData['recipients'] ?? [],
            'details' => $stepData['details'] ?? [],
            'rental_details' => $stepData['rental_details'] ?? [],
        ];
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
