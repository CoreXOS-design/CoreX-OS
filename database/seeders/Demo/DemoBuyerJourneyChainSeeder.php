<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Property;
use App\Models\ViewingPack;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Webinar day (2026-09-03) — "does this read like a LIVE SYSTEM a real
 * agency has been using for months?" Chases the specific cross-feature
 * chain Johan called out: a property with real attached documents, a buyer
 * who actually VIEWED it (a calendar appointment correctly linked via
 * `calendar_event_links` — confirmed by reading BuyerIntelligenceService::
 * getPropertiesViewed(), this is the ONLY path that feeds the buyer
 * detail page's viewing history, and most demo buyers had zero rows there
 * even though calendar_events existed), feedback on that viewing, and a
 * real (persisted, ticked-in) Viewing Pack — end to end, for a specific,
 * openable buyer + property.
 *
 * Deliberately uneven, not uniform: 3 warm buyers get full activity + a
 * ready pack, 2 new buyers get one viewing and no pack yet (early in the
 * funnel), 2 cold buyers get one lukewarm/negative viewing, 1 lost buyer
 * gets a past declined viewing and nothing since — a real agency's buyer
 * list looks like this, not identical rows.
 *
 * Idempotent: documents matched on (property_id, document_type slug,
 * 'demo-journey-chain' marker in original_name) via firstOrCreate-style
 * existence check; calendar events archived-then-recreated by title
 * prefix (same pattern as CalendarDemoSeeder); viewing packs archived-
 * then-recreated by title prefix (same pattern as DemoViewingPacksSeeder).
 */
final class DemoBuyerJourneyChainSeeder
{
    private const MARKER = '[DEMO-CHAIN]';

    /** property_id => [document type slugs to attach]. */
    private const PROPERTY_DOCS = [
        2 => ['mandate', 'disclosure', 'rates_taxes'],
        3 => ['mandate', 'disclosure', 'condition_report'],
        5 => ['mandate', 'disclosure'],
        6 => ['mandate', 'disclosure', 'rates_taxes', 'condition_report'],
        7 => ['mandate', 'disclosure', 'body_corporate'],
        8 => ['mandate', 'disclosure'],
        10 => ['mandate', 'disclosure', 'rates_taxes'],
        12 => ['mandate', 'disclosure', 'condition_report'],
        13 => ['mandate', 'disclosure'],
        14 => ['mandate', 'disclosure', 'rates_taxes', 'body_corporate'],
    ];

    /**
     * contact_id => ['tier' => hot|new|cold|lost, 'viewings' => [[property_id, daysAgo, outcomeLabel]], 'pack' => bool]
     */
    private const BUYER_PLAN = [
        30 => ['tier' => 'warm', 'viewings' => [[18, 6, 'Interested'], [6, 3, 'Interested']], 'pack' => true],
        35 => ['tier' => 'warm', 'viewings' => [[2, 9, 'Interested'], [10, 4, 'Made offer']], 'pack' => true],
        47 => ['tier' => 'warm', 'viewings' => [[7, 5, 'Interested']], 'pack' => true],
        31 => ['tier' => 'new', 'viewings' => [[3, 2, 'Interested']], 'pack' => false],
        36 => ['tier' => 'new', 'viewings' => [[12, 1, 'Interested']], 'pack' => false],
        39 => ['tier' => 'cold', 'viewings' => [[5, 20, 'Not interested']], 'pack' => false],
        46 => ['tier' => 'cold', 'viewings' => [[13, 25, 'No-show']], 'pack' => false],
        29 => ['tier' => 'lost', 'viewings' => [[8, 60, 'Not interested']], 'pack' => false],
    ];

    public function run(int $agencyId): array
    {
        $agency = DB::table('agencies')->where('id', $agencyId)->first(['id', 'name']);
        $branchId = DB::table('branches')->where('agency_id', $agencyId)->orderBy('id')->value('id');
        $agentId = DB::table('users')->where('agency_id', $agencyId)->where('role', 'admin')->value('id')
            ?? DB::table('users')->where('agency_id', $agencyId)->value('id');
        $outcomeIds = DB::table('agency_feedback_options')->where('category', 'outcome')->whereNull('deleted_at')->pluck('id', 'label');
        $documentTypeIds = DB::table('document_types')->whereNull('deleted_at')->pluck('id', 'slug');

        $this->archivePriorBatch($agencyId);

        $docsCreated = $this->seedPropertyDocuments($agencyId, $agentId, $agency->name, $documentTypeIds);
        [$eventsCreated, $feedbackCreated] = $this->seedViewingsAndFeedback($agencyId, $branchId, $agentId, $outcomeIds);
        $packsCreated = $this->seedViewingPacks($agencyId, $agentId);

        return [
            'documents' => $docsCreated,
            'events' => $eventsCreated,
            'feedback' => $feedbackCreated,
            'packs' => $packsCreated,
        ];
    }

