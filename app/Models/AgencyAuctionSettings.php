<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Auctions v2 (.ai/specs/auctions.md §7). One row per agency; a NULL column means
 * "use the neutral default below", so an agency that never opens the settings page
 * still gets sensible behaviour and nothing here is HFC-specific.
 *
 * Every setting is read through a `{camelKey}For($agencyId)` resolver — the same
 * convention the onboarding wizard's value loader dispatches on.
 */
class AgencyAuctionSettings extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'agency_auction_settings';

    protected $fillable = [
        'agency_id', 'site_title', 'intro_text', 'contact_email', 'contact_phone', 'reference_prefix',
        'default_increments', 'soft_close_minutes', 'reserve_visibility', 'show_bid_count', 'show_bidder_numbers',
        'registration_closes_hours', 'docs_required_individual', 'docs_required_company', 'terms_text',
        'notify_enquiry_to', 'outbid_emails', 'confirmation_days',
        'hero_image_path', 'accent_color', 'how_it_works', 'footer_text', 'show_past_auctions',
        'buyers_commission_percent', 'buyers_commission_minimum', 'deposit_percent', 'deposit_due_days', 'balance_due_days', 'payment_details',
    ];

    protected $casts = [
        'default_increments' => 'array',
        'docs_required_individual' => 'array',
        'docs_required_company' => 'array',
        'soft_close_minutes' => 'integer',
        'registration_closes_hours' => 'integer',
        'confirmation_days' => 'integer',
        'show_bid_count' => 'boolean',
        'show_bidder_numbers' => 'boolean',
        'outbid_emails' => 'boolean',
        'show_past_auctions' => 'boolean',
        'buyers_commission_percent' => 'float',
        'buyers_commission_minimum' => 'float',
        'deposit_percent' => 'float',
        'deposit_due_days' => 'integer',
        'balance_due_days' => 'integer',
    ];

    public const RESERVE_VISIBILITY = ['hidden' => 'Do not show anything about the reserve', 'met_not_met' => 'Show "Reserve met" / "Reserve not met"'];
    public const NOTIFY_ENQUIRY_TO = ['listing_agent' => 'The listing agent', 'branch_manager' => 'The branch manager', 'both' => 'Both'];
    public const DOCUMENT_KINDS = [
        'id_or_passport' => 'ID or passport copy',
        'proof_of_address' => 'Proof of address',
        'company_resolution' => 'Company resolution / authority to bid',
        'other_fica' => 'Other FICA document',
    ];

    /** Bid ladder: [from_amount, increment] pairs, ascending. Amounts in the auction's currency. */
    public const DEFAULT_INCREMENTS = [[0, 5000], [500000, 10000], [1000000, 25000], [5000000, 50000]];

    /**
     * Unsaved edits shown in the Preview page: agency id => column => value. Set only for the duration of one preview render
     * (see withPreview()); never persisted, and empty on every real request.
     *
     * @var array<int, array<string, mixed>>
     */
    private static array $previewOverrides = [];

    /** Run $render with these unsaved values standing in for the saved settings, then put everything back. */
    public static function withPreview(int $agencyId, array $overrides, callable $render): mixed
    {
        $before = self::$previewOverrides;
        self::$previewOverrides[$agencyId] = $overrides;
        try {
            return $render();
        } finally {
            self::$previewOverrides = $before;
        }
    }

    private static function value(int $agencyId, string $column, mixed $default): mixed
    {
        if (array_key_exists($column, self::$previewOverrides[$agencyId] ?? [])) {
            $v = self::$previewOverrides[$agencyId][$column];

            return $v === null || $v === '' ? $default : $v;
        }

        $row = static::query()->where('agency_id', $agencyId)->first();
        $v = $row?->{$column};

        return $v === null || $v === '' ? $default : $v;
    }

    /** The title the agency typed ('' when it never set one) — what the settings page and wizard edit. */
    public static function siteTitleFor(int $agencyId): string
    {
        return (string) static::value($agencyId, 'site_title', '');
    }

    /** The title buyers see on the public auction page: the agency's own wording, else the agency name. */
    public static function publicTitleFor(int $agencyId): string
    {
        $own = static::siteTitleFor($agencyId);

        return $own !== '' ? $own : (string) (Agency::query()->whereKey($agencyId)->value('name') ?: 'Auctions');
    }

    public static function introTextFor(int $agencyId): string { return (string) static::value($agencyId, 'intro_text', ''); }
    public static function contactEmailFor(int $agencyId): string { return (string) static::value($agencyId, 'contact_email', ''); }
    public static function contactPhoneFor(int $agencyId): string { return (string) static::value($agencyId, 'contact_phone', ''); }
    /** Public URL of the banner picture on the home page, or null. */
    public static function heroImageUrlFor(int $agencyId): ?string
    {
        $path = static::value($agencyId, 'hero_image_path', null);

        return $path ? asset('storage/' . ltrim((string) $path, '/')) : null;
    }
    /** The header colour (#rrggbb) the agency chose, or null to use its own brand colour. */
    public static function accentColorFor(int $agencyId): ?string
    {
        $c = static::value($agencyId, 'accent_color', null);

        return is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : null;
    }
    public static function howItWorksFor(int $agencyId): string { return (string) static::value($agencyId, 'how_it_works', ''); }
    public static function footerTextFor(int $agencyId): string { return (string) static::value($agencyId, 'footer_text', ''); }
    /** Agency default buyer's commission, % of the hammer price. 0 = none (neutral until the agency sets it). */
    public static function buyersCommissionPercentFor(int $agencyId): float { return max(0.0, (float) static::value($agencyId, 'buyers_commission_percent', 0)); }
    public static function buyersCommissionMinimumFor(int $agencyId): float { return max(0.0, (float) static::value($agencyId, 'buyers_commission_minimum', 0)); }
    public static function depositPercentFor(int $agencyId): float { return max(0.0, (float) static::value($agencyId, 'deposit_percent', 10)); }
    public static function depositDueDaysFor(int $agencyId): int { return max(0, (int) static::value($agencyId, 'deposit_due_days', 3)); }
    public static function balanceDueDaysFor(int $agencyId): int { return max(0, (int) static::value($agencyId, 'balance_due_days', 30)); }
    public static function paymentDetailsFor(int $agencyId): string { return (string) static::value($agencyId, 'payment_details', ''); }
    public static function showPastAuctionsFor(int $agencyId): bool { return (bool) static::value($agencyId, 'show_past_auctions', true); }
    public static function referencePrefixFor(int $agencyId): string { return (string) static::value($agencyId, 'reference_prefix', 'AUC'); }
    public static function softCloseMinutesFor(int $agencyId): int { return (int) static::value($agencyId, 'soft_close_minutes', 5); }
    public static function reserveVisibilityFor(int $agencyId): string { return (string) static::value($agencyId, 'reserve_visibility', 'met_not_met'); }
    public static function showBidCountFor(int $agencyId): bool { return (bool) static::value($agencyId, 'show_bid_count', true); }
    public static function showBidderNumbersFor(int $agencyId): bool { return (bool) static::value($agencyId, 'show_bidder_numbers', false); }
    public static function registrationClosesHoursFor(int $agencyId): int { return (int) static::value($agencyId, 'registration_closes_hours', 24); }
    public static function notifyEnquiryToFor(int $agencyId): string { return (string) static::value($agencyId, 'notify_enquiry_to', 'listing_agent'); }
    public static function outbidEmailsFor(int $agencyId): bool { return (bool) static::value($agencyId, 'outbid_emails', true); }
    /** Days a seller has to confirm or decline a sale made below the reserve. */
    public static function confirmationDaysFor(int $agencyId): int { return max(1, (int) static::value($agencyId, 'confirmation_days', 3)); }
    public static function termsTextFor(int $agencyId): string { return (string) static::value($agencyId, 'terms_text', ''); }

    /** @return array<int, array{0: int|float, 1: int|float}> */
    public static function defaultIncrementsFor(int $agencyId): array
    {
        $v = static::value($agencyId, 'default_increments', self::DEFAULT_INCREMENTS);

        return is_array($v) && $v ? $v : self::DEFAULT_INCREMENTS;
    }

    /** @return array<int, string> */
    public static function docsRequiredIndividualFor(int $agencyId): array
    {
        return (array) static::value($agencyId, 'docs_required_individual', ['id_or_passport', 'proof_of_address']);
    }

    /** @return array<int, string> */
    public static function docsRequiredCompanyFor(int $agencyId): array
    {
        return (array) static::value($agencyId, 'docs_required_company', ['id_or_passport', 'proof_of_address', 'company_resolution']);
    }
}
