<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * .ai/specs/rental-work-orders.md §3.1/§3.4b/§8 — one row per agency,
 * read-time default pattern matching RentalInspectionSetting: a null
 * column resolves to the DEFAULT_* constant, never written on read.
 */
class RentalWorkOrderSetting extends Model
{
    use BelongsToAgency;

    public const DEFAULT_OVERDUE_REMINDER_DAYS = 3;
    public const DEFAULT_NO_APPROVAL_SPEND_THRESHOLD = 500.00;
    public const DEFAULT_COMPLETION_REQUIRES_PHOTO = false;
    /** AT-442 — whether prices are used at all on internal job cards. Default ON. */
    public const DEFAULT_CAPTURE_PRICES_ON_JOB_CARDS = true;
    /**
     * AT-442 follow-up, conductor's ruling — the worker's PRINTED copy and
     * the owner's quote PDF are not the same audience. §17.4.7 (6 Oct 2026):
     * restated in COST terms — "Crew works on actual costs, not selling." The
     * printed job card shows the crew's cost figures (never selling) and only
     * when an agency switches this on; default OFF. Never gates the owner
     * quote PDF, which shows SELLING only.
     */
    public const DEFAULT_SHOW_COSTS_ON_PRINTED_JOB_CARD = false;

    // ---- .ai/specs/rental-work-orders.md §17.14 — maintenance-flow settings. Every default is neutral for any agency. ----
    /** §17.4.3 rule 6 — selling = cost + this % for part lines with no other rule. 0 = priced at cost until the agency sets its numbers. */
    public const DEFAULT_PARTS_MARKUP_PERCENT = 0.0;
    public const DEFAULT_LABOUR_MARKUP_PERCENT = 0.0;
    /** §17.6.1 term (ii) — agency default; a property may override. 0 = every increase goes to the owner. */
    public const DEFAULT_VARIATION_TOLERANCE_PERCENT = 0.0;
    /** §17.11 / R6 — the estimate wording printed on every owner quote, variation notice and work-order notice. Neutral; agency-editable. */
    public const DEFAULT_QUOTE_ESTIMATE_TERM = 'This quote is an estimate. The full extent of the work can only be confirmed once the affected area has been opened up, and the final invoice may differ. Any extra work will be put to you for approval before it is done, except where your agreed work terms already allow it.';
    /** §17.10 — days a tenant has to answer before silence counts as accepted (Decision 2). */
    public const DEFAULT_COMPLETION_RESPONSE_WINDOW_DAYS = 5;
    public const DEFAULT_TENANT_COMPLETION_CHECK_ENABLED = true;
    public const DEFAULT_NOTIFY_LANDLORD_ON_DISPUTE = true;
    /** §17.7.2 — information email when a variation is auto-approved (Decision 5). */
    public const DEFAULT_NOTIFY_LANDLORD_ON_AUTO_VARIATION = true;
    /** §17.9.1a — the agency's fee on an outside contractor's quote (Decision 1). Value 0 = off. */
    public const EXTERNAL_MARKUP_PERCENT = 'percent';
    public const EXTERNAL_MARKUP_AMOUNT = 'amount';
    public const DEFAULT_EXTERNAL_QUOTE_MARKUP_TYPE = self::EXTERNAL_MARKUP_PERCENT;
    public const DEFAULT_EXTERNAL_QUOTE_MARKUP_VALUE = 0.0;
    /** §17.10.6 — on a dispute, tell the crew straight away instead of waiting for the office's "Send back" (Decision 3). */
    public const DEFAULT_DISPUTE_NOTIFY_CREW_IMMEDIATELY = false;

    // ---- .ai/specs/rental-work-orders.md §17.31 — supplier invoice upload limits. Neutral for any agency. ----
    /** Largest invoice file the office may upload, in megabytes. */
    public const DEFAULT_INVOICE_MAX_FILE_MB = 10;
    public const MAX_INVOICE_MAX_FILE_MB = 50;
    /** Which kinds of file an invoice may be: a named choice, mapped to extensions below. */
    public const INVOICE_TYPES_PDF = 'pdf';
    public const INVOICE_TYPES_PDF_IMAGES = 'pdf_images';
    public const INVOICE_TYPES_PDF_IMAGES_HEIC = 'pdf_images_heic';
    public const DEFAULT_INVOICE_ALLOWED_FILE_TYPES = self::INVOICE_TYPES_PDF_IMAGES;
    public const INVOICE_TYPE_EXTENSIONS = [
        self::INVOICE_TYPES_PDF => ['pdf'],
        self::INVOICE_TYPES_PDF_IMAGES => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
        self::INVOICE_TYPES_PDF_IMAGES_HEIC => ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'],
    ];

    protected $fillable = [
        'agency_id',
        'completion_requires_photo',
        'overdue_reminder_days',
        'no_approval_spend_threshold',
        'capture_prices_on_job_cards',
        'show_costs_on_printed_job_card',
        'default_parts_markup_percent',
        'default_labour_markup_percent',
        'variation_tolerance_percent',
        'quote_estimate_term',
        'completion_response_window_days',
        'tenant_completion_check_enabled',
        'notify_landlord_on_dispute',
        'notify_landlord_on_auto_variation',
        'external_quote_markup_type',
        'external_quote_markup_value',
        'dispute_notify_crew_immediately',
        'invoice_max_file_mb',
        'invoice_allowed_file_types',
    ];

