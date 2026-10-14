<?php

declare(strict_types=1);

namespace App\Services\SellerOutreach;

use App\Events\SellerOutreach\TemplateConfigured;
use App\Models\SellerOutreach\SellerOutreachTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Seeds a starter WhatsApp outreach template for an agency that has none, so
 * the Seller Outreach composer is never empty.
 *
 * The starter is a NEUTRAL text of the platform's own (STARTER_BODY below) - it
 * names no agency, region or FFC number of its own: everything agency-specific is
 * a merge field ({agency_name}, {property_town}, {property_suburb}, {agency_ffc},
 * {agent_ffc}, {branch_or_company_tel}) resolved per send. It used to be a verbatim
 * CLONE of Home Finders Coastal's own first template, so every new agency's first
 * message told sellers it was "a registered estate agency on the KZN South Coast"
 * (Cape walk, 14 Oct 2026). Agencies that already hold a template are never touched;
 * rows cloned earlier from HFC are neutralised by migration
 * 2026_10_14_150000_neutralise_cloned_hfc_outreach_template (exact clones only).
 *
 * Mirrors ActivityDefinitionDefaultsService's shape: idempotent
 * ensureDefaults(), safe to call on agency creation AND on every Settings
 * page load (the latter opportunistically backfills any agency that
 * existed before this fix shipped).
 */
class SellerOutreachTemplateDefaultsService
{
    public const STARTER_NAME = 'General Marketing — Area Updates';

    public const STARTER_DESCRIPTION = 'Consent request — invite the seller to receive area market + buyer-demand updates.';

    /** Merge fields only - no agency, region or phone number of any one agency. */
    public const STARTER_BODY = <<<'TXT'
Hi {seller_name}, this is {agent_name} from {agency_name}, a registered estate agency working in {property_town}.
We track live buyer demand, recent sales and property values for {property_suburb}. With your permission, we'd send you these area updates so you always know what your property could be worth and who's looking to buy near you.
May we contact you with {property_suburb} market and buyer-demand updates by WhatsApp, SMS or email?
- Reply OPT IN and we'll be in touch
- Reply OPT OUT and we won't contact you again
Manage your preferences or opt out anytime: {opt_out_link}.
{agency_name} · FFC {agency_ffc}{?agent_ffc} · Agent FFC {agent_ffc}{/agent_ffc} · {branch_or_company_tel}.
TXT;

    /**
     * Create the neutral starter WhatsApp template in $agencyId, UNLESS it already
     * has any WhatsApp template of its own (including inactive ones - an
     * agency that deliberately disabled/replaced its only template keeps
     * that choice and is not re-seeded).
     */
    public function ensureDefaults(int $agencyId, ?int $actorUserId = null): void
    {
        $template = DB::transaction(function () use ($agencyId) {
            // Lock the agency row for the life of the transaction so two
            // concurrent callers for the SAME agency (two settings-page tabs,
            // a retried request) serialize instead of both passing the
            // existence check and creating duplicate default templates.
            DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->first();

            // withoutGlobalScopes() also lifts the SoftDeletingScope — a
            // soft-deleted template must still count as "has one", or an
            // agency that archives its only template gets silently
            // re-seeded here while every real read path (e.g. the composer)
            // correctly treats it as having none, which is the opposite of
            // this method's job. deleted_at IS NULL keeps that consistent:
            // an ARCHIVED template still means "this agency made a choice",
            // not "this agency needs another one this method invents".
            $hasAny = SellerOutreachTemplate::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->where('channel', SellerOutreachTemplate::CHANNEL_WHATSAPP)
                ->whereNull('deleted_at')
                ->exists();

            if ($hasAny) {
                return null;
            }

            // withoutAgencyStamping: same reason as Branch/User in
            // AgencyController::store() — a creating owner's stale Agency
            // Switcher session would otherwise force this row onto THEIR
            // switched-into agency instead of the target $agencyId.
            return SellerOutreachTemplate::withoutAgencyStamping(fn () => SellerOutreachTemplate::create([
                'agency_id'              => $agencyId,
                'name'                   => self::STARTER_NAME,
                'channel'                => SellerOutreachTemplate::CHANNEL_WHATSAPP,
                'subject'                => null,
                'body'                   => self::STARTER_BODY,
                'description'            => self::STARTER_DESCRIPTION,
                'is_active'              => true,
                'is_default_for_channel' => true,
                'include_tracking_link'  => false,
            ]));
        });

        if (!$template) {
            return;
        }

        $actorUserId ??= User::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereIn('role', ['admin', 'super_admin'])
            ->orderBy('id')
            ->value('id');

        // Same audit path a human create in the admin UI fires — non-negotiable
        // #9 (domain events), matching HfcConsentTemplatesSeeder's convention.
        event(new TemplateConfigured(
            template:    $template,
            action:      TemplateConfigured::ACTION_CREATED,
            actorUserId: $actorUserId,
            agencyId:    $agencyId,
        ));
    }
}
