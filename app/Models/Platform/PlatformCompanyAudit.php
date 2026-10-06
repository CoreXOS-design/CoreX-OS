<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;

/** Append-only: who changed the platform company record, when, and what (from → to). */
class PlatformCompanyAudit extends Model
{
    public $timestamps = false;
    protected $table = 'platform_company_audit';
    protected $fillable = ['user_id', 'action', 'summary', 'changes', 'created_at'];
    protected $casts = ['changes' => 'array', 'created_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}
