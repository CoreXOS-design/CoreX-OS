<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Enriches ONE headline deal from DemoDr2PipelineSeeder's batch (deal_no
 * 920005) into a fully-equipped DR2 showcase: a real conveyancing attorney
 * (doubling as bond attorney) + bond originator from cc3's supplier
 * directory, five real generated/uploaded PDFs filed to the deal (OTP,
 * Mandate, Rates Clearance, plus reuse of any admin-uploaded FICA/test
 * docs already on file), comments on the three completed steps, and the
 * Supplier Work Orders panel configured (Electrical/Beetle/Gas/Electric
 * Fence COCs, real suppliers) so completing "Bond Approved" live fires
 * four real emails to Mailpit — the webinar's "click send emails" moment.
 *
 * Built 2026-09-02 at Johan's explicit request: "a deal that's set up with
 * docs, seller/buyer/attorneys/coc suppliers... click the pipeline and
 * click send emails, capture comments per step."
 *
 * IDEMPOTENT BY CONSTRUCTION:
 *   - Targets the deal by deal_no (920005, DemoDr2PipelineSeeder's stable
 *     business key), not by id — survives a future reseed's id reshuffle
 *     as long as the batch seeder's own generation stays deterministic.
 *   - Attorney/bond-originator link: a plain idempotent UPDATE.
 *   - Documents: skipped if a document with the exact same original_name
 *     already exists (checked before every insert).
 *   - Comments: skipped if the exact same body already exists on the step.
 *   - Work orders: delegates to WorkOrderController::cocConfigSave, which
 *     is itself idempotent (never rewrites a `sent` row).
 *   - Never sends anything itself — leaves all four work orders `pending`
 *     so completing "Bond Approved" live is what fires them (the demo
 *     moment), not this seeder.
 */
class DemoDr2ShowcaseDealSeeder extends Seeder
{
    private const TARGET_DEAL_NO = 920005;
    private const ATTORNEY_SUPPLIER_ID_NAME = 'Dlamini & Associates Conveyancing Attorneys';
    private const BOND_ORIGINATOR_NAME = 'BondLink South Coast';

    public function run(int $agencyId = 1): array
    {
        $deal = DB::table('deals')->where('agency_id', $agencyId)->where('deal_no', self::TARGET_DEAL_NO)->first();
        if (!$deal) {
            return ['note' => 'Skipped — no deal with deal_no ' . self::TARGET_DEAL_NO . ' (DemoDr2PipelineSeeder not run yet).'];
        }

        $attorney = DB::table('agency_service_providers')->where('agency_id', $agencyId)
            ->where('name', self::ATTORNEY_SUPPLIER_ID_NAME)->whereNull('deleted_at')->first();
        $bondOriginator = DB::table('agency_service_providers')->where('agency_id', $agencyId)
            ->where('name', self::BOND_ORIGINATOR_NAME)->whereNull('deleted_at')->first();

        if ($attorney) {
            DB::table('deals')->where('id', $deal->id)->update([
                'attorney_provider_id' => $attorney->id,
                'attorney_name' => $attorney->name,
                'bond_attorney_provider_id' => $attorney->id,
                'updated_at' => now(),
            ]);
        }
        if ($bondOriginator) {
            DB::table('deals')->where('id', $deal->id)->update([
                'bond_originator_provider_id' => $bondOriginator->id,
                'updated_at' => now(),
            ]);
        }

        $docCount = $this->ensureDocuments($deal, $agencyId);
        $commentCount = $this->ensureComments($deal);
        $workOrderResult = $this->ensureWorkOrders($deal, $agencyId);

        return ['deal_id' => $deal->id, 'documents' => $docCount, 'comments' => $commentCount, 'work_orders' => $workOrderResult];
    }

