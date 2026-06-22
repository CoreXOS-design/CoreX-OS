<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Events\SellerOutreach\TemplateConfigured;
use App\Models\SellerOutreach\SellerOutreachTemplate;
use App\Models\User;
use App\Services\SellerOutreach\SellerOutreachTemplateValidator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * AT-47 — HFC's two approved consent-request WhatsApp templates.
 *
 * Idempotent: updateOrCreate keyed on (agency_id, channel, name). Runs the SAME
 * validation (SellerOutreachTemplateValidator) and audit (TemplateConfigured
 * event) path as the admin UI — NOT a back-door insert. Both templates carry no
 * live-demand tracking link (include_tracking_link = false, AT-46) and end with
 * the mandatory STOP opt-out clause.
 *
 * AT-48 — the footer now carries the company FFC ({agency_ffc}), the sending
 * agent's FFC ({agent_ffc}, optional {?agent_ffc}...{/agent_ffc} segment that
 * collapses when blank) and the branch-then-company tel ({branch_or_company_tel}),
 * replacing the PPRA-reg / public-contact pairing shipped in AT-46/AT-47.
 *
 * AT-49 — the footer now leads with {opt_out_link}, the per-send one-tap
 * self-service marketing opt-out (mandatory on every outreach template; the
 * real URL is substituted by SellerOutreachSenderService at send time). The
 * STOP keyword is retained alongside it.
 *
 * Depends on AT-46 (include_tracking_link column + agency merge fields).
 *
 * Run: php artisan db:seed --class=HfcConsentTemplatesSeeder
 */
final class HfcConsentTemplatesSeeder extends Seeder
{
    private const AGENCY_ID = 1; // Home Finders Coastal

    /**
     * AT-47c — seed BOTH channels. The email variant carries the SAME consent
     * copy (with "via WhatsApp" → "via email") plus a Subject, so toggling the
     * composer to Email pre-fills a populated, branded message. This only adds
     * the template ROWS — the single email SEND path stays the OutreachEmail
     * Mailable (this seeder does not touch delivery).
     */
    private const CHANNELS = [
        SellerOutreachTemplate::CHANNEL_WHATSAPP,
        SellerOutreachTemplate::CHANNEL_EMAIL,
    ];

    public function run(): void
    {
        if (! DB::table('agencies')->where('id', self::AGENCY_ID)->exists()) {
            $this->command?->warn('Agency ' . self::AGENCY_ID . ' (HFC) not found — skipping consent templates seeder.');
            return;
        }

        $validator = new SellerOutreachTemplateValidator();

        // System actor for the audit trail — agency owner/admin if one exists, else null.
        $actorUserId = User::withoutGlobalScopes()
            ->where('agency_id', self::AGENCY_ID)
            ->whereIn('role', ['owner', 'super_admin'])
            ->orderBy('id')
            ->value('id');

        foreach ($this->templates() as $tpl) {
            foreach (self::CHANNELS as $channel) {
                $isEmail = $channel === SellerOutreachTemplate::CHANNEL_EMAIL;

                // Email keeps the SAME copy with the channel word swapped + a Subject.
                $body    = $isEmail ? str_replace('via WhatsApp', 'via email', $tpl['body']) : $tpl['body'];
                $subject = $isEmail ? $tpl['email_subject'] : null;

                // SAME validation the admin UI runs. include_tracking_link = false →
                // {tracking_link} not required; {opt_out_link} + STOP stay mandatory,
                // and email additionally requires a non-empty subject.
                $result = $validator->validate($channel, $subject, $body, includeTrackingLink: false);
                if ($result->fails()) {
                    throw new \RuntimeException(
                        "HFC consent template '{$tpl['name']}' ({$channel}) failed validation: " . json_encode($result->errors)
                    );
                }

                // Keep the admin UI's one-default-per-channel invariant (per channel).
                if ($tpl['is_default_for_channel']) {
                    SellerOutreachTemplate::withoutGlobalScopes()
                        ->where('agency_id', self::AGENCY_ID)
                        ->where('channel', $channel)
                        ->where('is_default_for_channel', true)
                        ->where('name', '!=', $tpl['name'])
                        ->update(['is_default_for_channel' => false]);
                }

                // withoutGlobalScopes() also drops the SoftDeletes scope, so a prior
                // soft-deleted row is matched and reactivated rather than duplicated.
                $template = SellerOutreachTemplate::withoutGlobalScopes()->updateOrCreate(
                    [
                        'agency_id' => self::AGENCY_ID,
                        'channel'   => $channel,
                        'name'      => $tpl['name'],
                    ],
                    [
                        'subject'                => $subject,
                        'body'                   => $body,
                        'description'            => $tpl['description'],
                        'is_active'              => true,
                        'is_default_for_channel' => $tpl['is_default_for_channel'],
                        'include_tracking_link'  => false,
                    ]
                );

                if ($template->trashed()) {
                    $template->restore();
                }

                // SAME audit event the admin create/update path fires.
                event(new TemplateConfigured(
                    template:    $template,
                    action:      $template->wasRecentlyCreated ? TemplateConfigured::ACTION_CREATED : TemplateConfigured::ACTION_UPDATED,
                    actorUserId: $actorUserId,
                    agencyId:    self::AGENCY_ID,
                ));

                $this->command?->info(
                    ($template->wasRecentlyCreated ? 'Created' : 'Updated')
                    . " {$channel} template '{$template->name}' (id {$template->id})."
                );
            }
        }
    }

    /**
     * @return array<int, array{name: string, is_default_for_channel: bool, description: string, email_subject: string, body: string}>
     */
    private function templates(): array
    {
        return [
            [
                'name' => 'General Marketing — Area Updates',
                'is_default_for_channel' => true,
                'description' => 'Consent request — invite the seller to receive area market + buyer-demand updates.',
                'email_subject' => '{property_suburb} market & buyer-demand updates from {agency_name}',
                'body' => <<<'TXT'
Hi {seller_name}, this is {agent_name} from {agency_name} — a registered estate agency on the KZN South Coast.
We track live buyer demand, recent sales and property values for {property_suburb}. With your permission, we'd send you these area updates so you always know what your property could be worth and who's looking to buy near you.
May we contact you with {property_suburb} market and buyer-demand updates via WhatsApp?
- Reply YES — or tap to confirm here: {opt_in_link}
- Reply NO, or just ignore this, and we won't contact you again
Tap to stop marketing messages: {opt_out_link} — or reply STOP.
{agency_name} · FFC {agency_ffc}{?agent_ffc} · Agent FFC {agent_ffc}{/agent_ffc} · {branch_or_company_tel}.
TXT,
            ],
            [
                'name' => 'Buyer Demand Marketing',
                'is_default_for_channel' => false,
                'description' => 'Consent request — specific active buyer for the seller\'s property.',
                'email_subject' => 'A buyer for your {property_suburb} property',
                'body' => <<<'TXT'
Hi {seller_name}, I saw your property in {property_suburb} on the market. I'm {agent_name} from {agency_name} — a registered estate agency.
I have a buyer active in {property_suburb} and your property may suit them. With your permission, I'd like to send you the details and discuss your property with you.
May I contact you about this via WhatsApp?
- Reply YES — or tap to confirm here: {opt_in_link} — and I'll share what my buyer is looking for
- Reply NO, or just ignore this, and I won't contact you again
Tap to stop marketing messages: {opt_out_link} — or reply STOP.
{agency_name} · FFC {agency_ffc}{?agent_ffc} · Agent FFC {agent_ffc}{/agent_ffc} · {branch_or_company_tel}.
TXT,
            ],
        ];
    }
}
