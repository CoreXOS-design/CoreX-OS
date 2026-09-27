<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * AT-432 — .ai/specs/auctions.md §4 / §5.7. One row per agency, the
 * switchboard behind Andre's three shape-defining decisions (spec §0):
 * who runs the auction, where bidding happens, how the agency is paid —
 * every one of them an agency setting, never a hardcoded model.
 *
 * Copies RentalApplicationQualifyingSetting's pattern verbatim, per the
 * spec's own instruction not to invent a second accessor style: forAgency()
 * NEVER creates a row on read, only returns the documented default when the
 * agency has never opened Settings → Auctions. A row is only ever written
 * when the agency explicitly saves.
 */
class AgencyAuctionSettings extends Model
{
    use BelongsToAgency;

    // ── §4.1 Who runs the auction ───────────────────────────────────────
    public const AUCTIONEER_MODES = ['internal', 'external', 'both'];
    public const DEFAULT_AUCTIONEER_MODE = 'both';
    public const DEFAULT_EXTERNAL_AUCTIONEER_REQUIRED_FIELDS = ['name', 'company', 'contact', 'licence_no'];

    // ── §4.2 Where bidding happens ───────────────────────────────────────
    public const BIDDING_MODES = ['in_room', 'online', 'hybrid'];
    public const DEFAULT_BIDDING_MODES_ENABLED = ['in_room'];
    public const DEFAULT_DEFAULT_BIDDING_MODE = 'in_room';
    public const DEFAULT_ONLINE_AUTO_EXTEND_ENABLED = true;
    public const DEFAULT_ONLINE_AUTO_EXTEND_MINUTES = 5;
    public const DEFAULT_ONLINE_BID_INCREMENT_MODE = 'banded';
    /** §11.3 — the default increment band table. Agency-editable in full. */
    public const DEFAULT_ONLINE_BID_INCREMENT_BANDS = [
        ['from' => 0,       'to' => 500000,   'increment' => 10000],
        ['from' => 500001,  'to' => 1000000,  'increment' => 25000],
        ['from' => 1000001, 'to' => 2500000,  'increment' => 50000],
        ['from' => 2500001, 'to' => 5000000,  'increment' => 100000],
        ['from' => 5000001, 'to' => null,     'increment' => 250000],
    ];
    public const DEFAULT_PROXY_BIDDING_ENABLED = true;
    public const DEFAULT_ABSENTEE_BIDS_ENABLED = true;
    public const DEFAULT_PHONE_BIDDING_ENABLED = true;
    public const DEFAULT_BID_RETRACTION_ALLOWED = false;

    // ── §4.3 How the agency is paid ─────────────────────────────────────
    public const FEE_MODELS = ['buyers_premium', 'sellers_commission', 'both'];
    public const DEFAULT_FEE_MODEL = 'buyers_premium';
    public const DEFAULT_BUYERS_PREMIUM_PERCENT = 10.00;
    public const DEFAULT_BUYERS_PREMIUM_VAT_INCLUSIVE = false;
    public const DEFAULT_BUYERS_PREMIUM_MINIMUM = null;
    public const DEFAULT_SELLERS_COMMISSION_PERCENT = null;
    public const VAT_RATE_SOURCES = ['system', 'override'];
    public const DEFAULT_VAT_RATE_SOURCE = 'system';
    public const PREMIUM_PAYABLE_ON_OPTIONS = ['fall_of_hammer', 'confirmation', 'registration'];
    public const DEFAULT_PREMIUM_PAYABLE_ON = 'fall_of_hammer';

    // ── §4.4 Bidder registration requirements ───────────────────────────
    public const DEFAULT_REGISTRATION_REQUIRED = true;
    public const DEFAULT_REGISTRATION_OPENS_DAYS_BEFORE = 14;
    public const REGISTRATION_CLOSES_OPTIONS = ['at_start', 'hours_before'];
    public const DEFAULT_REGISTRATION_CLOSES = 'at_start';
    public const DEFAULT_REGISTRATION_CLOSES_HOURS_BEFORE = 24;
    public const DEFAULT_REQUIRE_FICA_BEFORE_PADDLE = true;
    public const DEFAULT_FICA_DOCUMENT_CHECKLIST = ['ID', 'Proof of Address', 'Proof of Funds'];
    public const DEFAULT_REGISTRATION_DEPOSIT_REQUIRED = true;
    public const DEFAULT_REGISTRATION_DEPOSIT_AMOUNT = 50000.00;
    public const DEFAULT_REGISTRATION_DEPOSIT_REFUND_DAYS = 7;
    public const DEFAULT_REQUIRE_SIGNED_RULES_BEFORE_PADDLE = true;
    public const PADDLE_NUMBER_MODES = ['sequential', 'manual'];
    public const DEFAULT_PADDLE_NUMBER_MODE = 'sequential';
    public const DEFAULT_ENTITY_BIDDERS_ALLOWED = true;

