<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Webinar prep (2026-09-03) — Johan: "Contacts and deals should have real
 * correspondence against them... a CRM with no communication history reads
 * as unused."
 *
 * Every contact's "Communications" tab (corex/contacts/show.blade.php,
 * ContactController@show's $contactThreads) was reading zero rows: the
 * `communications` table only held system escalation emails and the
 * attorney-comms-to-file batch (both linked to deals/properties, never to
 * a Contact). This seeder writes real-looking email + WhatsApp threads,
 * linked via `communication_links` (linkable_type=Contact::class) — the
 * exact relation ContactController@show queries.
 *
 * INERT BY CONSTRUCTION: inserting Communication/CommunicationLink rows
 * directly has no observer/booted() side effect that sends mail or calls
 * WhatsApp (confirmed by reading both models — same proof as
 * DemoAttorneyCommsToFileSeeder). Nothing here can leave the box.
 *
 * Fictional content only. No HFC references, no real names beyond the
 * agency's own demo agents/contacts already seeded elsewhere.
 *
 * Idempotent: identified by source_ref = self::SOURCE_REF. Prior batch is
 * soft-deleted (links first, then their parent communications) then
 * recreated fresh, so dates stay relative to now() on every reseed.
 */
final class DemoContactCommunicationHistorySeeder
{
    private const SOURCE_REF = 'demo-contact-comms-batch';

    /** How many contacts get a communication history. */
    private const TARGET_CONTACT_COUNT = 90;

    private const EMAIL_THREADS = [
        [
            'subject' => 'Your enquiry about {suburb} properties',
            'opening' => "Hi {first_name},\n\nThanks for your enquiry — I've attached a shortlist of {suburb} properties that match what you're after. Let me know which ones you'd like to view.\n\nKind regards,\n{agent_first}",
            'reply'   => "Hi {agent_first},\n\nThanks for this — the second and third ones look interesting. Could we arrange a viewing for this weekend?\n\n{first_name}",
            'closing' => "Hi {first_name},\n\nSaturday at 10:00 works well. I'll send confirmation closer to the time. Looking forward to it.\n\n{agent_first}",
        ],
        [
            'subject' => 'Viewing feedback — {property_address}',
            'opening' => "Hi {first_name},\n\nThanks for viewing {property_address} yesterday. What were your first impressions?\n\n{agent_first}",
            'reply'   => "Hi {agent_first},\n\nWe liked it a lot — the kitchen especially. Only concern is the garden size. Are there any similar options with a bit more outdoor space?\n\n{first_name}",
            'closing' => "Hi {first_name},\n\nI'll pull together two or three alternatives with bigger gardens and send them through this week.\n\n{agent_first}",
        ],
        [
            'subject' => 'Offer submitted — {property_address}',
            'opening' => "Hi {first_name},\n\nI've submitted your offer on {property_address} to the seller's agent. Will update you as soon as I hear back.\n\n{agent_first}",
            'reply'   => "Hi {agent_first},\n\nGreat, thank you. Please keep me posted — happy to discuss if there's a counter.\n\n{first_name}",
            'closing' => "Hi {first_name},\n\nGood news — the seller has accepted, subject to the standard bond clause. I'll send the OTP for signature shortly.\n\n{agent_first}",
        ],
        [
            'subject' => 'Mandate renewal — {property_address}',
            'opening' => "Hi {first_name},\n\nJust a heads-up that your mandate on {property_address} comes up for renewal next month. Happy to chat through how the marketing's gone so far.\n\n{agent_first}",
            'reply'   => "Hi {agent_first},\n\nThanks for the update. We're happy to continue — the viewing numbers have been encouraging.\n\n{first_name}",
            'closing' => null,
        ],
        [
            'subject' => 'FICA documents required',
            'opening' => "Hi {first_name},\n\nBefore we can proceed further we'll need certified copies of your ID and proof of residence for our FICA file. Could you send these through when you have a moment?\n\n{agent_first}",
            'reply'   => "Hi {agent_first},\n\nAttached — let me know if anything else is needed.\n\n{first_name}",
            'closing' => "Hi {first_name},\n\nPerfect, that's everything we need. Thank you.\n\n{agent_first}",
        ],
        [
            'subject' => 'Checking in — still in the market?',
            'opening' => "Hi {first_name},\n\nIt's been a little while since we last spoke — just checking in to see if you're still actively looking, and whether your requirements have changed at all.\n\n{agent_first}",
            'reply'   => "Hi {agent_first},\n\nStill looking, yes — though we've widened our search to include {suburb} as well now.\n\n{first_name}",
            'closing' => null,
        ],
    ];

