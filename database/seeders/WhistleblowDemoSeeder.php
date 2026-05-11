<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Compliance\WhistleblowAuditLog;
use App\Models\Compliance\WhistleblowComplaint;
use App\Models\Compliance\WhistleblowComplaintEvidence;
use App\Models\Property;
use App\Models\User;
use App\Services\Compliance\WhistleblowComplaintService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class WhistleblowDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment('local')) {
            $this->command->error('WhistleblowDemoSeeder cannot run in production. Current: ' . app()->environment());
            return;
        }

        // ── Idempotency: wipe existing [DEMO] complaints ──
        $existing = WhistleblowComplaint::withoutGlobalScopes()
            ->withTrashed()
            ->where('subject_agency_name', 'like', '[DEMO]%')
            ->get();

        if ($existing->count() > 0) {
            $this->command->info("Removing {$existing->count()} existing [DEMO] complaints...");
            foreach ($existing as $c) {
                // Clear property compliance flags referencing these complaints
                if ($c->property_id) {
                    $prop = Property::withoutGlobalScopes()->find($c->property_id);
                    if ($prop && $prop->compliance_evidence_flags) {
                        $flags = collect($prop->compliance_evidence_flags)
                            ->reject(fn($f) => ($f['complaint_id'] ?? null) == $c->id)
                            ->values()
                            ->all();
                        $prop->compliance_evidence_flags = !empty($flags) ? $flags : null;
                        $prop->saveQuietly();
                    }
                }
                // Clean PDF
                if ($c->complaint_pdf_path && file_exists($c->complaint_pdf_path)) {
                    @unlink($c->complaint_pdf_path);
                    $dir = dirname($c->complaint_pdf_path);
                    if (is_dir($dir) && count(glob($dir . '/*')) === 0) {
                        @rmdir($dir);
                    }
                }
                $c->forceDelete();
            }
        }

        // ── Resolve users ──
        $johan = User::where('email', 'johan@hfcoastal.co.za')->first();
        $retha = User::where('agency_id', 1)->where('name', 'like', '%Retha%')->first();
        $falan = User::where('agency_id', 1)->where('role', 'branch_manager')->first();
        $elize = User::where('agency_id', 1)->where('role', 'admin')->first();
        $agents = collect([$retha, $falan, $elize, $johan])->filter();

        if (!$johan) {
            $this->command->error('Johan not found. Cannot seed.');
            return;
        }

        // ── Resolve sample properties (link some complaints to real properties) ──
        $properties = Property::withoutGlobalScopes()
            ->where('agency_id', 1)
            ->whereNotNull('address')
            ->limit(8)
            ->get();

        $svc = app(WhistleblowComplaintService::class);

        // ── Define 12 complaints ──
        $complaints = [
            // ═══ TIER 1 — Paperwork breach (6) ═══
            [
                'tier' => 'tier_1', 'target_status' => 'sent', 'days_ago' => 45,
                'subject_agency_name' => '[DEMO] Coastal Realty Group',
                'subject_practitioner_name' => 'Daniel Mokoena',
                'property_address' => '12 Marine Drive, Uvongo',
                'property_portal_url' => 'https://demo-portal.example.com/listing/47291',
                'portal_source' => 'pp',
                'seller_statement' => 'I called the agent at [DEMO] Coastal Realty Group to ask why my house was on Property24 and they told me they didn\'t need my signature because the previous tenant had authorized them. I never met them before.',
                'seller_consents_to_named_complaint' => true,
                'agent_notes' => 'Seller very upset. Confirmed no mandate, no FICA, no MDF signed. Agent claimed verbal agreement was sufficient.',
                'reporter' => $retha,
                'approver' => $johan,
                'link_property' => true,
            ],
            [
                'tier' => 'tier_1', 'target_status' => 'sent', 'days_ago' => 38,
                'subject_agency_name' => '[DEMO] Margate Property Brokers',
                'subject_practitioner_name' => 'Sarah van der Westhuizen',
                'property_address' => '7 Lighthouse Road, Shelly Beach',
                'property_portal_url' => 'https://demo-portal.example.com/listing/51823',
                'portal_source' => 'p24',
                'seller_statement' => 'We signed a paper years ago for a different agency. [DEMO] Margate Property Brokers started advertising last week without contacting us. We asked them to remove it and they refused.',
                'seller_consents_to_named_complaint' => true,
                'agent_notes' => 'Previous mandate was with a completely different agency (expired 2024). New agency has no documentation at all.',
                'reporter' => $falan ?? $retha,
                'approver' => $johan,
                'link_property' => true,
            ],
            [
                'tier' => 'tier_1', 'target_status' => 'acknowledged_by_ppra', 'days_ago' => 55,
                'subject_agency_name' => '[DEMO] Hibiscus Coast Estates',
                'subject_practitioner_name' => 'Thabo Khumalo',
                'property_address' => '3 Palm Boulevard, Ramsgate',
                'property_portal_url' => 'https://demo-portal.example.com/listing/39104',
                'portal_source' => 'pp',
                'seller_statement' => 'I am the registered owner and I have never heard of [DEMO] Hibiscus Coast Estates. They listed my property without my knowledge or consent.',
                'seller_consents_to_named_complaint' => true,
                'agent_notes' => 'Seller found the listing while browsing PrivateProperty. Immediately called us. Confirmed no contact from subject agency ever.',
                'reporter' => $retha,
                'approver' => $johan,
                'link_property' => true,
                'ppra_ref' => 'PPRA/2026/48271',
            ],
            [
                'tier' => 'tier_1', 'target_status' => 'pending_approval', 'days_ago' => 3,
                'subject_agency_name' => '[DEMO] South Coast Realtors',
                'subject_practitioner_name' => 'Nomsa Dlamini',
                'property_address' => '22 Ocean View Crescent, Port Edward',
                'property_portal_url' => 'https://demo-portal.example.com/listing/62017',
                'portal_source' => 'p24',
                'seller_statement' => 'The seller says she was approached at a shopping centre and asked to sign something but was told it was just for a valuation, not a mandate to sell.',
                'seller_consents_to_named_complaint' => false,
                'agent_notes' => 'Seller does not want to be named but is willing for complaint to proceed anonymously. Suggests the signature on the mandate was obtained under false pretences.',
                'reporter' => $elize ?? $retha,
                'approver' => null,
                'link_property' => false,
            ],
            [
                'tier' => 'tier_1', 'target_status' => 'rejected', 'days_ago' => 20,
                'subject_agency_name' => '[DEMO] KZN Premier Property',
                'subject_practitioner_name' => 'Andre Booysen',
                'property_address' => '15 Disa Road, Manaba Beach',
                'property_portal_url' => 'https://demo-portal.example.com/listing/55301',
                'portal_source' => 'pp',
                'seller_statement' => 'I think they might have an old mandate from my late husband\'s estate, but I\'m not sure if it\'s still valid.',
                'seller_consents_to_named_complaint' => true,
                'agent_notes' => 'Uncertain case. Seller not sure about existing mandate status from deceased estate.',
                'reporter' => $retha,
                'approver' => $johan,
                'rejection_reason' => 'Insufficient evidence — seller unsure whether valid mandate exists via deceased estate. Recommend agent assist seller in establishing mandate status before resubmitting.',
                'link_property' => false,
            ],
            [
                'tier' => 'tier_1', 'target_status' => 'changes_requested', 'days_ago' => 5,
                'subject_agency_name' => '[DEMO] Coastal Realty Group',
                'subject_practitioner_name' => 'Linda September',
                'property_address' => '8 Frangipani Close, Southbroom',
                'property_portal_url' => 'https://demo-portal.example.com/listing/63842',
                'portal_source' => 'p24',
                'seller_statement' => 'Nobody from that agency has ever spoken to me about selling my house.',
                'seller_consents_to_named_complaint' => true,
                'agent_notes' => 'Clear-cut case. Seller emphatic.',
                'reporter' => $falan ?? $retha,
                'approver' => $johan,
                'changes_notes' => 'Please attach a screenshot of the actual listing on the portal — the URL you provided returns a 404. Also confirm the seller\'s full name.',
                'link_property' => false,
            ],

            // ═══ TIER 2 — No FFC displayed (4) ═══
            [
                'tier' => 'tier_2', 'target_status' => 'sent', 'days_ago' => 30,
                'subject_agency_name' => '[DEMO] South Coast Realtors',
                'subject_practitioner_name' => null,
                'property_address' => '44 Hibiscus Way, Margate',
                'property_portal_url' => 'https://demo-portal.example.com/listing/41987',
                'portal_source' => 'p24',
                'agent_notes' => 'Property24 listing for this property does not display any FFC number. Checked the agency\'s other listings — none display an FFC.',
                'reporter' => $retha,
                'approver' => $johan,
                'link_property' => true,
            ],
            [
                'tier' => 'tier_2', 'target_status' => 'sent', 'days_ago' => 22,
                'subject_agency_name' => '[DEMO] Margate Property Brokers',
                'subject_practitioner_name' => 'J. Naidoo',
                'property_address' => '9 Beach Road, Port Shepstone',
                'property_portal_url' => 'https://demo-portal.example.com/listing/53612',
                'portal_source' => 'pp',
                'agent_notes' => 'PrivateProperty listing shows agency name but no FFC number anywhere in the advert. Screenshot attached.',
                'reporter' => $falan ?? $retha,
                'approver' => $falan ?? $johan,
                'link_property' => false,
            ],
            [
                'tier' => 'tier_2', 'target_status' => 'pending_approval', 'days_ago' => 1,
                'subject_agency_name' => '[DEMO] Hibiscus Coast Estates',
                'subject_practitioner_name' => 'P. Govender',
                'property_address' => '31 Coral Reef Drive, Uvongo',
                'property_portal_url' => 'https://demo-portal.example.com/listing/64501',
                'portal_source' => 'p24',
                'agent_notes' => 'Spotted this listing today. No FFC visible. Agency seems to be relatively new — possibly unaware of the requirement.',
                'reporter' => $elize ?? $retha,
                'approver' => null,
                'link_property' => false,
            ],
            [
                'tier' => 'tier_2', 'target_status' => 'sent', 'days_ago' => 15,
                'subject_agency_name' => '[DEMO] KZN Premier Property',
                'subject_practitioner_name' => null,
                'property_address' => '5 Lagoon Drive, Shelly Beach',
                'property_portal_url' => 'https://demo-portal.example.com/listing/58290',
                'portal_source' => 'p24',
                'agent_notes' => 'Multiple listings by this agency on P24 — none display FFC number. This appears to be a systemic issue, not an oversight.',
                'reporter' => $retha,
                'approver' => $johan,
                'link_property' => true,
            ],

            // ═══ TIER 3 — Unregistered practitioner (2) ═══
            [
                'tier' => 'tier_3', 'target_status' => 'acknowledged_by_ppra', 'days_ago' => 50,
                'subject_agency_name' => '[DEMO] Phantom Property Services',
                'subject_practitioner_name' => 'Unknown Individual (M. Zwane)',
                'property_address' => '17 Victoria Road, Port Shepstone',
                'property_portal_url' => 'https://demo-portal.example.com/listing/38002',
                'portal_source' => 'p24',
                'agent_notes' => 'Searched PPRA "Find a Property Practitioner" register for both the individual and the agency name. Zero results. This person appears to be operating without any FFC at all. Screenshot of register search attached.',
                'reporter' => $johan,
                'approver' => $johan,
                'link_property' => false,
                'ppra_ref' => 'PPRA/2026/41093',
            ],
            [
                'tier' => 'tier_3', 'target_status' => 'draft', 'days_ago' => 2,
                'subject_agency_name' => '[DEMO] Unregistered Agent (Facebook)',
                'subject_practitioner_name' => 'S. Mkhize',
                'property_address' => '29 Sunset Strip, Margate',
                'property_portal_url' => 'https://demo-portal.example.com/listing/65100',
                'portal_source' => 'other',
                'agent_notes' => 'Found this listing on Facebook Marketplace. The person advertising appears to be acting as an agent but when I searched the PPRA register I found nothing. Still gathering evidence — will submit once screenshots are attached.',
                'reporter' => $retha,
                'approver' => null,
                'link_property' => false,
            ],
        ];

        $this->command->info('Seeding 12 [DEMO] whistleblower complaints...');
        $propIndex = 0;

        foreach ($complaints as $i => $spec) {
            $baseDate = now()->subDays($spec['days_ago']);
            $reporter = $spec['reporter'] ?? $retha ?? $johan;
            $approver = $spec['approver'];

            // Link property if requested
            $propertyId = null;
            if ($spec['link_property'] && isset($properties[$propIndex])) {
                $propertyId = $properties[$propIndex]->id;
                $propIndex++;
            }

            // Create complaint directly (not via service, to control timestamps)
            $complaint = WhistleblowComplaint::withoutGlobalScopes()->create([
                'agency_id'            => 1,
                'branch_id'            => $reporter->branch_id,
                'reported_by_user_id'  => $reporter->id,
                'tier'                 => $spec['tier'],
                'subject_agency_name'  => $spec['subject_agency_name'],
                'subject_practitioner_name' => $spec['subject_practitioner_name'] ?? null,
                'property_id'          => $propertyId,
                'property_address'     => $spec['property_address'],
                'property_portal_url'  => $spec['property_portal_url'] ?? null,
                'portal_source'        => $spec['portal_source'] ?? null,
                'seller_statement'     => $spec['seller_statement'] ?? null,
                'seller_consents_to_named_complaint' => $spec['seller_consents_to_named_complaint'] ?? false,
                'agent_notes'          => $spec['agent_notes'] ?? null,
                'status'               => 'draft',
                'created_at'           => $baseDate,
                'updated_at'           => $baseDate,
            ]);

            // Audit: created
            $this->audit($complaint, 'created', $reporter, $baseDate);

            // Attach 1-2 evidence rows
            $evidenceTypes = $spec['tier'] === 'tier_1'
                ? ['screenshot', 'other']
                : ($spec['tier'] === 'tier_3' ? ['screenshot', 'screenshot'] : ['screenshot']);

            foreach ($evidenceTypes as $eIdx => $eType) {
                $desc = match ($eType) {
                    'screenshot' => $eIdx === 0
                        ? 'Screenshot of portal listing'
                        : ($spec['tier'] === 'tier_3' ? 'PPRA register search — no results' : 'Additional evidence'),
                    default => 'Call notes from seller conversation',
                };
                WhistleblowComplaintEvidence::create([
                    'complaint_id'       => $complaint->id,
                    'evidence_type'      => $eType,
                    'file_path'          => "/tmp/demo-evidence-{$complaint->id}-{$eIdx}.png",
                    'original_filename'  => "evidence-{$complaint->id}-{$eIdx}.png",
                    'mime_type'          => $eType === 'screenshot' ? 'image/png' : 'text/plain',
                    'size_bytes'         => rand(50000, 350000),
                    'description'        => $desc,
                    'uploaded_by_user_id' => $reporter->id,
                    'created_at'         => $baseDate,
                    'updated_at'         => $baseDate,
                ]);
                $this->audit($complaint, 'evidence_attached', $reporter, $baseDate->copy()->addMinutes(rand(1, 10)), [
                    'evidence_type' => $eType, 'filename' => "evidence-{$complaint->id}-{$eIdx}.png",
                ]);
            }

            $targetStatus = $spec['target_status'];

            // Draft stays as-is
            if ($targetStatus === 'draft') {
                $this->command->line("  #{$complaint->id} Tier {$spec['tier']} → draft");
                continue;
            }

            // Submit
            $submitDate = $baseDate->copy()->addHours(rand(1, 6));
            $complaint->update(['status' => 'pending_approval', 'updated_at' => $submitDate]);
            $this->audit($complaint, 'submitted', $reporter, $submitDate);

            if ($targetStatus === 'pending_approval') {
                $this->command->line("  #{$complaint->id} Tier {$spec['tier']} → pending_approval");
                continue;
            }

            // Changes requested
            if ($targetStatus === 'changes_requested') {
                $changesDate = $submitDate->copy()->addHours(rand(2, 12));
                $complaint->update(['status' => 'changes_requested', 'updated_at' => $changesDate]);
                $this->audit($complaint, 'changes_requested', $approver, $changesDate, [
                    'notes' => $spec['changes_notes'] ?? 'Please provide additional evidence.',
                ]);
                $this->command->line("  #{$complaint->id} Tier {$spec['tier']} → changes_requested");
                continue;
            }

            // Rejected
            if ($targetStatus === 'rejected') {
                $rejectDate = $submitDate->copy()->addHours(rand(4, 24));
                $complaint->update([
                    'status' => 'rejected',
                    'rejected_by_user_id' => $approver->id,
                    'rejected_at' => $rejectDate,
                    'rejection_reason' => $spec['rejection_reason'] ?? 'Insufficient evidence.',
                    'updated_at' => $rejectDate,
                ]);
                $this->audit($complaint, 'rejected', $approver, $rejectDate, [
                    'reason' => $spec['rejection_reason'] ?? 'Insufficient evidence.',
                ]);
                $this->command->line("  #{$complaint->id} Tier {$spec['tier']} → rejected");
                continue;
            }

            // Approve
            $approveDate = $submitDate->copy()->addHours(rand(2, 8));
            $complaint->update([
                'status' => 'approved',
                'approved_by_user_id' => $approver->id,
                'approved_at' => $approveDate,
                'approval_notes' => 'Approved for PPRA submission.',
                'updated_at' => $approveDate,
            ]);
            $this->audit($complaint, 'approved', $approver, $approveDate, ['notes' => 'Approved for PPRA submission.']);

            // Generate PDF
            try {
                $pdfPath = $this->generateDemoPdf($complaint, $svc);
                $complaint->update(['complaint_pdf_path' => $pdfPath]);
                $this->audit($complaint, 'pdf_generated', $approver, $approveDate->copy()->addSeconds(3), ['pdf_path' => $pdfPath]);
            } catch (\Throwable $e) {
                $this->command->warn("  PDF generation failed for #{$complaint->id}: " . $e->getMessage());
            }

            // Flag property
            if ($complaint->property_id) {
                $svc->flagPropertyEvidence($complaint);
            }

            // Mark sent
            $sentDate = $approveDate->copy()->addMinutes(1);
            $complaint->update([
                'status' => 'sent',
                'sent_to_ppra_at' => $sentDate,
                'updated_at' => $sentDate,
            ]);
            $this->audit($complaint, 'emailed_to_ppra', null, $sentDate, [
                'recipient_to' => 'johan@hfcoastal.co.za',
                'demo_mode' => true,
            ]);

            if ($targetStatus === 'sent') {
                $this->command->line("  #{$complaint->id} Tier {$spec['tier']} → sent");
                continue;
            }

            // Acknowledged by PPRA
            if ($targetStatus === 'acknowledged_by_ppra') {
                $ackDate = $sentDate->copy()->addDays(rand(3, 10));
                $complaint->update([
                    'status' => 'acknowledged_by_ppra',
                    'ppra_acknowledged_at' => $ackDate,
                    'ppra_reference_number' => $spec['ppra_ref'] ?? 'PPRA/2026/' . rand(10000, 99999),
                    'updated_at' => $ackDate,
                ]);
                $this->audit($complaint, 'acknowledged_by_ppra', null, $ackDate, [
                    'ppra_reference' => $complaint->ppra_reference_number,
                ]);
                $this->command->line("  #{$complaint->id} Tier {$spec['tier']} → acknowledged_by_ppra ({$complaint->ppra_reference_number})");
            }
        }

        $this->command->info('Done. ' . WhistleblowComplaint::withoutGlobalScopes()->where('subject_agency_name', 'like', '[DEMO]%')->count() . ' [DEMO] complaints seeded.');
    }

    private function audit(WhistleblowComplaint $complaint, string $action, ?User $user, Carbon $at, ?array $data = null): void
    {
        WhistleblowAuditLog::create([
            'complaint_id' => $complaint->id,
            'user_id'      => $user?->id,
            'action'       => $action,
            'action_data'  => $data,
            'created_at'   => $at,
        ]);
    }

    private function generateDemoPdf(WhistleblowComplaint $complaint, WhistleblowComplaintService $svc): string
    {
        // Use reflection to call the protected generatePdf method
        $method = new \ReflectionMethod($svc, 'generatePdf');
        $method->setAccessible(true);
        return $method->invoke($svc, $complaint);
    }
}
