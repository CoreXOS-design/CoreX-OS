<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An agent's saved signature + initial images and signing PIN.
 *
 * signature_image / initial_image use the 'encrypted' cast — ciphertext at rest,
 * transparently decrypted only when the model is read (and reads are gated by
 * AgentSignatureService, never under impersonation). signing_pin is a bcrypt hash.
 * All three are $hidden so they can never leak through a toArray()/JSON response.
 */
class AgentSignature extends Model
{
    protected $fillable = [
        'user_id', 'agency_id',
        'signature_image', 'initial_image',
        'signing_pin', 'pin_set_at', 'images_updated_at',
    ];

    protected $hidden = ['signing_pin', 'signature_image', 'initial_image'];

    protected $casts = [
        'signature_image'   => 'encrypted',
        'initial_image'     => 'encrypted',
        'pin_set_at'        => 'datetime',
        'images_updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasImages(): bool
    {
        return ! empty($this->signature_image) && ! empty($this->initial_image);
    }

    public function hasPin(): bool
    {
        return ! empty($this->signing_pin);
    }
}
