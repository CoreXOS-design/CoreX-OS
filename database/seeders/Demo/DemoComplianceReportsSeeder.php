<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Compliance\WhistleblowAuditLog;
use App\Models\Compliance\WhistleblowComplaint;
use App\Models\Compliance\WhistleblowComplaintEvidence;
use App\Models\Compliance\WhistleblowComplaintSubject;
use App\Models\Property;
use App\Models\User;
use App\Services\Compliance\WhistleblowComplaintService;
use Carbon\Carbon;

/**
 * Seeds a couple of realistic "compliance reports" — the system's actual
 * name for these is the whistleblower/PPRA-complaint module (Compliance →
 * Compliance Reporting in the sidebar; empty-state text is "No reports filed
 * yet.") — for the demo webinar (Johan, 2026-09-03). Uses the tier/status
 * enums the system already defines; no new report type invented.
 *
 * NOT a reuse of the existing database/seeders/WhistleblowDemoSeeder.php:
 * that seeder (a) force-deletes prior [DEMO] rows on every run — a hard
 * delete, against project policy — and (b) guards on
 * app()->environment('local'), which refuses on this box (APP_ENV=demo).
 * This is a fresh, small, idempotent-via-skip, non-destructive seeder for
 * the demo agency instead. Subject "agency" names below are FICTIONAL third
 * parties being reported (the demo agency itself is never the subject) —
 * none reference Home Finders Coastal / HFC.
 *
 * IDEMPOTENT: keyed on agent_notes LIKE '[DEMO-COMPLIANCE]%' — if any such
 * rows already exist for the agency, this is a no-op.
 */
final class DemoComplianceReportsSeeder
{
    private const TAG = '[DEMO-COMPLIANCE]';

