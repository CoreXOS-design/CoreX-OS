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
 * the Seller Outreach composer is never empty. Before this, only Home
 * Finders Coastal (agency_id 1) had any seller_outreach_templates rows at
 * all — HfcConsentTemplatesSeeder is deliberately scoped to HFC's own
 * carefully-worded, POPIA-compliant consent copy (spec:
 * seller-outreach-spec.md §11) and was never meant to run for other
 * agencies — so every other agency, including every new one, started with
 * zero templates and nothing to send.
 *
 * Source of truth is HFC's own first WhatsApp template (id 1, "General
 * Marketing — Area Updates" — also HFC's own is_default_for_channel row):
 * cloned verbatim rather than rewritten per agency, since it is already a
 * reviewed, compliant, generically-worded template (its {agency_name} /
 * {agent_name} / {agent_ffc} / etc. merge fields resolve per-recipient
 * agency at send time regardless of which agency owns the row).
 *
 * Mirrors ActivityDefinitionDefaultsService's shape: idempotent
 * ensureDefaults(), safe to call on agency creation AND on every Settings
 * page load (the latter opportunistically backfills any agency that
 * existed before this fix shipped).
 */
class SellerOutreachTemplateDefaultsService
{
    private const SOURCE_AGENCY_ID = 1; // Home Finders Coastal

    /**
     * Clone HFC's first WhatsApp template into $agencyId, UNLESS it already
     * has any WhatsApp template of its own (including inactive ones — an
     * agency that deliberately disabled/replaced its only template keeps
     * that choice and is not re-seeded).
     */
    public function ensureDefaults(int $agencyId, ?int $actorUserId = null): void
    {
        if ($agencyId === self::SOURCE_AGENCY_ID) {
            return; // HFC is the source, not a target.
        }

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

            $source = SellerOutreachTemplate::withoutGlobalScopes()
                ->where('agency_id', self::SOURCE_AGENCY_ID)
                ->where('channel', SellerOutreachTemplate::CHANNEL_WHATSAPP)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->first();

            if (!$source) {
                Log::warning('SellerOutreachTemplateDefaultsService: HFC (source agency) has no WhatsApp template to clone.', [
                    'target_agency_id' => $agencyId,
                ]);
                return null;
            }

            // withoutAgencyStamping: same reason as Branch/User in
            // AgencyController::store() — a creating owner's stale Agency
            // Switcher session would otherwise force this row onto THEIR
            // switched-into agency instead of the target $agencyId.
            return SellerOutreachTemplate::withoutAgencyStamping(fn () => SellerOutreachTemplate::create([
                'agency_id'              => $agencyId,
                'name'                   => $source->name,
                'channel'                => SellerOutreachTemplate::CHANNEL_WHATSAPP,
                'subject'                => null,
                'body'                   => $source->body,
                'description'            => $source->description,
                'is_active'              => true,
                'is_default_for_channel' => true,
                'include_tracking_link'  => $source->include_tracking_link,
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
