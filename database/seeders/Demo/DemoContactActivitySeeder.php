<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\DB;

/**
 * Expanded mandate (2026-09-02, "does this read like a live system?") —
 * Communications and Notes were BOTH completely empty: 0 of 290 contacts
 * had a linked communication, a note, or a testimonial. The History tab
 * looked non-empty (872 audit-log rows) but was almost entirely mechanical
 * create/update noise, not a narrative an agent actually left behind.
 *
 * Seeds a realistic MIX, not uniform coverage — matching the brief
 * explicitly ("some complete, some partial, some brand new"):
 *   - ~48% of contacts get 1-4 email threads referencing their actual
 *     linked property where one exists (a real cross-feature touch, not a
 *     generic template)
 *   - ~35% get 1-3 quick notes (Contacted/Viewing booked/etc.)
 *   - a small handful of buyer contacts who closed get a short testimonial
 * The two coverage sets overlap partially, not identically, and roughly
 * 30% of contacts get neither — genuinely untouched, reading as brand-new
 * leads rather than a uniformly "complete" roster.
 *
 * IDEMPOTENT BY CONSTRUCTION — coverage is capped by a TOTAL target per
 * signal (contacts-with-any-row), computed fresh each run, so a re-run
 * tops up toward the same target rather than adding more every time.
 */
class DemoContactActivitySeeder
{
    private const COMMS_COVERAGE_TARGET = 142;
    private const NOTES_COVERAGE_TARGET = 100;
    private const TESTIMONIAL_COVERAGE_TARGET = 18;

    private const SUBJECTS = [
        'Viewing this weekend?',
        'Following up on our call',
        'Re: your enquiry',
        'Documents for the offer',
        'Quick question about the property',
        'Checking in',
    ];

    private const NOTE_BODIES = [
        'Contacted' => 'Called to introduce myself and confirm requirements. Happy to hear from us again.',
        'Viewing booked' => 'Booked a viewing — confirmed time and address, sent directions.',
        'Viewing done' => 'Walked the property together. Liked the layout, wants to think about the price.',
        'Offer discussed' => 'Talked through offer options and what a realistic number would look like.',
        'Not interested' => 'Not the right fit for them right now — keeping in touch for future stock.',
        'Follow up later' => 'Nothing urgent — asked to be contacted again in a few weeks.',
    ];

    /** @return array{comms_contacts:int, comms_rows:int, notes_contacts:int, notes_rows:int, testimonials:int, note:string} */
    public function run(int $agencyId = 1): array
    {
        $commsResult = $this->seedCommunications($agencyId);
        $notesResult = $this->seedNotes($agencyId);
        $testimonialsAdded = $this->seedTestimonials($agencyId);

        $note = "Contact activity: comms +{$commsResult['contacts']} contacts (+{$commsResult['rows']} messages), "
            . "notes +{$notesResult['contacts']} contacts (+{$notesResult['rows']} notes), +{$testimonialsAdded} testimonials.";

        return [
            'comms_contacts' => $commsResult['contacts'], 'comms_rows' => $commsResult['rows'],
            'notes_contacts' => $notesResult['contacts'], 'notes_rows' => $notesResult['rows'],
            'testimonials'   => $testimonialsAdded, 'note' => $note,
        ];
    }

