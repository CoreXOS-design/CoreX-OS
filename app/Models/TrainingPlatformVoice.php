<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-525 (spec section 9.20) - a "CoreX voice": a Chatterbox voice the SYSTEM OWNER made from a reference clip (with the speaker's consent), available to every agency.
 * The reference clip stays on the private disk; an agency only ever hears the generated samples and videos.
 */
class TrainingPlatformVoice extends Model
{
    use SoftDeletes;

    protected $table = 'training_platform_voices';
    protected $guarded = [];
    protected $casts = ['consent_confirmed_at' => 'datetime', 'is_default' => 'boolean', 'is_active' => 'boolean', 'reference_seconds' => 'float'];
}
