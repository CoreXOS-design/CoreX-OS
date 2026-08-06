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
    // AT-323 — the honest terminal state when the agent confirms on the sent page
    // that WhatsApp did NOT actually go out. Never counted as a reached send.
    public const OUTCOME_NOT_SENT = 'not_sent';

    protected $fillable = [
        'agency_id',
        'contact_id', 'property_id', 'agent_id', 'template_id',
        // AT-323 — link to the mirrored provisional Communication (comms archive),
        // so a "not sent" answer flips both this row's outcome AND the comm's send_status.
        'communication_id', 'channel',
        'subject_snapshot', 'body_snapshot', 'facts_snapshot',
        'tracking_short_code', 'opt_out_token', 'recipient_phone_snapshot', 'recipient_email_snapshot',
        'address_snapshot', 'suburb_snapshot',
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

    /** AT-323 — the mirrored provisional Communication this send was logged as. */
    public function communication(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Communications\Communication::class, 'communication_id');
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
