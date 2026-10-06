<?php

namespace App\Models\PlatformEsign;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Who changed the Subscription Agreement wording, and when (spec §11.13). Append-only. */
class WordingAudit extends Model
{
    protected $table = 'platform_esign_wording_audit';
    public $timestamps = false;
    protected $fillable = ['template_id', 'version_id', 'action', 'user_id', 'detail', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class, 'user_id'); }
    public function version() { return $this->belongsTo(WordingVersion::class, 'version_id')->withTrashed(); }
}
