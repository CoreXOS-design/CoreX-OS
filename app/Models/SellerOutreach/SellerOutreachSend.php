<?php

declare(strict_types=1);

namespace App\Models\SellerOutreach;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SellerOutreachSend extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const OUTCOME_SENT = 'sent';
    public const OUTCOME_CLICKED = 'clicked';
    public const OUTCOME_REPLIED = 'replied';
    public const OUTCOME_BOOKED = 'booked';
    public const OUTCOME_NO_RESPONSE = 'no_response';
    public const OUTCOME_NOT_INTERESTED = 'not_interested';
    public const OUTCOME_BOUNCED = 'bounced';

    protected $fillable = [
        'agency_id',
        'contact_id', 'property_id', 'agent_id', 'template_id', 'channel',
        'subject_snapshot', 'body_snapshot', 'facts_snapshot',
        'tracking_short_code', 'opt_out_token', 'recipient_phone_snapshot', 'recipient_email_snapshot',
        'sent_at', 'first_clicked_at', 'outcome', 'outcome_note',
        'outcome_set_by_user_id', 'outcome_set_at',
    ];

    protected $casts = [
        'facts_snapshot' => 'array',
        'sent_at' => 'datetime',
        'first_clicked_at' => 'datetime',
        'outcome_set_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SellerOutreachTemplate::class, 'template_id');
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(SellerOutreachClick::class, 'send_id');
    }

    public function landingUrl(): string
    {
        return rtrim(config('app.url'), '/') . '/m/' . $this->tracking_short_code;
    }

    /** Public self-service opt-out URL for this send (AT-49); null if no token. */
    public function optOutUrl(): ?string
    {
        return $this->opt_out_token
            ? rtrim(config('app.url'), '/') . '/outreach/opt-out/' . $this->opt_out_token
            : null;
    }
}
