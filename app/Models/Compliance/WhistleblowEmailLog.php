<?php

namespace App\Models\Compliance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\BelongsToAgency;
class WhistleblowEmailLog extends Model
{
    use BelongsToAgency;

    const UPDATED_AT = null;

    protected $table = 'whistleblow_email_log';

    protected $fillable = [
        'agency_id',
        'complaint_id',
        'sent_at',
        'email_type',
        'subject',
        'recipients_to',
        'recipients_cc',
        'recipients_bcc',
        'rendered_html',
        'rendered_text',
        'attachments',
        'sent_by_user_id',
        'mail_message_id',
        'status',
        'error_message',
    ];

    protected $casts = [
        'sent_at'        => 'datetime',
        'recipients_to'  => 'array',
        'recipients_cc'  => 'array',
        'recipients_bcc' => 'array',
        'attachments'    => 'array',
    ];

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(WhistleblowComplaint::class, 'complaint_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