    public function run(int $agencyId): array
    {
        $already = WhistleblowComplaint::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('agent_notes', 'like', self::TAG . '%')
            ->count();

        if ($already > 0) {
            return ['created' => 0, 'skipped' => $already, 'notes' => ["SKIPPED (already seeded): {$already} " . self::TAG . " complaints exist"]];
        }

        $admin = User::withoutGlobalScopes()->where('agency_id', $agencyId)->where('role', 'admin')->first();
        $bm = User::withoutGlobalScopes()->where('agency_id', $agencyId)->where('role', 'branch_manager')->first();
        $agent = User::withoutGlobalScopes()->where('agency_id', $agencyId)->where('role', 'agent')->first();
        if (!$admin) {
            return ['created' => 0, 'skipped' => 0, 'notes' => ['FAILED: no admin user found for agency ' . $agencyId]];
        }

        $properties = Property::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNotNull('address')->limit(2)->get();
        $svc = app(WhistleblowComplaintService::class);

        $reports = [
            [
                'tier' => 'tier_1', 'target_status' => 'sent', 'days_ago' => 25,
                'property_address' => $properties[0]->address ?? '41 Ridge Road, Uvongo',
                'link_property' => true,
                'seller_statement' => 'I never signed a mandate with this agency. They contacted me directly after seeing the property on a portal and told me they already had authority from a family member, which is not true.',
                'agent_notes' => self::TAG . ' Seller confirmed no mandate, no FICA pack, no MDF on file for this agency.',
                'subjects' => [['agency_name' => '[DEMO] Sunset Coast Properties', 'practitioner_name' => 'R. Naicker', 'portal_url' => 'https://demo-portal.example.com/listing/71204', 'portal_source' => 'p24']],
                'reporter' => $agent ?? $admin, 'approver' => $admin,
            ],
            [
                'tier' => 'tier_2', 'target_status' => 'pending_approval', 'days_ago' => 2,
                'property_address' => $properties[1]->address ?? '18 Marine Parade, Ramsgate',
                'link_property' => false,
                'seller_statement' => null,
                'agent_notes' => self::TAG . ' Listing spotted this week — agency name shown on the portal but no FFC number displayed anywhere on the ad.',
                'subjects' => [['agency_name' => '[DEMO] Golden Sands Realty', 'practitioner_name' => 'T. Mabaso', 'portal_url' => 'https://demo-portal.example.com/listing/71355', 'portal_source' => 'pp']],
                'reporter' => $bm ?? $admin, 'approver' => null,
            ],
            [
                'tier' => 'tier_3', 'target_status' => 'acknowledged_by_ppra', 'days_ago' => 60,
                'property_address' => '5 Beacon Rocks Road, Southbroom',
                'link_property' => false,
                'seller_statement' => null,
                'agent_notes' => self::TAG . ' Searched the PPRA public register for both names — zero results. Appears to be operating without registration.',
                'subjects' => [
                    ['agency_name' => '[DEMO] Coastline Unregistered Brokers', 'practitioner_name' => 'J. Fourie', 'portal_url' => 'https://demo-portal.example.com/listing/70880', 'portal_source' => 'p24'],
                    ['agency_name' => '[DEMO] Ocean Breeze Estates (Pty)', 'practitioner_name' => 'M. Radebe', 'portal_url' => 'https://demo-portal.example.com/listing/70881', 'portal_source' => 'pp'],
                ],
                'reporter' => $admin, 'approver' => $admin, 'ppra_ref' => 'PPRA/2026/' . '55214',
            ],
        ];

        $created = 0;
        $notes = [];

        foreach ($reports as $spec) {
            $baseDate = now()->subDays($spec['days_ago']);
            $reporter = $spec['reporter'];
            $approver = $spec['approver'];
            $propertyId = ($spec['link_property'] && isset($properties[0])) ? $properties[0]->id : null;

            $complaint = WhistleblowComplaint::withoutGlobalScopes()->create([
                'agency_id' => $agencyId,
                'branch_id' => $reporter->branch_id,
                'reported_by_user_id' => $reporter->id,
                'tier' => $spec['tier'],
                'property_id' => $propertyId,
                'property_address' => $spec['property_address'],
                'seller_statement' => $spec['seller_statement'],
                'agent_notes' => $spec['agent_notes'],
                'status' => 'draft',
                'created_at' => $baseDate, 'updated_at' => $baseDate,
            ]);

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

            WhistleblowComplaintEvidence::create([
                'complaint_id' => $complaint->id, 'evidence_type' => 'screenshot',
                'file_path' => "docuperfect/demo-placeholders/whistleblow-evidence-{$complaint->id}.png",
                'original_filename' => "evidence-{$complaint->id}.png",
                'mime_type' => 'image/png',
                'size_bytes' => rand(60000, 300000),
                'description' => 'Screenshot of portal listing',
                'uploaded_by_user_id' => $reporter->id,
                'created_at' => $baseDate, 'updated_at' => $baseDate,
            ]);

            $targetStatus = $spec['target_status'];
            $submitDate = $baseDate->copy()->addHours(rand(1, 6));
            $complaint->update(['status' => 'pending_approval', 'updated_at' => $submitDate]);
            $this->audit($complaint, 'submitted', $reporter, $submitDate);

            if ($targetStatus === 'pending_approval') {
                $created++;
                $notes[] = "CREATED: complaint #{$complaint->id} ({$spec['tier']}) -> pending_approval";
                continue;
            }

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
                $notes[] = "  PDF generation failed for #{$complaint->id}: " . $e->getMessage();
            }

            if ($complaint->property_id) {
                $svc->flagPropertyEvidence($complaint);
            }

            $sentDate = $approveDate->copy()->addMinutes(1);
            $complaint->update(['status' => 'sent', 'sent_to_ppra_at' => $sentDate, 'updated_at' => $sentDate]);
            $this->audit($complaint, 'emailed_to_ppra', null, $sentDate, ['demo_mode' => true]);

            if ($targetStatus === 'sent') {
                $created++;
                $notes[] = "CREATED: complaint #{$complaint->id} ({$spec['tier']}) -> sent";
                continue;
            }

            if ($targetStatus === 'acknowledged_by_ppra') {
                $ackDate = $sentDate->copy()->addDays(rand(3, 10));
                $complaint->update([
                    'status' => 'acknowledged_by_ppra',
                    'ppra_acknowledged_at' => $ackDate,
                    'ppra_reference_number' => $spec['ppra_ref'] ?? ('PPRA/2026/' . rand(10000, 99999)),
                    'updated_at' => $ackDate,
                ]);
                $this->audit($complaint, 'acknowledged_by_ppra', null, $ackDate, ['ppra_reference' => $complaint->ppra_reference_number]);
                $created++;
                $notes[] = "CREATED: complaint #{$complaint->id} ({$spec['tier']}) -> acknowledged_by_ppra ({$complaint->ppra_reference_number})";
            }
        }

        return ['created' => $created, 'skipped' => 0, 'notes' => $notes];
    }

    private function audit(WhistleblowComplaint $complaint, string $action, ?User $user, Carbon $at, ?array $data = null): void
    {
        WhistleblowAuditLog::create([
            'complaint_id' => $complaint->id,
            'user_id' => $user?->id,
            'action' => $action,
            'action_data' => $data,
            'created_at' => $at,
        ]);
    }
}
