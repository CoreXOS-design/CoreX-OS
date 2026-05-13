<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds HFC (agency_id=1) with the three default seller-outreach templates
 * shipped with v1 of the module. Idempotent — re-runs do NOT create
 * duplicates (matched on agency_id + channel + name).
 *
 * Every body MUST contain {tracking_link} AND an opt-out clause ("STOP")
 * per spec Section 9 (POPIA) + Section S4.
 */
class SellerOutreachTemplatesSeeder extends Seeder
{
    private const AGENCY_ID = 1;

    public function run(): void
    {
        foreach ($this->templates() as $tpl) {
            $existing = DB::table('seller_outreach_templates')
                ->where('agency_id', self::AGENCY_ID)
                ->where('channel', $tpl['channel'])
                ->where('name', $tpl['name'])
                ->first();

            if ($existing === null) {
                DB::table('seller_outreach_templates')->insert(array_merge($tpl, [
                    'agency_id'  => self::AGENCY_ID,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            } elseif ($existing->deleted_at !== null) {
                DB::table('seller_outreach_templates')
                    ->where('id', $existing->id)
                    ->update([
                        'deleted_at' => null,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function templates(): array
    {
        return [
            [
                'name'                   => 'Initial outreach — sale',
                'channel'                => 'whatsapp',
                'subject'                => null,
                'body'                   => $this->initialWhatsAppBody(),
                'description'            => 'Default opening pitch for a new seller. References current live buyer counts.',
                'is_active'              => true,
                'is_default_for_channel' => true,
            ],
            [
                'name'                   => 'Follow-up after 7 days',
                'channel'                => 'whatsapp',
                'subject'                => null,
                'body'                   => $this->followUpWhatsAppBody(),
                'description'            => '7-day soft follow-up. Live demand may have changed since the initial pitch.',
                'is_active'              => true,
                'is_default_for_channel' => false,
            ],
            [
                'name'                   => 'Initial outreach — sale (email)',
                'channel'                => 'email',
                'subject'                => 'Active buyers looking for properties like yours in {property_town}',
                'body'                   => $this->initialEmailBody(),
                'description'            => 'Default email pitch. Longer-form than WhatsApp.',
                'is_active'              => true,
                'is_default_for_channel' => true,
            ],
        ];
    }

    private function initialWhatsAppBody(): string
    {
        return <<<'TEXT'
Hi {seller_name},

This is {agent_name} from {agency_name}. I noticed your property at {property_address}.

I wanted to reach out because we currently have {buyer_count} active buyers looking for properties in {property_town}, and {matching_buyer_count} of them are specifically searching for {property_beds}-bedroom {property_type}s in your price range.

If you're considering selling — or curious about what your property could fetch in this market — I'd love to share more.

See the live demand for your property here: {tracking_link}

Reply STOP to opt out of further messages.

Best,
{agent_name}
{agent_phone}
TEXT;
    }

    private function followUpWhatsAppBody(): string
    {
        return <<<'TEXT'
Hi {seller_name},

{agent_name} again from {agency_name}. Just following up on my note about {property_address}.

The buyer pool has shifted slightly — we now have {buyer_count} buyers actively looking in {property_town}.

Latest view here: {tracking_link}

Happy to answer any questions, no pressure.

Reply STOP to opt out.

{agent_name}
{agent_phone}
TEXT;
    }

    private function initialEmailBody(): string
    {
        return <<<'TEXT'
Hi {seller_name},

I'm {agent_name} from {agency_name}.

I wanted to reach out about your property at {property_address}. We're tracking {buyer_count} active buyers in {property_town} right now, and {matching_buyer_count} are specifically looking for {property_beds}-bedroom {property_type}s in your price range.

You can see the live demand and what we're seeing in your area here:
{tracking_link}

If you're open to a conversation — whether about selling, market value, or just understanding what's happening in {property_town} — I'd love to chat. No commitment.

To opt out of further messages, reply STOP.

Best,
{agent_name}
{agent_phone}
{agency_name}
TEXT;
    }
}