    /** @return array{contacts:int, rows:int} */
    private function seedCommunications(int $agencyId): array
    {
        $alreadyCovered = DB::table('communication_links')
            ->where('agency_id', $agencyId)->where('linkable_type', \App\Models\Contact::class)
            ->distinct('linkable_id')->pluck('linkable_id')->all();

        $need = max(0, self::COMMS_COVERAGE_TARGET - count($alreadyCovered));
        if ($need === 0) {
            return ['contacts' => 0, 'rows' => 0];
        }

        $candidates = DB::table('contacts')
            ->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->whereNotIn('id', $alreadyCovered)
            ->orderBy('id')->limit($need)
            ->get(['id', 'first_name', 'last_name', 'email']);

        $agentIds = DB::table('users')->where('agency_id', $agencyId)->whereIn('role', ['agent', 'admin', 'branch_manager'])->orderBy('id')->pluck('id')->all();
        if ($candidates->isEmpty() || empty($agentIds)) {
            return ['contacts' => 0, 'rows' => 0];
        }

        $contactsDone = 0;
        $rowsAdded = 0;
        foreach ($candidates as $idx => $contact) {
            $property = DB::table('contact_property')
                ->join('properties', 'properties.id', '=', 'contact_property.property_id')
                ->where('contact_property.contact_id', $contact->id)
                ->orderBy('contact_property.id')
                ->first(['properties.address', 'properties.suburb']);

            $threadCount = 1 + ($idx % 4);
            $threadKey = 'demo-thread-' . $agencyId . '-' . $contact->id;
            $agentId = $agentIds[$idx % count($agentIds)];
            $contactEmail = $contact->email ?: (strtolower($contact->first_name . '.' . $contact->last_name) . '@example.com');
            $agentEmail = DB::table('users')->where('id', $agentId)->value('email');

            for ($m = 0; $m < $threadCount; $m++) {
                $isInbound = $m % 2 === 0;
                $subject = self::SUBJECTS[($idx + $m) % count(self::SUBJECTS)];
                $propertyLine = $property ? (' regarding ' . $property->address . ', ' . $property->suburb) : '';
                $body = $isInbound
                    ? "Hi, just following up{$propertyLine}. Let me know when you have a moment to chat."
                    : "Hi " . $contact->first_name . ", thanks for reaching out{$propertyLine}. Happy to schedule a time — what works for you this week?";

                $occurredAt = now()->subDays(2 + ($idx % 60) + $m * 3)->subHours($m);
                $externalId = 'demo-email-' . $agencyId . '-' . $contact->id . '-' . $m;

                $exists = DB::table('communications')->where('agency_id', $agencyId)->where('external_id', $externalId)->exists();
                if ($exists) {
                    continue;
                }

                $commId = DB::table('communications')->insertGetId([
                    'agency_id'               => $agencyId,
                    'channel'                 => 'email',
                    'direction'               => $isInbound ? 'inbound' : 'outbound',
                    'send_status'             => 'sent',
                    'external_id'             => $externalId,
                    'thread_key'              => $threadKey,
                    'from_identifier'         => $isInbound ? $contactEmail : $agentEmail,
                    'to_identifiers'          => json_encode([$isInbound ? $agentEmail : $contactEmail]),
                    'participant_identifiers' => json_encode([$contactEmail, $agentEmail]),
                    'occurred_at'             => $occurredAt,
                    'captured_at'             => $occurredAt,
                    'subject'                 => $subject,
                    'body_text'               => $body,
                    'body_preview'            => \Illuminate\Support\Str::limit($body, 150),
                    'has_attachments'         => 0,
                    'owner_user_id'           => $agentId,
                    'created_at'              => $occurredAt,
                    'updated_at'              => $occurredAt,
                ]);

                DB::table('communication_links')->insert([
                    'agency_id'      => $agencyId,
                    'communication_id' => $commId,
                    'linkable_type'  => \App\Models\Contact::class,
                    'linkable_id'    => $contact->id,
                    'link_method'    => 'deterministic',
                    'confidence'     => 100,
                    'confirmed_at'   => $occurredAt,
                    'created_at'     => $occurredAt,
                    'updated_at'     => $occurredAt,
                ]);
                $rowsAdded++;
            }
            $contactsDone++;
        }

        return ['contacts' => $contactsDone, 'rows' => $rowsAdded];
    }