    protected $casts = [
        'completion_requires_photo' => 'boolean',
        'overdue_reminder_days' => 'integer',
        'no_approval_spend_threshold' => 'decimal:2',
        'capture_prices_on_job_cards' => 'boolean',
        'show_costs_on_printed_job_card' => 'boolean',
        'default_parts_markup_percent' => 'decimal:2',
        'default_labour_markup_percent' => 'decimal:2',
        'variation_tolerance_percent' => 'decimal:2',
        'completion_response_window_days' => 'integer',
        'tenant_completion_check_enabled' => 'boolean',
        'notify_landlord_on_dispute' => 'boolean',
        'notify_landlord_on_auto_variation' => 'boolean',
        'external_quote_markup_value' => 'decimal:2',
        'dispute_notify_crew_immediately' => 'boolean',
        'invoice_max_file_mb' => 'integer',
    ];

    /**
     * §3.1/§3.4/§8 — CORRECTED 2026-09-21, Johan: "some repairs will not
     * carry photo evidence - broken gate motor... you cant have a tenant
     * or agent going and fiddling with a gate motor to take a pic of a
     * replaced pc board." Default is now off; an agency that wants photo
     * evidence on every job can still switch this on per-agency.
     */
    public static function completionRequiresPhotoFor(?int $agencyId): bool
    {
        if (! $agencyId) {
            return self::DEFAULT_COMPLETION_REQUIRES_PHOTO;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('completion_requires_photo');

        return $value !== null ? (bool) $value : self::DEFAULT_COMPLETION_REQUIRES_PHOTO;
    }

    public static function overdueReminderDaysFor(?int $agencyId): int
    {
        if (! $agencyId) {
            return self::DEFAULT_OVERDUE_REMINDER_DAYS;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('overdue_reminder_days');

        return $value !== null ? (int) $value : self::DEFAULT_OVERDUE_REMINDER_DAYS;
    }

    /**
     * §3.4b — the agency-level default, read-time, never written until an
     * agency actually sets one.
     */
    public static function spendThresholdFor(?int $agencyId): float
    {
        if (! $agencyId) {
            return self::DEFAULT_NO_APPROVAL_SPEND_THRESHOLD;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('no_approval_spend_threshold');

        return $value !== null ? (float) $value : self::DEFAULT_NO_APPROVAL_SPEND_THRESHOLD;
    }

    /**
     * §3.4b/§3.4c — settled 2026-09-29, superseding the 2026-09-26 lease
     * ruling. Johan, looking at the live lease screen: "per property,
     * populated to the leases screen." The PROPERTY is the single editable
     * source of truth for the override, with the agency default behind it —
     * this is the one resolver anything gating a spend decision should call,
     * never read either column directly. Consumed by
     * RentalWorkOrder::selectQuote() (§3.4c) against the SELECTED quote's
     * amount.
     */
    public static function thresholdFor(Property $property): float
    {
        if ($property->rental_no_approval_spend_threshold !== null) {
            return (float) $property->rental_no_approval_spend_threshold;
        }

        return self::spendThresholdFor($property->agency_id);
    }

    /** AT-442 — with this off, no price columns or totals appear anywhere on a job card. */
    public static function capturePricesOnJobCardsFor(?int $agencyId): bool
    {
        if (! $agencyId) {
            return self::DEFAULT_CAPTURE_PRICES_ON_JOB_CARDS;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('capture_prices_on_job_cards');

        return $value !== null ? (bool) $value : self::DEFAULT_CAPTURE_PRICES_ON_JOB_CARDS;
    }

    /**
     * AT-442 follow-up / §17.4.7 — gates ONLY the worker-facing printed job card (which then shows COST,
     * never selling), never the owner quote PDF.
     */
    public static function showCostsOnPrintedJobCardFor(?int $agencyId): bool
    {
        return self::boolFor($agencyId, 'show_costs_on_printed_job_card', self::DEFAULT_SHOW_COSTS_ON_PRINTED_JOB_CARD);
    }

    // ---- §17.14 accessors — read-time defaults, never written on read. -------------------------------------------

    public static function defaultPartsMarkupPercentFor(?int $agencyId): float
    {
        return self::floatFor($agencyId, 'default_parts_markup_percent', self::DEFAULT_PARTS_MARKUP_PERCENT);
    }

    public static function defaultLabourMarkupPercentFor(?int $agencyId): float
    {
        return self::floatFor($agencyId, 'default_labour_markup_percent', self::DEFAULT_LABOUR_MARKUP_PERCENT);
    }

    /** §17.6.1 term (ii), agency default. For the per-property value use variationToleranceFor(). */
    public static function variationTolerancePercentFor(?int $agencyId): float
    {
        return self::floatFor($agencyId, 'variation_tolerance_percent', self::DEFAULT_VARIATION_TOLERANCE_PERCENT);
    }

    /** §17.6.1 — the property's own tolerance when set, else the agency default. */
    public static function variationToleranceFor(Property $property): float
    {
        if ($property->rental_variation_tolerance_percent !== null) {
            return (float) $property->rental_variation_tolerance_percent;
        }

        return self::variationTolerancePercentFor($property->agency_id);
    }

    /** Did the agency actually set its own spend limit (vs the built-in constant)? Used to cite the term's source. */
    public static function hasAgencySpendThreshold(?int $agencyId): bool
    {
        return self::rawFor($agencyId, 'no_approval_spend_threshold') !== null;
    }

    public static function hasAgencyVariationTolerance(?int $agencyId): bool
    {
        return self::rawFor($agencyId, 'variation_tolerance_percent') !== null;
    }

    /** §17.11 — a null or blank stored term resolves to the built-in wording. */
    public static function quoteEstimateTermFor(?int $agencyId): string
    {
        $value = self::rawFor($agencyId, 'quote_estimate_term');

        return is_string($value) && trim($value) !== '' ? $value : self::DEFAULT_QUOTE_ESTIMATE_TERM;
    }

    public static function completionResponseWindowDaysFor(?int $agencyId): int
    {
        $value = self::rawFor($agencyId, 'completion_response_window_days');

        return $value !== null ? max(1, (int) $value) : self::DEFAULT_COMPLETION_RESPONSE_WINDOW_DAYS;
    }

    public static function tenantCompletionCheckEnabledFor(?int $agencyId): bool
    {
        return self::boolFor($agencyId, 'tenant_completion_check_enabled', self::DEFAULT_TENANT_COMPLETION_CHECK_ENABLED);
    }

    public static function notifyLandlordOnDisputeFor(?int $agencyId): bool
    {
        return self::boolFor($agencyId, 'notify_landlord_on_dispute', self::DEFAULT_NOTIFY_LANDLORD_ON_DISPUTE);
    }

    public static function notifyLandlordOnAutoVariationFor(?int $agencyId): bool
    {
        return self::boolFor($agencyId, 'notify_landlord_on_auto_variation', self::DEFAULT_NOTIFY_LANDLORD_ON_AUTO_VARIATION);
    }

    /** §17.9.1a — `percent` | `amount`. */
    public static function externalQuoteMarkupTypeFor(?int $agencyId): string
    {
        $value = self::rawFor($agencyId, 'external_quote_markup_type');

        return in_array($value, [self::EXTERNAL_MARKUP_PERCENT, self::EXTERNAL_MARKUP_AMOUNT], true)
            ? $value
            : self::DEFAULT_EXTERNAL_QUOTE_MARKUP_TYPE;
    }

    /** §17.9.1a — 0 = no fee on outside contractors' quotes. */
    public static function externalQuoteMarkupValueFor(?int $agencyId): float
    {
        return self::floatFor($agencyId, 'external_quote_markup_value', self::DEFAULT_EXTERNAL_QUOTE_MARKUP_VALUE);
    }

    public static function disputeNotifyCrewImmediatelyFor(?int $agencyId): bool
    {
        return self::boolFor($agencyId, 'dispute_notify_crew_immediately', self::DEFAULT_DISPUTE_NOTIFY_CREW_IMMEDIATELY);
    }

    /** §17.31 — the largest supplier-invoice file, in MB (1–50). */
    public static function invoiceMaxFileMbFor(?int $agencyId): int
    {
        $value = (int) self::rawFor($agencyId, 'invoice_max_file_mb');

        return $value >= 1 ? min($value, self::MAX_INVOICE_MAX_FILE_MB) : self::DEFAULT_INVOICE_MAX_FILE_MB;
    }

    /** §17.31 — one of the INVOICE_TYPES_* keys. */
    public static function invoiceAllowedFileTypesFor(?int $agencyId): string
    {
        $value = self::rawFor($agencyId, 'invoice_allowed_file_types');

        return is_string($value) && isset(self::INVOICE_TYPE_EXTENSIONS[$value]) ? $value : self::DEFAULT_INVOICE_ALLOWED_FILE_TYPES;
    }

    /** §17.31 — the file extensions an invoice may have for this agency. @return array<int, string> */
    public static function invoiceAllowedExtensionsFor(?int $agencyId): array
    {
        return self::INVOICE_TYPE_EXTENSIONS[self::invoiceAllowedFileTypesFor($agencyId)];
    }

    private static function rawFor(?int $agencyId, string $column): mixed
    {
        if (! $agencyId) {
            return null;
        }

        return static::withoutGlobalScopes()->where('agency_id', $agencyId)->value($column);
    }

    private static function boolFor(?int $agencyId, string $column, bool $default): bool
    {
        $value = self::rawFor($agencyId, $column);

        return $value !== null ? (bool) $value : $default;
    }

    private static function floatFor(?int $agencyId, string $column, float $default): float
    {
        $value = self::rawFor($agencyId, $column);

        return $value !== null ? (float) $value : $default;
    }
}
