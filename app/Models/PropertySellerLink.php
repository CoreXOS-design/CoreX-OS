<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertySellerLink extends Model
{
    protected $fillable = [
        'property_id', 'token', 'contact_id', 'generated_by_user_id',
        'generated_at', 'last_accessed_at', 'access_count',
        'revoked_at', 'revoked_by_user_id',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function property(): BelongsTo { return $this->belongsTo(Property::class); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function generatedBy(): BelongsTo { return $this->belongsTo(User::class, 'generated_by_user_id'); }

    public function isActive(): bool { return $this->revoked_at === null; }

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32)); // 64-char hex
    }

    /**
     * Ensure an active seller link exists for a (property, contact) pair.
     * Returns the existing active link or creates a new one. Idempotent.
     */
    public static function ensureExists(int $propertyId, int $contactId, ?int $generatedByUserId = null): self
    {
        $existing = static::where('property_id', $propertyId)
            ->where('contact_id', $contactId)
            ->whereNull('revoked_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        return static::create([
            'property_id' => $propertyId,
            'contact_id' => $contactId,
            'token' => static::generateToken(),
            'generated_by_user_id' => $generatedByUserId ?? auth()->id() ?? 1,
            'generated_at' => now(),
        ]);
    }
}
