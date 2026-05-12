<?php

namespace Database\Seeders;

use App\Models\Compliance\WhistleblowAuditLog;
use App\Models\Compliance\WhistleblowComplaint;
use App\Models\Compliance\WhistleblowComplaintEvidence;
use App\Models\Compliance\WhistleblowComplaintSubject;
use App\Models\Property;
use App\Models\User;
use App\Services\Compliance\WhistleblowComplaintService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class WhistleblowDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment('local')) {
            $this->command->error('WhistleblowDemoSeeder cannot run in production. Current: ' . app()->environment());
            return;
        }

        // ── Idempotency: wipe existing [DEMO] complaints ──
        // Match by agent_notes starting with [DEMO] since subject columns are now on subjects table
        $existing = WhistleblowComplaint::withoutGlobalScopes()
            ->withTrashed()
            ->where('agent_notes', 'like', '[DEMO]%')
            ->get();

        if ($existing->count() > 0) {
            $this->command->info("Removing {$existing->count()} existing [DEMO] complaints...");
            foreach ($existing as $c) {
                if ($c->property_id) {
                    $prop = Property::withoutGlobalScopes()->find($c->property_id);
                    if ($prop && $prop->compliance_evidence_flags) {
                        $flags = collect($prop->compliance_evidence_flags)
                            ->reject(fn($f) => ($f['complaint_id'] ?? null) == $c->id)
                            ->values()->all();
                        $prop->compliance_evidence_flags = !empty($flags) ? $flags : null;
                        $prop->saveQuietly();
                    }
                }
                if ($c->complaint_pdf_path && file_exists($c->complaint_pdf_path)) {
                    @unlink($c->complaint_pdf_path);
                    $dir = dirname($c->complaint_pdf_path);
                    if (is_dir($dir) && count(glob($dir . '/*')) === 0) @rmdir($dir);
                }
                $c->forceDelete();
            }
        }

        $johan = User::where('email', 'johan@hfcoastal.co.za')->first();
        $retha = User::where('agency_id', 1)->where('name', 'like', '%Retha%')->first();
        $falan = User::where('agency_id', 1)->where('role', 'branch_manager')->first();
        $elize = User::where('agency_id', 1)->where('role', 'admin')->first();
        if (!$johan) { $this->command->error('Johan not found.'); return; }

        $properties = Property::withoutGlobalScopes()->where('agency_id', 1)->whereNotNull('address')->limit(8)->get();
        $svc = app(WhistleblowComplaintService::class);

        $complaints = [
            // ═══ TIER 1 (6) ═══
            ['tier' => 'tier_1', 'target_status' => 'sent', 'days_ago' => 45,
             'property_address' => '12 Marine Drive, Uvongo',
             'seller_statement' => 'I called the agent at Coastal Realty Group to ask why my house was on Property24 and they told me they didn\'t need my signature because the previous tenant had authorized them. I never met them before.',
             'agent_notes' => '[DEMO] Seller upset. Confirmed no mandate, no FICA, no MDF signed.',
             'subjects' => [['agency_name' => '[DEMO] Coastal Realty Group', 'practitioner_name' => 'Daniel Mokoena', 'portal_url' => 'https://demo-portal.example.com/listing/47291', 'portal_source' => 'pp']],
             'reporter' => $retha, 'approver' => $johan, 'link_property' => true],

            ['tier' => 'tier_1', 'target_status' => 'sent', 'days_ago' => 38,
             'property_address' => '7 Lighthouse Road, Shelly Beach',
             'seller_statement' => 'We signed a sole mandate with a different agency two months ago. Margate Property Brokers started advertising our property last week without contacting us. We asked them to remove it and they refused.',
             'agent_notes' => '[DEMO] Previous mandate was with a different agency (expired 2024).',
             'subjects' => [['agency_name' => '[DEMO] Margate Property Brokers', 'practitioner_name' => 'Sarah van der Westhuizen', 'portal_url' => 'https://demo-portal.example.com/listing/51823', 'portal_source' => 'p24']],
             'reporter' => $falan ?? $retha, 'approver' => $johan, 'link_property' => true],

            ['tier' => 'tier_1', 'target_status' => 'acknowledged_by_ppra', 'days_ago' => 55,
             'property_address' => '3 Palm Boulevard, Ramsgate',
             'seller_statement' => 'I am the registered owner and I have never heard of Hibiscus Coast Estates. They listed my property without my knowledge or consent.',
             'agent_notes' => '[DEMO] Seller found the listing while browsing PrivateProperty.',
             'subjects' => [['agency_name' => '[DEMO] Hibiscus Coast Estates', 'practitioner_name' => 'Thabo Khumalo', 'portal_url' => 'https://demo-portal.example.com/listing/39104', 'portal_source' => 'pp']],
             'reporter' => $retha, 'approver' => $johan, 'link_property' => true, 'ppra_ref' => 'PPRA/2026/48271'],

            // Multi-subject: 2 agencies reported on same property
            ['tier' => 'tier_1', 'target_status' => 'pending_approval', 'days_ago' => 3,
             'property_address' => '22 Ocean View Crescent, Port Edward',
             'seller_statement' => 'Two different agencies contacted me about my property being listed. I never signed anything with either of them.',
             'agent_notes' => '[DEMO] Seller reports TWO agencies marketing without mandate.',
             'subjects' => [
                 ['agency_name' => '[DEMO] South Coast Realtors', 'practitioner_name' => 'Nomsa Dlamini', 'portal_url' => 'https://demo-portal.example.com/listing/62017', 'portal_source' => 'p24'],
                 ['agency_name' => '[DEMO] Beachfront Brokers SA', 'practitioner_name' => 'Christo Pretorius', 'portal_url' => 'https://demo-portal.example.com/listing/62018', 'portal_source' => 'pp'],
             ],
             'reporter' => $elize ?? $retha, 'approver' => null, 'link_property' => false],

            ['tier' => 'tier_1', 'target_status' => 'rejected', 'days_ago' => 20,
             'property_address' => '15 Disa Road, Manaba Beach',
             'seller_statement' => 'I think they might have an old mandate from my late husband\'s estate, but I\'m not sure if it\'s still valid.',
             'agent_notes' => '[DEMO] Uncertain case. Seller not sure about existing mandate status.',
             'subjects' => [['agency_name' => '[DEMO] KZN Premier Property', 'practitioner_name' => 'Andre Booysen', 'portal_url' => 'https://demo-portal.example.com/listing/55301', 'portal_source' => 'pp']],
             'reporter' => $retha, 'approver' => $johan, 'link_property' => false,
             'rejection_reason' => 'Insufficient evidence — seller unsure whether valid mandate exists via deceased estate.'],

            ['tier' => 'tier_1', 'target_status' => 'changes_requested', 'days_ago' => 5,
             'property_address' => '8 Frangipani Close, Southbroom',
             'seller_statement' => 'Nobody from that agency has ever spoken to me about selling my house.',
             'agent_notes' => '[DEMO] Clear-cut case. Seller emphatic.',
             'subjects' => [['agency_name' => '[DEMO] Coastal Realty Group', 'practitioner_name' => 'Linda September', 'portal_url' => 'https://demo-portal.example.com/listing/63842', 'portal_source' => 'p24']],
             'reporter' => $falan ?? $retha, 'approver' => $johan, 'link_property' => false,
             'changes_notes' => 'Please attach a screenshot of the actual listing — the URL you provided returns a 404.'],

            // ═══ TIER 2 (4) ═══
            ['tier' => 'tier_2', 'target_status' => 'sent', 'days_ago' => 30,
             'property_address' => '44 Hibiscus Way, Margate',
             'agent_notes' => '[DEMO] P24 listing does not display any FFC number.',
             'subjects' => [['agency_name' => '[DEMO] South Coast Realtors', 'portal_url' => 'https://demo-portal.example.com/listing/41987', 'portal_source' => 'p24']],
             'reporter' => $retha, 'approver' => $johan, 'link_property' => true],

            ['tier' => 'tier_2', 'target_status' => 'sent', 'days_ago' => 22,
             'property_address' => '9 Beach Road, Port Shepstone',
             'agent_notes' => '[DEMO] PP listing shows agency name but no FFC number anywhere.',
             'subjects' => [['agency_name' => '[DEMO] Margate Property Brokers', 'practitioner_name' => 'J. Naidoo', 'portal_url' => 'https://demo-portal.example.com/listing/53612', 'portal_source' => 'pp']],
             'reporter' => $falan ?? $retha, 'approver' => $falan ?? $johan, 'link_property' => false],

            ['tier' => 'tier_2', 'target_status' => 'pending_approval', 'days_ago' => 1,
             'property_address' => '31 Coral Reef Drive, Uvongo',
             'agent_notes' => '[DEMO] Spotted today. No FFC visible.',
             'subjects' => [['agency_name' => '[DEMO] Hibiscus Coast Estates', 'practitioner_name' => 'P. Govender', 'portal_url' => 'https://demo-portal.example.com/listing/64501', 'portal_source' => 'p24']],
             'reporter' => $elize ?? $retha, 'approver' => null, 'link_property' => false],

            ['tier' => 'tier_2', 'target_status' => 'sent', 'days_ago' => 15,
             'property_address' => '5 Lagoon Drive, Shelly Beach',
             'agent_notes' => '[DEMO] Multiple listings by this agency — none display FFC number.',
             'subjects' => [['agency_name' => '[DEMO] KZN Premier Property', 'portal_url' => 'https://demo-portal.example.com/listing/58290', 'portal_source' => 'p24']],
             'reporter' => $retha, 'approver' => $johan, 'link_property' => true],

            // ═══ TIER 3 (2) ═══
            // Multi-subject: 3 agencies reported
            ['tier' => 'tier_3', 'target_status' => 'acknowledged_by_ppra', 'days_ago' => 50,
             'property_address' => '17 Victoria Road, Port Shepstone',
             'agent_notes' => '[DEMO] Searched PPRA register. Zero results for all three.',
             'subjects' => [
                 ['agency_name' => '[DEMO] Phantom Property Services', 'practitioner_name' => 'M. Zwane', 'portal_url' => 'https://demo-portal.example.com/listing/38002', 'portal_source' => 'p24'],
                 ['agency_name' => '[DEMO] Ghost Estates KZN', 'practitioner_name' => 'R. Sithole', 'portal_url' => 'https://demo-portal.example.com/listing/38003', 'portal_source' => 'pp'],
                 ['agency_name' => '[DEMO] Unlicensed Realty', 'practitioner_name' => 'T. Ngcobo', 'portal_url' => 'https://demo-portal.example.com/listing/38004', 'portal_source' => 'p24'],
             ],
             'reporter' => $johan, 'approver' => $johan, 'link_property' => false, 'ppra_ref' => 'PPRA/2026/41093'],

            ['tier' => 'tier_3', 'target_status' => 'draft', 'days_ago' => 2,
             'property_address' => '29 Sunset Strip, Margate',
             'agent_notes' => '[DEMO] Found on Facebook Marketplace. Still gathering evidence.',
             'subjects' => [['agency_name' => '[DEMO] Unregistered Agent (Facebook)', 'practitioner_name' => 'S. Mkhize', 'portal_url' => 'https://demo-portal.example.com/listing/65100', 'portal_source' => 'other']],
             'reporter' => $retha, 'approver' => null, 'link_property' => false],
        ];

        $this->command->info('Seeding 12 [DEMO] whistleblower complaints...');
        $propIndex = 0;

        foreach ($complaints as $spec) {
            $baseDate = now()->subDays($spec['days_ago']);
            $reporter = $spec['reporter'] ?? $retha ?? $johan;
            $approver = $spec['approver'];
            $propertyId = null;
            if ($spec['link_property'] && isset($properties[$propIndex])) {
                $propertyId = $properties[$propIndex]->id;
                $propIndex++;
            }

            $complaint = WhistleblowComplaint::withoutGlobalScopes()->create([
                'agency_id' => 1, 'branch_id' => $reporter->branch_id,
                'reported_by_user_id' => $reporter->id,
                'tier' => $spec['tier'],
                'property_id' => $propertyId,
                'property_address' => $spec['property_address'],
                'seller_statement' => $spec['seller_statement'] ?? null,
                'agent_notes' => $spec['agent_notes'],
                'status' => 'draft',
                'created_at' => $baseDate, 'updated_at' => $baseDate,
            ]);

            // Create subject rows
            foreach ($spec['subjects'] as $si => $subj) {
                WhistleblowComplaintSubject::create([
                    'complaint_id' => $complaint->id,
                    'agency_name' => $subj['agency_name'],
                    'practitioner_name' => $subj['practitioner_name'] ?? null,
                    'portal_url' => $subj['portal_url'],
                    'portal_source' => $subj['portal_source'],
                    'display_order' => $si,
                    'created_at' => $baseDate, 'updated_at' => $baseDate,
                ]);
            }

            $this->audit($complaint, 'created', $reporter, $baseDate);

            // Evidence
            $eTypes = $spec['tier'] === 'tier_1' ? ['screenshot', 'other'] : ($spec['tier'] === 'tier_3' ? ['screenshot', 'screenshot'] : ['screenshot']);
            foreach ($eTypes as $eIdx => $eType) {
                WhistleblowComplaintEvidence::create([
                    'complaint_id' => $complaint->id, 'evidence_type' => $eType,
                    'file_path' => "/tmp/demo-evidence-{$complaint->id}-{$eIdx}.png",
                    'original_filename' => "evidence-{$complaint->id}-{$eIdx}.png",
                    'mime_type' => $eType === 'screenshot' ? 'image/png' : 'text/plain',
                    'size_bytes' => rand(50000, 350000),
                    'description' => $eIdx === 0 ? 'Screenshot of portal listing' : 'Additional evidence',
                    'uploaded_by_user_id' => $reporter->id,
                    'created_at' => $baseDate, 'updated_at' => $baseDate,
                ]);
            }

            $targetStatus = $spec['target_status'];
            if ($targetStatus === 'draft') { $this->command->line("  #{$complaint->id} {$spec['tier']} → draft ({$complaint->subjects()->count()} subjects)"); continue; }

            $submitDate = $baseDate->copy()->addHours(rand(1, 6));
            $complaint->update(['status' => 'pending_approval', 'updated_at' => $submitDate]);
            $this->audit($complaint, 'submitted', $reporter, $submitDate);

            if ($targetStatus === 'pending_approval') { $this->command->line("  #{$complaint->id} {$spec['tier']} → pending_approval ({$complaint->subjects()->count()} subjects)"); continue; }

            if ($targetStatus === 'changes_requested') {
                $d = $submitDate->copy()->addHours(rand(2, 12));
                $complaint->update(['status' => 'changes_requested', 'updated_at' => $d]);
                $this->audit($complaint, 'changes_requested', $approver, $d, ['notes' => $spec['changes_notes'] ?? '']);
                $this->command->line("  #{$complaint->id} {$spec['tier']} → changes_requested"); continue;
            }

            if ($targetStatus === 'rejected') {
                $d = $submitDate->copy()->addHours(rand(4, 24));
                $complaint->update(['status' => 'rejected', 'rejected_by_user_id' => $approver->id, 'rejected_at' => $d, 'rejection_reason' => $spec['rejection_reason'] ?? 'Insufficient evidence.', 'updated_at' => $d]);
                $this->audit($complaint, 'rejected', $approver, $d, ['reason' => $spec['rejection_reason'] ?? '']);
                $this->command->line("  #{$complaint->id} {$spec['tier']} → rejected"); continue;
            }

            // Approve
            $approveDate = $submitDate->copy()->addHours(rand(2, 8));
            $complaint->update(['status' => 'approved', 'approved_by_user_id' => $approver->id, 'approved_at' => $approveDate, 'updated_at' => $approveDate]);
            $this->audit($complaint, 'approved', $approver, $approveDate);

            try {
                $method = new \ReflectionMethod($svc, 'generatePdf');
                $method->setAccessible(true);
                $pdfPath = $method->invoke($svc, $complaint);
                $complaint->update(['complaint_pdf_path' => $pdfPath]);
                $this->audit($complaint, 'pdf_generated', $approver, $approveDate->copy()->addSeconds(3));
            } catch (\Throwable $e) {
                $this->command->warn("  PDF failed for #{$complaint->id}: " . $e->getMessage());
            }

            if ($complaint->property_id) { $svc->flagPropertyEvidence($complaint); }

            $sentDate = $approveDate->copy()->addMinutes(1);
            $complaint->update(['status' => 'sent', 'sent_to_ppra_at' => $sentDate, 'updated_at' => $sentDate]);
            $this->audit($complaint, 'emailed_to_ppra', null, $sentDate, ['recipient_to' => 'johan@hfcoastal.co.za', 'demo_mode' => true]);

            if ($targetStatus === 'sent') { $this->command->line("  #{$complaint->id} {$spec['tier']} → sent ({$complaint->subjects()->count()} subjects)"); continue; }

            if ($targetStatus === 'acknowledged_by_ppra') {
                $ackDate = $sentDate->copy()->addDays(rand(3, 10));
                $complaint->update(['status' => 'acknowledged_by_ppra', 'ppra_acknowledged_at' => $ackDate, 'ppra_reference_number' => $spec['ppra_ref'] ?? 'PPRA/2026/' . rand(10000, 99999), 'updated_at' => $ackDate]);
                $this->audit($complaint, 'acknowledged_by_ppra', null, $ackDate, ['ppra_reference' => $complaint->ppra_reference_number]);
                $this->command->line("  #{$complaint->id} {$spec['tier']} → acknowledged ({$complaint->ppra_reference_number}, {$complaint->subjects()->count()} subjects)");
            }
        }

        $count = WhistleblowComplaint::withoutGlobalScopes()->where('agent_notes', 'like', '[DEMO]%')->count();
        $this->command->info("Done. {$count} [DEMO] complaints seeded.");
    }

    private function audit(WhistleblowComplaint $complaint, string $action, ?User $user, Carbon $at, ?array $data = null): void
    {
        WhistleblowAuditLog::create(['complaint_id' => $complaint->id, 'user_id' => $user?->id, 'action' => $action, 'action_data' => $data, 'created_at' => $at]);
    }
}
