<?php

declare(strict_types=1);

namespace App\Models\Prospecting;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One phone/email row scraped from a TVA person's Contact tab. Ingestion is
 * agent-ticked, not automatic — ingested_at/ingested_contact_id record what
 * happened once they tick + submit. See TvaContactCapture for the person half.
 */
final class TvaContactCaptureItem extends Model
{
    public const TYPE_CELL = 'cell';
    public const TYPE_TEL = 'tel';
    public const TYPE_EMAIL = 'email';

    protected $fillable = [
        'tva_contact_capture_id',
        'type',
        'value',
        'date',
        'link_date',
        'opted_out',
        'ingested_at',
        'ingested_contact_id',
    ];

    protected $casts = [
        'date' => 'date',
        'link_date' => 'date',
        'opted_out' => 'boolean',
        'ingested_at' => 'datetime',
    ];

    public function capture(): BelongsTo
    {
        return $this->belongsTo(TvaContactCapture::class, 'tva_contact_capture_id');
    }

    public function ingestedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'ingested_contact_id');
    }

    public function isIngested(): bool
    {
        return $this->ingested_at !== null;
    }
}