    // ── §4.5 Reserve, guide and confirmation ─────────────────────────────
    public const RESERVE_VISIBILITY_OPTIONS = ['private', 'disclosed_on_the_day', 'published'];
    public const DEFAULT_RESERVE_VISIBILITY = 'private';
    public const DEFAULT_GUIDE_PRICE_ENABLED = true;
    public const DEFAULT_CONFIRMATION_PERIOD_ENABLED = true;
    public const DEFAULT_CONFIRMATION_PERIOD_DAYS = 7;
    /**
     * §18 — this shipped line is the system's default disclosure text ONLY.
     * "The legal wording of every document produced here must be confirmed
     * by the agency's attorney or compliance officer before first live use
     * — this spec specifies the system's behaviour, not legal advice."
     */
    public const DEFAULT_VENDOR_BIDDING_DISCLOSURE = 'Vendor bidding may take place on this property up to, but not exceeding, the reserve price, where disclosed and permitted by the auction rules and applicable law.';

    // ── §4.6 Deposit and settlement on the day ───────────────────────────
    public const PURCHASE_DEPOSIT_MODES = ['percent', 'fixed', 'none'];
    public const DEFAULT_PURCHASE_DEPOSIT_MODE = 'percent';
    public const DEFAULT_PURCHASE_DEPOSIT_PERCENT = 10.00;
    public const DEFAULT_PURCHASE_DEPOSIT_FIXED_AMOUNT = null;
    public const DEFAULT_PURCHASE_DEPOSIT_DUE_DAYS = 0;
    public const DEFAULT_BALANCE_DUE_DAYS = 30;
    public const DEFAULT_DEFAULT_ATTORNEY_PROVIDER_ID = null;

    protected $fillable = [
        'agency_id',
        'auctioneer_mode', 'external_auctioneer_required_fields',
        'bidding_modes_enabled', 'default_bidding_mode',
        'online_auto_extend_enabled', 'online_auto_extend_minutes',
        'online_bid_increment_mode', 'online_bid_increment_bands',
        'proxy_bidding_enabled', 'absentee_bids_enabled', 'phone_bidding_enabled', 'bid_retraction_allowed',
        'fee_model', 'buyers_premium_percent', 'buyers_premium_vat_inclusive', 'buyers_premium_minimum',
        'sellers_commission_percent', 'vat_rate_source', 'premium_payable_on',
        'registration_required', 'registration_opens_days_before', 'registration_closes',
        'registration_closes_hours_before', 'require_fica_before_paddle', 'fica_document_checklist',
        'registration_deposit_required', 'registration_deposit_amount', 'registration_deposit_refund_days',
        'require_signed_rules_before_paddle', 'paddle_number_mode', 'entity_bidders_allowed',
        'reserve_visibility', 'guide_price_enabled', 'confirmation_period_enabled', 'confirmation_period_days',
        'vendor_bidding_disclosure',
        'purchase_deposit_mode', 'purchase_deposit_percent', 'purchase_deposit_fixed_amount',
        'purchase_deposit_due_days', 'balance_due_days', 'default_attorney_provider_id',
    ];

    protected $casts = [
        'external_auctioneer_required_fields' => 'array',
        'bidding_modes_enabled' => 'array',
        'online_auto_extend_enabled' => 'boolean',
        'online_auto_extend_minutes' => 'integer',
        'online_bid_increment_bands' => 'array',
        'proxy_bidding_enabled' => 'boolean',
        'absentee_bids_enabled' => 'boolean',
        'phone_bidding_enabled' => 'boolean',
        'bid_retraction_allowed' => 'boolean',
        'buyers_premium_percent' => 'decimal:2',
        'buyers_premium_vat_inclusive' => 'boolean',
        'buyers_premium_minimum' => 'decimal:2',
        'sellers_commission_percent' => 'decimal:2',
        'registration_required' => 'boolean',
        'registration_opens_days_before' => 'integer',
        'registration_closes_hours_before' => 'integer',
        'require_fica_before_paddle' => 'boolean',
        'fica_document_checklist' => 'array',
        'registration_deposit_required' => 'boolean',
        'registration_deposit_amount' => 'decimal:2',
        'registration_deposit_refund_days' => 'integer',
        'require_signed_rules_before_paddle' => 'boolean',
        'entity_bidders_allowed' => 'boolean',
        'guide_price_enabled' => 'boolean',
        'confirmation_period_enabled' => 'boolean',
        'confirmation_period_days' => 'integer',
        'purchase_deposit_percent' => 'decimal:2',
        'purchase_deposit_fixed_amount' => 'decimal:2',
        'purchase_deposit_due_days' => 'integer',
        'balance_due_days' => 'integer',
    ];

