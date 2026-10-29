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

    private static function value(int $agencyId, string $column, mixed $default): mixed
    {
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
