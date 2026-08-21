<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\BelongsToAgency;

class CommissionSettingAuditEntry extends Model
{
    use BelongsToAgency;

    protected $table = 'commission_setting_audit_log';

    protected $fillable = [
        'agency_id',
        'commission_setting_id', 'action',
        'old_values', 'new_values',
        'performed_by_user_id', 'performed_at',
        'notes',
    ];

    protected $casts = [
        'old_values'   => 'array',
        'new_values'   => 'array',
        'performed_at' => 'datetime',
    ];

    public function commissionSetting(): BelongsTo
    {
        return $this->belongsTo(CommissionSetting::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }
}