    private const WHATSAPP_THREADS = [
        [
            ['dir' => 'out', 'text' => "Hi {first_name}, it's {agent_first} from the agency. Is now a good time to chat about {property_address}?"],
            ['dir' => 'in',  'text' => "Hi yes, go ahead"],
            ['dir' => 'out', 'text' => "The sellers have come back — they can do R{price_neg} instead of asking price. Would that work for you?"],
            ['dir' => 'in',  'text' => "Let me speak to my partner and come back to you tonight"],
            ['dir' => 'out', 'text' => "No rush, take your time 👍"],
        ],
        [
            ['dir' => 'in',  'text' => "Hi, are you still available for the viewing tomorrow at {property_address}?"],
            ['dir' => 'out', 'text' => "Yes, 10am still works for me. See you there!"],
            ['dir' => 'in',  'text' => "Perfect, see you then"],
        ],
        [
            ['dir' => 'out', 'text' => "Hi {first_name}, just confirming your viewing is set for Saturday at 11:00 — {property_address}. Reply YES to confirm."],
            ['dir' => 'in',  'text' => "YES"],
        ],
        [
            ['dir' => 'in',  'text' => "Hi, do you have anything new in {suburb} under R{price_max}?"],
            ['dir' => 'out', 'text' => "Hi {first_name}! Yes, a new listing just came in — I'll email you the details now."],
            ['dir' => 'in',  'text' => "Great, thank you 🙏"],
        ],
    ];