    private static function rowFor(?int $agencyId): ?self
    {
        if ($agencyId === null || $agencyId <= 0) {
            return null;
        }

        return static::where('agency_id', $agencyId)->first();
    }

    public static function auctioneerModeFor(?int $agencyId): string
    {
        $row = self::rowFor($agencyId);
        return $row && $row->auctioneer_mode !== null ? $row->auctioneer_mode : self::DEFAULT_AUCTIONEER_MODE;
    }

    public static function externalAuctioneerRequiredFieldsFor(?int $agencyId): array
    {
        $row = self::rowFor($agencyId);
        return $row && $row->external_auctioneer_required_fields !== null
            ? $row->external_auctioneer_required_fields
            : self::DEFAULT_EXTERNAL_AUCTIONEER_REQUIRED_FIELDS;
    }

    public static function biddingModesEnabledFor(?int $agencyId): array
    {
        $row = self::rowFor($agencyId);
        return $row && $row->bidding_modes_enabled !== null ? $row->bidding_modes_enabled : self::DEFAULT_BIDDING_MODES_ENABLED;
    }

    public static function defaultBiddingModeFor(?int $agencyId): string
    {
        $row = self::rowFor($agencyId);
        return $row && $row->default_bidding_mode !== null ? $row->default_bidding_mode : self::DEFAULT_DEFAULT_BIDDING_MODE;
    }

    public static function onlineAutoExtendEnabledFor(?int $agencyId): bool
    {
        $row = self::rowFor($agencyId);
        return $row && $row->online_auto_extend_enabled !== null ? (bool) $row->online_auto_extend_enabled : self::DEFAULT_ONLINE_AUTO_EXTEND_ENABLED;
    }

    public static function onlineAutoExtendMinutesFor(?int $agencyId): int
    {
        $row = self::rowFor($agencyId);
        return $row && $row->online_auto_extend_minutes !== null ? (int) $row->online_auto_extend_minutes : self::DEFAULT_ONLINE_AUTO_EXTEND_MINUTES;
    }

    public static function onlineBidIncrementModeFor(?int $agencyId): string
    {
        $row = self::rowFor($agencyId);
        return $row && $row->online_bid_increment_mode !== null ? $row->online_bid_increment_mode : self::DEFAULT_ONLINE_BID_INCREMENT_MODE;
    }

    public static function onlineBidIncrementBandsFor(?int $agencyId): array
    {
        $row = self::rowFor($agencyId);
        return $row && $row->online_bid_increment_bands !== null ? $row->online_bid_increment_bands : self::DEFAULT_ONLINE_BID_INCREMENT_BANDS;
    }

    public static function proxyBiddingEnabledFor(?int $agencyId): bool
    {
        $row = self::rowFor($agencyId);
        return $row && $row->proxy_bidding_enabled !== null ? (bool) $row->proxy_bidding_enabled : self::DEFAULT_PROXY_BIDDING_ENABLED;
    }

    public static function absenteeBidsEnabledFor(?int $agencyId): bool
    {
        $row = self::rowFor($agencyId);
        return $row && $row->absentee_bids_enabled !== null ? (bool) $row->absentee_bids_enabled : self::DEFAULT_ABSENTEE_BIDS_ENABLED;
    }

    public static function phoneBiddingEnabledFor(?int $agencyId): bool
    {
        $row = self::rowFor($agencyId);
        return $row && $row->phone_bidding_enabled !== null ? (bool) $row->phone_bidding_enabled : self::DEFAULT_PHONE_BIDDING_ENABLED;
    }

    public static function bidRetractionAllowedFor(?int $agencyId): bool
    {
        $row = self::rowFor($agencyId);
        return $row && $row->bid_retraction_allowed !== null ? (bool) $row->bid_retraction_allowed : self::DEFAULT_BID_RETRACTION_ALLOWED;
    }