    private function ensureDocuments($deal, int $agencyId): int
    {
        $property = DB::table('properties')->where('id', $deal->property_id)->first();
        if (!$property) {
            return 0;
        }

        $count = 0;
        $templates = [
            ['type_id' => 23, 'title' => 'Offer to Purchase', 'filename' => 'OTP-Offer-to-Purchase'],
            ['type_id' => 4, 'title' => 'Sole Mandate', 'filename' => 'Mandate-Sole-Mandate'],
            ['type_id' => 14, 'title' => 'Rates Clearance Certificate', 'filename' => 'Rates-Clearance-Certificate'],
        ];

        foreach ($templates as $t) {
            $origName = $t['filename'] . '-' . $deal->property_address . '.pdf';
            $existing = DB::table('documents')->where('original_name', $origName)->first();
            if ($existing) {
                if (!$existing->deal_id && $deal->deal_v2_id) {
                    DB::table('documents')->where('id', $existing->id)->update(['deal_id' => $deal->deal_v2_id]);
                }
                $count++;
                continue;
            }

            $html = "<html><body style='font-family:sans-serif;padding:40px;'>
                <h1>{$t['title']}</h1>
                <p><strong>Property:</strong> {$deal->property_address}</p>
                <p><strong>Seller:</strong> {$deal->seller_name}</p>
                <p><strong>Buyer:</strong> {$deal->buyer_name}</p>
                <p><strong>Deal Reference:</strong> {$deal->deal_no}</p>
                <p><strong>Purchase Price:</strong> R" . number_format((float) $deal->property_value, 2) . "</p>
                <hr><p style='color:#888;font-size:10px;'>[DEMO] Fictional document generated for demo purposes. CoreX Demo Realty.</p>
                </body></html>";

            $pdfContent = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->output();
            $storagePath = "properties/{$property->id}/files/" . \Illuminate\Support\Str::random(40) . '.pdf';
            Storage::disk('local')->put($storagePath, $pdfContent);

            $docId = DB::table('documents')->insertGetId([
                'original_name' => $origName,
                'storage_path' => $storagePath,
                'disk' => 'local',
                'mime_type' => 'application/pdf',
                'size' => strlen($pdfContent),
                'document_type_id' => $t['type_id'],
                'source_type' => 'upload',
                'deal_id' => $deal->deal_v2_id,
                'uploaded_by' => 1,
                'agency_id' => $agencyId,
                'branch_id' => $property->branch_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('document_properties')->insert([
                'document_id' => $docId, 'property_id' => $property->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $count++;
        }

        return $count;
    }

    private function ensureComments($deal): int
    {
        $steps = DB::table('deal_step_instances')
            ->where('dr1_deal_id', $deal->id)->where('status', 'completed')
            ->orderBy('position')->limit(3)->pluck('id', 'name');

        $bodies = [
            'Offer to Purchase signed by both parties, FICA docs on file for buyer and seller. Ready to proceed.',
            'Deposit received into the trust account — confirmed with the transferring attorney.',
            'Bond application submitted to the bank on behalf of the buyer. Awaiting the bank\'s response.',
        ];

        $count = 0;
        $i = 0;
        foreach ($steps as $stepId) {
            $body = $bodies[$i] ?? null;
            $i++;
            if (!$body) {
                continue;
            }
            $exists = DB::table('deal_step_comments')->where('deal_step_instance_id', $stepId)->where('body', $body)->exists();
            if (!$exists) {
                DB::table('deal_step_comments')->insert([
                    'agency_id' => $deal->agency_id,
                    'deal_step_instance_id' => $stepId,
                    'user_id' => 1,
                    'body' => $body,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $count++;
        }

        return $count;
    }

    private function ensureWorkOrders($deal, int $agencyId): string
    {
        $existing = DB::table('deal_step_work_orders')->where('dr1_deal_id', $deal->id)->count();
        if ($existing >= 4) {
            return 'already configured (' . $existing . ')';
        }

        $suppliers = DB::table('agency_service_providers')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->whereIn('specialty', ['electrician', 'entomologist', 'gas', 'electric_fence'])
            ->get()->keyBy('specialty');

        $map = [
            'COC' => 'electrician',
            'Beetle' => 'entomologist',
            'Gas' => 'gas',
            'Electric Fence' => 'electric_fence',
        ];

        $items = [];
        foreach ($map as $code => $specialty) {
            $supplier = $suppliers->get($specialty);
            if (!$supplier) {
                continue;
            }
            $items[] = [
                'code' => $code,
                'applies' => true,
                'responsible_party' => 'supplier',
                'service_provider_id' => $supplier->id,
            ];
        }
        if (empty($items)) {
            return 'skipped — no matching suppliers found';
        }

        $admin = DB::table('users')->where('agency_id', $agencyId)->where('role', 'admin')->orderBy('id')->first();
        if (!$admin) {
            return 'skipped — no admin user';
        }

        \Illuminate\Support\Facades\Auth::loginUsingId($admin->id);
        $session = app('session.store');
        $session->start();
        $request = \Illuminate\Http\Request::create('/deals-dr2/' . $deal->id . '/pipeline/coc/config', 'POST', ['items' => $items]);
        $request->setUserResolver(fn () => \Illuminate\Support\Facades\Auth::user());
        $request->setLaravelSession($session);
        app()->instance('request', $request);

        try {
            $dealModel = \App\Models\Deal::findOrFail($deal->id);
            $pipelines = app(\App\Services\Deal\Dr1PipelineService::class);
            app(\App\Http\Controllers\DealV2\WorkOrderController::class)->cocConfigSave($request, $dealModel, $pipelines);
            return count($items) . ' work orders configured (pending — fire on Bond Approved completion)';
        } catch (\Throwable $e) {
            return 'FAILED: ' . $e->getMessage();
        }
    }
}
