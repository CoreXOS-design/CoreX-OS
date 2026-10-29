<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A winning bidder's invoice for one sold lot (.ai/specs/auctions.md §17). The figures are a snapshot taken at the sale. */
class AuctionInvoice extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_PART_PAID = 'part_paid';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ISSUED, self::STATUS_PART_PAID, self::STATUS_PAID, self::STATUS_CANCELLED];

    /** Statuses the buyer may see and download. */
    public const BUYER_VISIBLE = [self::STATUS_ISSUED, self::STATUS_PART_PAID, self::STATUS_PAID];

    protected $fillable = [
        'agency_id', 'auction_id', 'lot_id', 'registration_id', 'deal_id', 'number', 'status', 'hammer_price', 'commission_percent',
        'commission_amount', 'vat_rate', 'vat_amount', 'total_amount', 'deposit_percent', 'deposit_amount', 'deposit_due_at',
        'balance_amount', 'balance_due_at', 'payment_details', 'notes', 'issued_at', 'issued_by', 'paid_at', 'cancelled_at',
        'cancel_reason', 'guarantee_received_at', 'guarantee_note',
    ];

    protected $casts = [
        'hammer_price' => 'decimal:2', 'commission_percent' => 'decimal:2', 'commission_amount' => 'decimal:2', 'vat_rate' => 'decimal:2',
        'vat_amount' => 'decimal:2', 'total_amount' => 'decimal:2', 'deposit_percent' => 'decimal:2', 'deposit_amount' => 'decimal:2',
        'balance_amount' => 'decimal:2', 'deposit_due_at' => 'datetime', 'balance_due_at' => 'datetime', 'issued_at' => 'datetime',
        'paid_at' => 'datetime', 'cancelled_at' => 'datetime', 'guarantee_received_at' => 'datetime',
    ];

    public function auction()
    {
        return $this->belongsTo(Auction::class)->withTrashed();
    }

    public function lot()
    {
        return $this->belongsTo(AuctionLot::class, 'lot_id')->withTrashed();
    }

    public function registration()
    {
        return $this->belongsTo(AuctionRegistration::class, 'registration_id')->withTrashed();
    }

    public function payments()
    {
        return $this->hasMany(AuctionInvoicePayment::class, 'invoice_id')->orderBy('received_at')->orderBy('id');
    }

    public function statusLabel(): string
    {
        return ucwords(str_replace('_', ' ', (string) $this->status));
    }

    public function paidTotal(): float
    {
        return round((float) $this->payments()->sum('amount'), 2);
    }

    public function paidOfKind(string $kind): float
    {
        return round((float) $this->payments()->where('kind', $kind)->sum('amount'), 2);
    }

    public function outstanding(): float
    {
        return max(0.0, round((float) $this->total_amount - $this->paidTotal(), 2));
    }

    public function depositPaid(): bool
    {
        // a deposit is covered by deposit payments, or by any payments that together reach the deposit
        return $this->paidTotal() + 0.005 >= (float) $this->deposit_amount;
    }

    /** Money is owed and a due date has passed: the deposit date with the deposit unpaid, or the balance date with anything unpaid. */
    public function isOverdue(): bool
    {
        if (! in_array($this->status, [self::STATUS_ISSUED, self::STATUS_PART_PAID], true)) {
            return false;
        }
        $depositLate = $this->deposit_due_at && $this->deposit_due_at->isPast() && ! $this->depositPaid();
        $balanceLate = $this->balance_due_at && $this->balance_due_at->isPast() && $this->outstanding() > 0.005;

        return $depositLate || $balanceLate;
    }

    /** Same visibility as the lot's bids: the lot's property decides own / branch / agency. */
    public function scopeVisibleTo($query, User $user)
    {
        return $query->whereIn('lot_id', AuctionLot::visibleTo($user)->withTrashed()->select('auction_lots.id'));
    }
}