    public static function feeModelFor(?int $agencyId): string
    {
        $row = self::rowFor($agencyId);
        return $row && $row->fee_model !== null ? $row->fee_model : self::DEFAULT_FEE_MODEL;
    }

    public static function buyersPremiumPercentFor(?int $agencyId): float
    {
        $row = self::rowFor($agencyId);
        return $row && $row->buyers_premium_percent !== null ? (float) $row->buyers_premium_percent : self::DEFAULT_BUYERS_PREMIUM_PERCENT;
    }

    public static function buyersPremiumVatInclusiveFor(?int $agencyId): bool
    {
        $row = self::rowFor($agencyId);
        return $row && $row->buyers_premium_vat_inclusive !== null ? (bool) $row->buyers_premium_vat_inclusive : self::DEFAULT_BUYERS_PREMIUM_VAT_INCLUSIVE;
    }

    public static function buyersPremiumMinimumFor(?int $agencyId): ?float
    {
        $row = self::rowFor($agencyId);
        return $row && $row->buyers_premium_minimum !== null ? (float) $row->buyers_premium_minimum : self::DEFAULT_BUYERS_PREMIUM_MINIMUM;
    }

    public static function sellersCommissionPercentFor(?int $agencyId): ?float
    {
        $row = self::rowFor($agencyId);
        return $row && $row->sellers_commission_percent !== null ? (float) $row->sellers_commission_percent : self::DEFAULT_SELLERS_COMMISSION_PERCENT;
    }

    public static function vatRateSourceFor(?int $agencyId): string
    {
        $row = self::rowFor($agencyId);
        return $row && $row->vat_rate_source !== null ? $row->vat_rate_source : self::DEFAULT_VAT_RATE_SOURCE;
    }

    public static function premiumPayableOnFor(?int $agencyId): string
    {
        $row = self::rowFor($agencyId);
        return $row && $row->premium_payable_on !== null ? $row->premium_payable_on : self::DEFAULT_PREMIUM_PAYABLE_ON;
    }

    public static function registrationRequiredFor(?int $agencyId): bool
    {
        $row = self::rowFor($agencyId);
        return $row && $row->registration_required !== null ? (bool) $row->registration_required : self::DEFAULT_REGISTRATION_REQUIRED;
    }

    public static function registrationOpensDaysBeforeFor(?int $agencyId): int
    {
        $row = self::rowFor($agencyId);
        return $row && $row->registration_opens_days_before !== null ? (int) $row->registration_opens_days_before : self::DEFAULT_REGISTRATION_OPENS_DAYS_BEFORE;
    }

    public static function registrationClosesFor(?int $agencyId): string
    {
        $row = self::rowFor($agencyId);
        return $row && $row->registration_closes !== null ? $row->registration_closes : self::DEFAULT_REGISTRATION_CLOSES;
    }

    public static function registrationClosesHoursBeforeFor(?int $agencyId): int
    {
        $row = self::rowFor($agencyId);
        return $row && $row->registration_closes_hours_before !== null ? (int) $row->registration_closes_hours_before : self::DEFAULT_REGISTRATION_CLOSES_HOURS_BEFORE;
    }

    public static function requireFicaBeforePaddleFor(?int $agencyId): bool
    {
        $row = self::rowFor($agencyId);
        return $row && $row->require_fica_before_paddle !== null ? (bool) $row->require_fica_before_paddle : self::DEFAULT_REQUIRE_FICA_BEFORE_PADDLE;
    }

    public static function ficaDocumentChecklistFor(?int $agencyId): array
    {
        $row = self::rowFor($agencyId);
        return $row && $row->fica_document_checklist !== null ? $row->fica_document_checklist : self::DEFAULT_FICA_DOCUMENT_CHECKLIST;
    }

    public static function registrationDepositRequiredFor(?int $agencyId): bool
    {
        $row = self::rowFor($agencyId);
        return $row && $row->registration_deposit_required !== null ? (bool) $row->registration_deposit_required : self::DEFAULT_REGISTRATION_DEPOSIT_REQUIRED;
    }

    public static function registrationDepositAmountFor(?int $agencyId): float
    {
        $row = self::rowFor($agencyId);
        return $row && $row->registration_deposit_amount !== null ? (float) $row->registration_deposit_amount : self::DEFAULT_REGISTRATION_DEPOSIT_AMOUNT;
    }

