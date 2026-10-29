<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One document attached to a registration, and staff's verdict on it. */
class AuctionRegistrationDocument extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = ['agency_id', 'registration_id', 'bidder_document_id', 'kind', 'status', 'reviewed_by', 'reviewed_at', 'reject_reason'];

    protected $casts = ['reviewed_at' => 'datetime'];

    public function registration()
    {
        return $this->belongsTo(AuctionRegistration::class, 'registration_id');
    }

    public function document()
    {
        return $this->belongsTo(BidderDocument::class, 'bidder_document_id')->withTrashed();
    }
}
