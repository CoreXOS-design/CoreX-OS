<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-work-orders.md §3a/§3a.3 — evidence at the time of
 * report. Immutable, no deleted_at, same evidence-integrity reasoning as
 * rental_inspection_photos. Deliberately no photo_type column — see the
 * migration's own docblock.
 */
class RentalFaultReportPhoto extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    protected $fillable = [
        'agency_id',
        'rental_fault_report_id',
        'storage_path',
        'uploaded_by_user_id',
        'client_idempotency_key',
        'file_size_bytes',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $photo) {
            if (empty($photo->client_idempotency_key)) {
                $photo->client_idempotency_key = (string) \Illuminate\Support\Str::uuid();
            }
            if (empty($photo->created_at)) {
                $photo->created_at = now();
            }
        });
    }

    public function faultReport(): BelongsTo
    {
        return $this->belongsTo(RentalFaultReport::class, 'rental_fault_report_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * D14 - the guards that kill a signed picture URL the moment this photo or its fault report is removed / archived.
     *
     * @return array<int,array{0:string,1:int}>
     */
    public function imageGuards(): array
    {
        return [['rental_fault_report_photos', (int) $this->id], ['rental_fault_reports', (int) $this->rental_fault_report_id]];
    }

    /**
     * What an office screen puts in an `<img src>`: a signed, expiring URL for a `private:` picture (a portal upload), the stored
     * address untouched for an older public one.
     */
    public function displayUrl(string $variant = \App\Services\Images\PrivateImageService::VARIANT_FULL): ?string
    {
        return app(\App\Services\Images\PrivateImageService::class)->displayUrl($this->storage_path, (int) $this->agency_id, $this->imageGuards(), $variant);
    }

    /** The file on disk (PDF, mail attachment, GD) for either kind of stored picture; null when it is not there. */
    public function absolutePath(): ?string
    {
        return app(\App\Services\Images\PrivateImageService::class)->absolutePath($this->storage_path);
    }
}