    /** @return array{contacts:int, rows:int} */
    private function seedNotes(int $agencyId): array
    {
        $alreadyCovered = DB::table('contact_notes')->where('agency_id', $agencyId)->distinct('contact_id')->pluck('contact_id')->all();
        $need = max(0, self::NOTES_COVERAGE_TARGET - count($alreadyCovered));
        if ($need === 0) {
            return ['contacts' => 0, 'rows' => 0];
        }

        // Bias toward contacts NOT already getting a full email thread, so
        // coverage overlaps only partially rather than always the same set.
        $candidates = DB::table('contacts')
            ->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->whereNotIn('id', $alreadyCovered)
            ->orderByDesc('id')->limit($need)
            ->get(['id']);

        $agentIds = DB::table('users')->where('agency_id', $agencyId)->whereIn('role', ['agent', 'admin', 'branch_manager'])->orderBy('id')->pluck('id')->all();
        if ($candidates->isEmpty() || empty($agentIds)) {
            return ['contacts' => 0, 'rows' => 0];
        }

        $types = array_keys(self::NOTE_BODIES);
        $contactsDone = 0;
        $rowsAdded = 0;
        foreach ($candidates as $idx => $contact) {
            $noteCount = 1 + ($idx % 3);
            for ($n = 0; $n < $noteCount; $n++) {
                $type = $types[($idx + $n) % count($types)];
                DB::table('contact_notes')->insert([
                    'agency_id'  => $agencyId,
                    'contact_id' => $contact->id,
                    'user_id'    => $agentIds[$idx % count($agentIds)],
                    'type'       => $type,
                    'body'       => self::NOTE_BODIES[$type],
                    'created_at' => now()->subDays(1 + ($idx % 45) + $n * 5),
                    'updated_at' => now()->subDays(1 + ($idx % 45) + $n * 5),
                ]);
                $rowsAdded++;
            }
            $contactsDone++;
        }

        return ['contacts' => $contactsDone, 'rows' => $rowsAdded];
    }

    private function seedTestimonials(int $agencyId): int
    {
        $already = DB::table('contact_testimonials')->where('agency_id', $agencyId)->count();
        $need = max(0, self::TESTIMONIAL_COVERAGE_TARGET - $already);
        if ($need === 0) {
            return 0;
        }

        // Buyers on a deal (deal_contacts, any role) read as genuine "closed
        // client" testimonials rather than a random contact.
        $candidateIds = DB::table('deal_contacts')
            ->whereIn('contact_id', DB::table('contacts')->where('agency_id', $agencyId)->pluck('id'))
            ->distinct('contact_id')->orderBy('contact_id')->limit($need)
            ->pluck('contact_id');

        $quotes = [
            'The whole process was smooth from the first viewing to signing. Always felt informed.',
            'Found us exactly what we were looking for and handled the paperwork without any stress.',
            'Responsive, honest about pricing, and made a nerve-wracking process feel manageable.',
            'Would recommend to anyone buying on the South Coast — knew the area inside out.',
        ];

        $agentIds = DB::table('users')->where('agency_id', $agencyId)->whereIn('role', ['agent', 'admin', 'branch_manager'])->orderBy('id')->pluck('id')->all();

        $added = 0;
        foreach ($candidateIds as $idx => $contactId) {
            $exists = DB::table('contact_testimonials')->where('agency_id', $agencyId)->where('contact_id', $contactId)->exists();
            if ($exists) {
                continue;
            }
            $contact = DB::table('contacts')->where('id', $contactId)->first(['first_name', 'last_name']);
            $displayName = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: 'A happy client';
            $createdAt = now()->subDays(10 + $idx * 7);
            DB::table('contact_testimonials')->insert([
                'agency_id'    => $agencyId,
                'contact_id'   => $contactId,
                'user_id'      => empty($agentIds) ? null : $agentIds[$idx % count($agentIds)],
                'body'         => $quotes[$idx % count($quotes)],
                'display_name' => $displayName,
                'rating'       => 5,
                'published'    => 1,
                'published_at' => $createdAt,
                'created_at'   => $createdAt,
                'updated_at'   => $createdAt,
            ]);
            $added++;
        }

        return $added;
    }
}