    public static function registrationDepositRefundDaysFor(?int $agencyId): int
    {
        $row = self::rowFor($agencyId);
        return $row && $row->registration_deposit_refund_days !== null ? (int) $row->registration_deposit_refund_days : self::DEFAULT_REGISTRATION_DEPOSIT_REFUND_DAYS;
    }

    public static function requireSignedRulesBeforePaddleFor(?int $agencyId): bool
    {
        $row = self::rowFor($agencyId);
        return $row && $row->require_signed_rules_before_paddle !== null ? (bool) $row->require_signed_rules_before_paddle : self::DEFAULT_REQUIRE_SIGNED_RULES_BEFORE_PADDLE;
    }

    public static function paddleNumberModeFor(?int $agencyId): string
    {
        $row = self::rowFor($agencyId);
        return $row && $row->paddle_number_mode !== null ? $row->paddle_number_mode : self::DEFAULT_PADDLE_NUMBER_MODE;
    }

    public static function entityBiddersAllowedFor(?int $agencyId): bool
    {
        $row = self::rowFor($agencyId);
        return $row && $row->entity_bidders_allowed !== null ? (bool) $row->entity_bidders_allowed : self::DEFAULT_ENTITY_BIDDERS_ALLOWED;
    }

    public static function reserveVisibilityFor(?int $agencyId): string
    {
        $row = self::rowFor($agencyId);
        return $row && $row->reserve_visibility !== null ? $row->reserve_visibility : self::DEFAULT_RESERVE_VISIBILITY;
    }

    public static function guidePriceEnabledFor(?int $agencyId): bool
    {
        $row = self::rowFor($agencyId);
        return $row && $row->guide_price_enabled !== null ? (bool) $row->guide_price_enabled : self::DEFAULT_GUIDE_PRICE_ENABLED;
    }

    public static function confirmationPeriodEnabledFor(?int $agencyId): bool
    {
        $row = self::rowFor($agencyId);
        return $row && $row->confirmation_period_enabled !== null ? (bool) $row->confirmation_period_enabled : self::DEFAULT_CONFIRMATION_PERIOD_ENABLED;
    }

    public static function confirmationPeriodDaysFor(?int $agencyId): int
    {
        $row = self::rowFor($agencyId);
        return $row && $row->confirmation_period_days !== null ? (int) $row->confirmation_period_days : self::DEFAULT_CONFIRMATION_PERIOD_DAYS;
    }

    public static function vendorBiddingDisclosureFor(?int $agencyId): string
    {
        $row = self::rowFor($agencyId);
        return $row && $row->vendor_bidding_disclosure !== null ? $row->vendor_bidding_disclosure : self::DEFAULT_VENDOR_BIDDING_DISCLOSURE;
    }

    public static function purchaseDepositModeFor(?int $agencyId): string
    {
        $row = self::rowFor($agencyId);
        return $row && $row->purchase_deposit_mode !== null ? $row->purchase_deposit_mode : self::DEFAULT_PURCHASE_DEPOSIT_MODE;
    }

    public static function purchaseDepositPercentFor(?int $agencyId): float
    {
        $row = self::rowFor($agencyId);
        return $row && $row->purchase_deposit_percent !== null ? (float) $row->purchase_deposit_percent : self::DEFAULT_PURCHASE_DEPOSIT_PERCENT;
    }

    public static function purchaseDepositFixedAmountFor(?int $agencyId): ?float
    {
        $row = self::rowFor($agencyId);
        return $row && $row->purchase_deposit_fixed_amount !== null ? (float) $row->purchase_deposit_fixed_amount : self::DEFAULT_PURCHASE_DEPOSIT_FIXED_AMOUNT;
    }

    public static function purchaseDepositDueDaysFor(?int $agencyId): int
    {
        $row = self::rowFor($agencyId);
        return $row && $row->purchase_deposit_due_days !== null ? (int) $row->purchase_deposit_due_days : self::DEFAULT_PURCHASE_DEPOSIT_DUE_DAYS;
    }

    public static function balanceDueDaysFor(?int $agencyId): int
    {
        $row = self::rowFor($agencyId);
        return $row && $row->balance_due_days !== null ? (int) $row->balance_due_days : self::DEFAULT_BALANCE_DUE_DAYS;
    }

    public static function defaultAttorneyProviderIdFor(?int $agencyId): ?int
    {
        $row = self::rowFor($agencyId);
        return $row && $row->default_attorney_provider_id !== null ? (int) $row->default_attorney_provider_id : self::DEFAULT_DEFAULT_ATTORNEY_PROVIDER_ID;
    }
}