    public function run(int $agencyId): array
    {
        $this->archivePriorBatch($agencyId);

        $contacts = DB::table('contacts')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereNotNull('email')
            ->orderByRaw('is_buyer DESC, id ASC')
            ->limit(self::TARGET_CONTACT_COUNT)
            ->get(['id', 'agent_id', 'first_name', 'last_name', 'email', 'phone', 'suburb', 'is_buyer']);

        if ($contacts->isEmpty()) {
            return ['inserted' => 0, 'note' => "Skipped — agency {$agencyId} has no contacts."];
        }

        $agents = DB::table('users')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereIn('role', ['agent', 'branch_manager', 'admin'])
            ->pluck('name', 'id');

        $properties = DB::table('properties')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->inRandomOrder()
            ->limit(200)
            ->get(['id', 'street_number', 'street_name', 'suburb', 'price']);

        if ($agents->isEmpty() || $properties->isEmpty()) {
            return ['inserted' => 0, 'note' => "Skipped — agency {$agencyId} missing agents or properties."];
        }

        $agentIds = $agents->keys()->all();
        $propertyList = $properties->values();
        $emailCommCount = 0;
        $waCommCount = 0;
        $contactsCovered = 0;

        foreach ($contacts as $i => $contact) {
            $agentId = $contact->agent_id && in_array($contact->agent_id, $agentIds, true)
                ? $contact->agent_id
                : $agentIds[$i % count($agentIds)];
            $agentName = $agents[$agentId] ?? 'Your Agent';
            $agentFirst = explode(' ', trim($agentName))[0] ?: 'Your Agent';
            $property = $propertyList[$i % $propertyList->count()];
            $propertyAddress = trim(($property->street_number ?? '') . ' ' . ($property->street_name ?? ''), ' ') ?: 'the property';
            $suburb = $contact->suburb ?: ($property->suburb ?: 'the area');
            $priceBase = (int) ($property->price ?: 2500000);

            $vars = [
                '{first_name}'        => $contact->first_name ?: 'there',
                '{agent_first}'       => $agentFirst,
                '{suburb}'            => $suburb,
                '{property_address}'  => $propertyAddress . ($suburb ? ', ' . $suburb : ''),
                '{price_neg}'         => number_format(round($priceBase * 0.96, -4)),
                '{price_max}'         => number_format(round($priceBase * 1.1, -5)),
            ];

            // Every contact gets one email thread (rotate through the 6 templates).
            $template = self::EMAIL_THREADS[$i % count(self::EMAIL_THREADS)];
            $threadKey = self::SOURCE_REF . '-email-' . $contact->id;
            $baseDate = now()->subDays(random_int(4, 210))->subHours(random_int(0, 20));

            $steps = array_filter([$template['opening'], $template['reply'], $template['closing']]);
            $subject = strtr($template['subject'], $vars);
            $stepDate = $baseDate;
            foreach (array_values($steps) as $stepIdx => $stepBody) {
                $isAgentTurn = $stepIdx % 2 === 0;
                $body = strtr($stepBody, $vars);
                $stepDate = $stepIdx === 0 ? $baseDate : $stepDate->copy()->addHours(random_int(3, 72));

                $communication = Communication::create([
                    'agency_id'               => $agencyId,
                    'channel'                 => Communication::CHANNEL_EMAIL,
                    'direction'               => $isAgentTurn ? Communication::DIRECTION_OUTBOUND : Communication::DIRECTION_INBOUND,
                    'external_id'             => self::SOURCE_REF . '/' . $contact->id . '/email/' . $stepIdx,
                    'thread_key'              => $threadKey,
                    'from_identifier'         => $isAgentTurn ? ($agentName ? Str::slug($agentName, '.') . '@demo.corexos.co.za' : 'agent@demo.corexos.co.za') : $contact->email,
                    'participant_identifiers' => array_values(array_unique([$contact->email, Str::slug($agentName, '.') . '@demo.corexos.co.za'])),
                    'occurred_at'             => $stepDate,
                    'captured_at'             => $stepDate->copy()->addMinutes(random_int(1, 15)),
                    'subject'                 => $subject,
                    'body_text'               => $body,
                    'body_preview'            => Str::limit($body, 160),
                    'has_attachments'         => false,
                    'send_status'             => Communication::SEND_STATUS_SENT,
                    'source_ref'              => self::SOURCE_REF,
                    'owner_user_id'           => $agentId,
                ]);

                CommunicationLink::create([
                    'agency_id'      => $agencyId,
                    'communication_id' => $communication->id,
                    'linkable_type'  => Contact::class,
                    'linkable_id'    => $contact->id,
                    'link_method'    => CommunicationLink::METHOD_DETERMINISTIC,
                    'confidence'     => 1.0,
                ]);

                $emailCommCount++;
            }

            // Roughly two in three contacts also get a WhatsApp thread.
            if ($contact->phone && $i % 3 !== 2) {
                $waTemplate = self::WHATSAPP_THREADS[$i % count(self::WHATSAPP_THREADS)];
                $waThreadKey = self::SOURCE_REF . '-wa-' . $contact->id;
                $waChatId = preg_replace('/\D/', '', $contact->phone) . '@c.us';
                $waDate = now()->subDays(random_int(1, 60))->subHours(random_int(0, 20));

                foreach ($waTemplate as $stepIdx => $step) {
                    $isOutbound = $step['dir'] === 'out';
                    $body = strtr($step['text'], $vars);
                    $waDate = $stepIdx === 0 ? $waDate : $waDate->copy()->addMinutes(random_int(5, 240));

                    $communication = Communication::create([
                        'agency_id'               => $agencyId,
                        'channel'                 => Communication::CHANNEL_WHATSAPP,
                        'direction'               => $isOutbound ? Communication::DIRECTION_OUTBOUND : Communication::DIRECTION_INBOUND,
                        'external_id'             => self::SOURCE_REF . '/' . $contact->id . '/wa/' . $stepIdx,
                        'thread_key'              => $waThreadKey,
                        'wa_chat_id'              => $waChatId,
                        'from_identifier'         => $isOutbound ? 'agency' : $waChatId,
                        'participant_identifiers' => [$waChatId],
                        'occurred_at'             => $waDate,
                        'captured_at'             => $waDate->copy()->addMinutes(1),
                        'body_text'               => $body,
                        'body_preview'            => Str::limit($body, 160),
                        'has_attachments'         => false,
                        'send_status'             => Communication::SEND_STATUS_SENT,
                        'source_ref'              => self::SOURCE_REF,
                        'owner_user_id'           => $agentId,
                    ]);

                    CommunicationLink::create([
                        'agency_id'      => $agencyId,
                        'communication_id' => $communication->id,
                        'linkable_type'  => Contact::class,
                        'linkable_id'    => $contact->id,
                        'link_method'    => CommunicationLink::METHOD_DETERMINISTIC,
                        'confidence'     => 1.0,
                    ]);

                    $waCommCount++;
                }
            }

            $contactsCovered++;
        }

        return [
            'contacts_covered' => $contactsCovered,
            'email_messages'   => $emailCommCount,
            'whatsapp_messages' => $waCommCount,
        ];
    }

    private function archivePriorBatch(int $agencyId): void
    {
        $commIds = DB::table('communications')
            ->where('agency_id', $agencyId)
            ->where('source_ref', self::SOURCE_REF)
            ->whereNull('deleted_at')
            ->pluck('id');

        if ($commIds->isEmpty()) {
            return;
        }

        DB::table('communication_links')->whereIn('communication_id', $commIds)->whereNull('deleted_at')->update(['deleted_at' => now()]);
        DB::table('communications')->whereIn('id', $commIds)->update(['deleted_at' => now()]);
    }
}
