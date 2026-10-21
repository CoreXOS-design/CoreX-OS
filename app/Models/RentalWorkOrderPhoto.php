<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-work-orders.md §3.1/§3.4 — evidence photos, before AND
 * after, distinguished by photo_type. Immutable, no deleted_at.
 */
class RentalWorkOrderPhoto extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    /** rental_work_order_photos.uploaded_via — where the photo came from (§14.27.6 item 6). Null = uploaded before the column existed. */
    public const VIA_CREW_LINK = 'crew_link';
    public const VIA_CREW_PAGE = 'crew_page';
    public const VIA_OFFICE = 'office';
    /** §17.10.4 — a tenant's "not complete" photo (rental_work_order_photos.photo_type = RentalWorkOrder::PHOTO_DISPUTE). */
    public const VIA_TENANT = 'tenant';

    protected $fillable = [
        'agency_id',
        'rental_work_order_id',
        'rental_job_card_id',
        // §17.5.1 — the crew line a photo explains / §17.10.4 — the completion round a dispute photo belongs to.
        'rental_job_card_line_id',
        'rental_completion_round_id',
        'photo_type',
        'caption',
        'uploaded_via',
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

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(RentalWorkOrder::class, 'rental_work_order_id');
    }

    /** Set when this photo belongs to a job card with no linked work order (RentalJobCardService::storePhoto()). */
    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(RentalJobCard::class, 'rental_job_card_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * D14 - the guards that kill a signed picture URL the moment this photo, its work order or its job card is removed / archived.
     *
     * @return array<int,array{0:string,1:int}>
     */
    public function imageGuards(): array
    {
        $guards = [['rental_work_order_photos', (int) $this->id]];
        if ($this->rental_work_order_id) {
            $guards[] = ['rental_work_orders', (int) $this->rental_work_order_id];
        }
        if ($this->rental_job_card_id) {
            $guards[] = ['rental_job_cards', (int) $this->rental_job_card_id];
        }

        return $guards;
    }

    /**
     * What an office screen puts in an `<img src>`: a signed, expiring URL for a `private:` picture (a fault photo that travelled onto
     * the work order), the stored address untouched for a public one.
     */
    public function displayUrl(string $variant = \App\Services\Images\PrivateImageService::VARIANT_FULL): ?string
    {
        return app(\App\Services\Images\PrivateImageService::class)->displayUrl($this->storage_path, (int) $this->agency_id, $this->imageGuards(), $variant);
    }

    /**
     * The address a picture gets in an EMAIL: a private picture is a signed link that lives for the longest picture-link window the portal
     * allows (a mail is read hours later - the screen window would be dead by then; it still dies the moment the photo / work order / card is
     * archived), a public one its own absolute address.
     */
    public function mailUrl(): ?string
    {
        $stored = trim((string) $this->storage_path);
        if ($stored === '') {
            return null;
        }
        $images = app(\App\Services\Images\PrivateImageService::class);
        if ($images->isPrivate($stored)) {
            return $images->signedUrl($stored, (int) $this->agency_id, $this->imageGuards(), \App\Models\RentalPortalSetting::MAX_PORTAL_PHOTO_LINK_MINUTES);
        }

        return str_starts_with($stored, 'http') ? $stored : url($stored);
    }

    /** The file on disk (PDF, mail attachment, GD) for either kind of stored picture; null when it is not there. */
    public function absolutePath(): ?string
    {
        return app(\App\Services\Images\PrivateImageService::class)->absolutePath($this->storage_path);
    }
}
