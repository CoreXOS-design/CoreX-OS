<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Money received against a bidder invoice. Voided, never deleted outright: the row is archived with the reason. */
class AuctionInvoicePayment extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const KINDS = ['deposit' => 'Deposit', 'balance' => 'Balance', 'other' => 'Other'];

    protected $fillable = ['agency_id', 'invoice_id', 'kind', 'amount', 'received_at', 'reference', 'recorded_by', 'void_reason'];

    protected $casts = ['amount' => 'decimal:2', 'received_at' => 'date'];

    public function invoice()
    {
        return $this->belongsTo(AuctionInvoice::class, 'invoice_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
