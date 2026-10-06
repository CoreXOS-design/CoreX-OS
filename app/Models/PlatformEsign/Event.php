<?php

namespace App\Models\PlatformEsign;

use Illuminate\Database\Eloquent\Model;

/** Append-only audit trail. */
class Event extends Model
{
    public $timestamps = false;
    protected $table = 'platform_esign_events';
    protected $fillable = ['document_id', 'signer_id', 'event', 'detail', 'actor_user_id', 'ip', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];

    public function actor() { return $this->belongsTo(\App\Models\User::class, 'actor_user_id'); }
    public function signer() { return $this->belongsTo(Signer::class, 'signer_id'); }
}