    private function seedPropertyDocuments(int $agencyId, int $agentId, string $agencyName, $documentTypeIds): int
    {
        $created = 0;
        $labels = [
            'mandate' => 'Sole Mandate',
            'disclosure' => 'Mandatory Disclosure',
            'rates_taxes' => 'Rates & Taxes Statement',
            'condition_report' => 'Property Condition Report',
            'body_corporate' => 'Body Corporate Levy Statement',
        ];

        foreach (self::PROPERTY_DOCS as $propertyId => $slugs) {
            $property = Property::find($propertyId);
            if (! $property) {
                continue;
            }

            foreach ($slugs as $slug) {
                $typeId = $documentTypeIds[$slug] ?? null;
                if (! $typeId) {
                    continue;
                }

                $originalName = self::MARKER . ' ' . ($labels[$slug] ?? $slug) . ' — ' . $property->address . '.pdf';
                $exists = DB::table('documents')
                    ->where('agency_id', $agencyId)
                    ->where('original_name', $originalName)
                    ->whereNull('deleted_at')
                    ->exists();
                if ($exists) {
                    continue;
                }

                $html = $this->documentHtml($labels[$slug] ?? $slug, $property, $agencyName);
                $pdf = Pdf::loadHTML($html)->output();
                $path = "properties/{$property->id}/files/" . Str::random(32) . '.pdf';
                \Illuminate\Support\Facades\Storage::disk('local')->put($path, $pdf);

                $documentId = DB::table('documents')->insertGetId([
                    'agency_id' => $agencyId,
                    'branch_id' => $property->branch_id,
                    'original_name' => $originalName,
                    'storage_path' => $path,
                    'disk' => 'local',
                    'mime_type' => 'application/pdf',
                    'size' => strlen($pdf),
                    'document_type_id' => $typeId,
                    'source_type' => 'upload',
                    'uploaded_by' => $agentId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('document_properties')->insert([
                    'document_id' => $documentId,
                    'property_id' => $property->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $created++;
            }
        }

        return $created;
    }

    private function documentHtml(string $label, Property $property, string $agencyName): string
    {
        $today = now()->format('d F Y');

        return <<<HTML
        <html><body style="font-family: sans-serif; padding: 40px;">
            <h2>{$agencyName}</h2>
            <h3>{$label}</h3>
            <p><strong>Property:</strong> {$property->address}, {$property->suburb}</p>
            <p><strong>Date:</strong> {$today}</p>
            <hr>
            <p>This is a demonstration document generated for CoreX OS presentation purposes.
            It represents a real {$label} that would be attached to this listing in normal
            agency operation.</p>
        </body></html>
        HTML;
    }

    private function seedViewingsAndFeedback(int $agencyId, ?int $branchId, int $agentId, $outcomeIds): array
    {
        $events = 0;
        $feedback = 0;

        foreach (self::BUYER_PLAN as $contactId => $plan) {
            foreach ($plan['viewings'] as [$propertyId, $daysAgo, $outcomeLabel]) {
                $property = Property::find($propertyId);
                if (! $property) {
                    continue;
                }
                $eventDate = now()->subDays($daysAgo)->setTime(10, 0);
                $title = self::MARKER . ' Viewing — ' . $property->address;

                $eventId = DB::table('calendar_events')->insertGetId([
                    'event_type' => 'manual',
                    'category' => 'viewing',
                    'title' => $title,
                    'description' => null,
                    'event_date' => $eventDate,
                    'end_date' => $eventDate->copy()->addMinutes(30),
                    'all_day' => false,
                    'priority' => 'normal',
                    'send_reminder' => false,
                    'status' => 'completed',
                    'source_type' => 'manual:demo',
                    'user_id' => $agentId,
                    'property_id' => $property->id,
                    'contact_id' => $contactId,
                    'agency_id' => $agencyId,
                    'branch_id' => $branchId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $events++;

                DB::table('calendar_event_links')->insert([
                    [
                        'calendar_event_id' => $eventId,
                        'linkable_type' => 'App\\Models\\Property',
                        'linkable_id' => $property->id,
                        'role' => 'subject_property',
                        'agency_id' => $agencyId,
                        'created_by_user_id' => $agentId,
                        'created_at' => now(), 'updated_at' => now(),
                    ],
                    [
                        'calendar_event_id' => $eventId,
                        'linkable_type' => 'App\\Models\\Contact',
                        'linkable_id' => $contactId,
                        'role' => 'buyer_contact',
                        'agency_id' => $agencyId,
                        'created_by_user_id' => $agentId,
                        'created_at' => now(), 'updated_at' => now(),
                    ],
                ]);

                $notes = match ($outcomeLabel) {
                    'Interested' => 'Buyer responded very positively — liked the layout and location. Following up with next steps.',
                    'Made offer' => 'Buyer loved the property and indicated they want to proceed to an offer.',
                    'Not interested' => 'Buyer felt the property did not match their brief. Not proceeding.',
                    'No-show' => 'Buyer did not arrive for the scheduled viewing.',
                    default => 'Viewing completed.',
                };

                DB::table('calendar_event_feedback')->insert([
                    'calendar_event_id' => $eventId,
                    'contact_id' => $contactId,
                    'feedback_kind' => 'viewing',
                    'property_id' => $property->id,
                    'outcome_option_id' => $outcomeIds[$outcomeLabel] ?? null,
                    'concern_option_ids' => json_encode([]),
                    'seller_visible_notes' => $notes,
                    'internal_notes' => $notes,
                    'captured_by_user_id' => $agentId,
                    'captured_at' => $eventDate->copy()->addHours(2),
                    'agency_id' => $agencyId,
                    'branch_id' => $branchId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $feedback++;
            }
        }

        return [$events, $feedback];
    }

    private function seedViewingPacks(int $agencyId, int $agentId): int
    {
        $created = 0;
        $branchByAgent = DB::table('users')->where('agency_id', $agencyId)->pluck('branch_id', 'id');
        $docsService = app(\App\Services\ViewingPack\ViewingPackDocumentService::class);
        $selection = app(\App\Services\ViewingPack\ViewingPackSelectionService::class);

        foreach (self::BUYER_PLAN as $contactId => $plan) {
            if (! $plan['pack']) {
                continue;
            }

            $pack = ViewingPack::create([
                'agency_id' => $agencyId,
                'contact_id' => $contactId,
                'agent_id' => $agentId,
                'branch_id' => $branchByAgent[$agentId] ?? null,
                'tour_at' => now()->subDays(3),
                'status' => ViewingPack::STATUS_READY,
                'title' => self::MARKER . ' Buyer journey pack',
            ]);

            $propertyIds = array_unique(array_column($plan['viewings'], 0));
            foreach ($propertyIds as $sort => $propertyId) {
                $property = Property::find($propertyId);
                if (! $property) {
                    continue;
                }
                $selection->addProperty($pack, $property, $agentId);
                $vpp = $pack->viewingPackProperties()->where('property_id', $propertyId)->first();
                foreach ($docsService->eligibleDocumentsFor($vpp) as $doc) {
                    $docsService->includeDocument($vpp, $doc);
                }
            }

            $created++;
        }

        return $created;
    }

    private function archivePriorBatch(int $agencyId): void
    {
        DB::transaction(function () use ($agencyId) {
            $now = now();

            // Documents.
            $docIds = DB::table('documents')
                ->where('agency_id', $agencyId)
                ->where('original_name', 'like', self::MARKER . '%')
                ->whereNull('deleted_at')
                ->pluck('id');
            if ($docIds->isNotEmpty()) {
                DB::table('document_properties')->whereIn('document_id', $docIds)->delete();
                DB::table('documents')->whereIn('id', $docIds)->update(['deleted_at' => $now, 'updated_at' => $now]);
            }

            // Calendar events + links + feedback.
            $eventIds = DB::table('calendar_events')
                ->where('agency_id', $agencyId)
                ->where('title', 'like', self::MARKER . '%')
                ->whereNull('deleted_at')
                ->pluck('id');
            if ($eventIds->isNotEmpty()) {
                DB::table('calendar_event_links')->whereIn('calendar_event_id', $eventIds)->delete();
                DB::table('calendar_event_feedback')->whereIn('calendar_event_id', $eventIds)
                    ->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
                DB::table('calendar_events')->whereIn('id', $eventIds)->update(['deleted_at' => $now, 'updated_at' => $now]);
            }

            // Viewing packs (cascade properties + documents).
            $packIds = DB::table('viewing_packs')
                ->where('agency_id', $agencyId)
                ->where('title', 'like', self::MARKER . '%')
                ->whereNull('deleted_at')
                ->pluck('id');
            if ($packIds->isNotEmpty()) {
                $vppIds = DB::table('viewing_pack_properties')->whereIn('viewing_pack_id', $packIds)->pluck('id');
                DB::table('viewing_pack_documents')->whereIn('viewing_pack_property_id', $vppIds)
                    ->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
                DB::table('viewing_pack_properties')->whereIn('viewing_pack_id', $packIds)
                    ->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
                DB::table('viewing_packs')->whereIn('id', $packIds)->update(['deleted_at' => $now, 'updated_at' => $now]);
            }
        });
    }
}
