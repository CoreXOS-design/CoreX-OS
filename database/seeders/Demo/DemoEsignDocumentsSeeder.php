<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Http\Controllers\Docuperfect\ESignWizardController;
use App\Http\Controllers\Docuperfect\SignatureController;
use App\Http\Controllers\Docuperfect\SigningController;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\Flow;
use App\Models\Docuperfect\SignatureTemplate;
use App\Services\Docuperfect\WebPackSlotResolver;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Seeds a small set of REAL, filed e-sign documents for the demo webinar
 * (Johan, 2026-09-01 — Thursday 10am prospective-agency demo). Before this,
 * demo had zero rows in `documents` and zero genuinely-completed
 * signature_templates — nothing on any property showing "here is the
 * signed mandate on file", which is the entire payoff of the e-sign story.
 *
 * WHY THIS DRIVES THE REAL CONTROLLERS, NOT RAW INSERTS: an earlier,
 * unrelated batch of 27 signature_templates already sat in this DB with
 * status='completed' (stamped directly, never through the real pipeline) —
 * zero `signatures` rows, zero filed `documents` rows, zero PDFs on disk.
 * "Completed" status alone proves nothing; the actual payoff (a real PDF,
 * filed, linked to the property and the seller's contact record) only
 * exists if it went through the genuine signing/completion/filing code
 * path. So this seeder drives App\Http\Controllers\Docuperfect\
 * ESignWizardController / SignatureController / SigningController exactly
 * the way a real agent's browser does — same validation, same canonical
 * compose, same ink baking, same Puppeteer PDF render, same
 * SignatureService::fileSingleDocument() property/contact/deal linking.
 * Those 27 fake rows are left untouched (no hard deletes, not this
 * seeder's job to clean up someone else's prior work).
 *
 * IDEMPOTENCY: each target document has a STABLE, unique display name
 * ("[DEMO-ESIGN] <scenario> — <property address>"). Before touching
 * anything, the seeder checks whether a docuperfect_documents row with
 * that exact name already exists for that property — if so, it is
 * skipped entirely (logged, not silently ignored) and NOTHING is created
 * or re-run for it. A prior partial/crashed run for a scenario is left
 * exactly as it was and reported, never silently duplicated or clobbered
 * — see run()'s summary output. Re-running this seeder is safe.
 */
final class DemoEsignDocumentsSeeder
{
    private const NAME_PREFIX = '[DEMO-ESIGN]';

    /** A tiny valid 1x1 PNG — placeholder "ink" satisfying the completion
     *  floor check. Never presented as a real signature. */
    private const INK_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private ESignWizardController $wizardCtrl;
    private SignatureController $sigCtrl;
    private SigningController $signingCtrl;
    private ?object $command = null;

    public function setCommand($command): void
    {
        $this->command = $command;
    }

    private function info(string $msg): void
    {
        if ($this->command) {
            $this->command->info('    ' . $msg);
        }
    }

    /**
     * @return array{created:int,skipped:int,notes:array<int,string>}
     */
    public function run(int $agencyId): array
    {
        $this->wizardCtrl = app(ESignWizardController::class);
        $this->sigCtrl = app(SignatureController::class);
        $this->signingCtrl = app(SigningController::class);

        $created = 0;
        $skipped = 0;
        $notes = [];

        $scenarios = $this->targetScenarios($agencyId);

        foreach ($scenarios as $scenario) {
            $existingName = self::NAME_PREFIX . ' ' . $scenario['label'] . ' — ' . $scenario['property_address'];
            $exists = \App\Models\Docuperfect\Document::query()
                ->where('property_id', $scenario['property_id'])
                ->where('name', $existingName)
                ->exists();

            if ($exists) {
                $skipped++;
                $notes[] = "SKIPPED (already seeded): {$existingName}";
                continue;
            }

            try {
                $result = $this->driveScenario($scenario, $existingName);
                $created++;
                $notes[] = "CREATED: {$existingName} — {$result}";
                $this->info("e-sign: {$existingName} — {$result}");
            } catch (\Throwable $e) {
                $notes[] = "FAILED: {$existingName} — " . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')';
                Log::error('DemoEsignDocumentsSeeder scenario failed', [
                    'scenario' => $scenario['label'],
                    'property_id' => $scenario['property_id'],
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
                $this->info("e-sign FAILED: {$existingName} — " . $e->getMessage());
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'notes' => $notes];
    }

    /**
     * Every target document for the webinar demo. Contact/property ids are
     * from the existing demo dataset (DemoPropertiesSeeder); this seeder
     * does not create properties or contacts, only e-sign documents against
     * ones that already exist and have no e-sign document yet.
     */
    private function targetScenarios(int $agencyId): array
    {
        return [
            [
                'label' => 'Sole Mandate',
                'stage' => 'completed',
                'property_id' => 16, 'property_address' => '54 Coastal Way, St Michaels-on-Sea',
                'agent_id' => 9, 'seller_contact_id' => 16, 'seller_name' => '[DEMO] Zola Naidoo', 'seller_email' => 'sellerHIJiO@example.com',
                'template_id' => 12, 'is_pack' => false,
            ],
            [
                'label' => 'Mandatory Disclosure',
                'stage' => 'completed',
                'property_id' => 17, 'property_address' => '112 Coastal Way, Port Shepstone',
                'agent_id' => 13, 'seller_contact_id' => 17, 'seller_name' => '[DEMO] Naledi Naidoo', 'seller_email' => 'sellerm6jg8@example.com',
                'template_id' => 2, 'is_pack' => false,
            ],
            [
                'label' => 'Seller Onboarding Pack',
                'stage' => 'completed',
                'property_id' => 18, 'property_address' => '116 Coastal Way, Sea Park',
                'agent_id' => 11, 'seller_contact_id' => 18, 'seller_name' => '[DEMO] Naledi Sibeko', 'seller_email' => 'sellerCsesx@example.com',
                'is_pack' => true, 'pack_id' => 2,
            ],
            [
                'label' => 'Sole Mandate',
                'stage' => 'awaiting_seller',
                'property_id' => 19, 'property_address' => '89 Coastal Way, Manaba Beach',
                'agent_id' => 4, 'seller_contact_id' => 19, 'seller_name' => '[DEMO] Riaan Fourie', 'seller_email' => 'seller5cxDv@example.com',
                'template_id' => 12, 'is_pack' => false,
            ],
            [
                'label' => 'Mandatory Disclosure',
                'stage' => 'ready',
                'property_id' => 20, 'property_address' => '69 Coastal Way, Sea Park',
                'agent_id' => 11, 'seller_contact_id' => 20, 'seller_name' => '[DEMO] Zola Naidoo', 'seller_email' => 'seller6P7r0@example.com',
                'template_id' => 2, 'is_pack' => false,
            ],
            [
                'label' => 'Sole Mandate',
                'stage' => 'pending_agent_approval',
                'property_id' => 21, 'property_address' => '177 Coastal Way, Oslo Beach',
                'agent_id' => 13, 'seller_contact_id' => 21, 'seller_name' => '[DEMO] Pieter Sibeko', 'seller_email' => 'sellernq138@example.com',
                'template_id' => 12, 'is_pack' => false,
            ],
        ];
    }

    private function makeReq(string $uri, string $method, array $payload, User $user): Request
    {
        $req = Request::create($uri, $method, [], [], [], [], json_encode($payload));
        $req->headers->set('Content-Type', 'application/json');
        $req->headers->set('Accept', 'application/json');
        $req->setUserResolver(fn () => $user);
        return $req;
    }

    private function saveStep(int $flowId, int $step, array $data, User $agent, ?string $docName = null): array
    {
        $payload = ['data' => $data];
        if ($docName) {
            $payload['document_name'] = $docName;
        }
        $req = $this->makeReq("/docuperfect/esign/{$flowId}/step/{$step}", 'POST', $payload, $agent);
        $resp = $this->wizardCtrl->saveStep($req, $flowId, $step);
        $body = json_decode($resp->getContent(), true) ?? [];
        if ($resp->getStatusCode() >= 400 || ($body['success'] ?? true) === false) {
            throw new \RuntimeException("saveStep {$step} failed: " . $resp->getContent());
        }
        return $body;
    }

    /**
     * Drives the wizard + (optionally) the full completion pipeline for one
     * scenario, stopping at the requested stage:
     *   'ready'                   — document created, nobody has signed yet
     *   'awaiting_seller'         — agent signed, sent to seller, seller has not signed
     *   'pending_agent_approval'  — agent signed, seller signed, awaiting agent's final approval
     *   'completed'               — fully approved, filed, PDF generated + emailed
     */
    private function driveScenario(array $s, string $docName): string
    {
        $agent = User::withoutGlobalScopes()->findOrFail($s['agent_id']);
        Auth::login($agent);

        // ── Create the flow ──
        $storePayload = $s['is_pack']
            ? ['is_pack_flow' => true, 'pack_id' => $s['pack_id']]
            : ['template_id' => $s['template_id'], 'is_pack_flow' => false];
        $storeReq = $this->makeReq('/docuperfect/esign/store', 'POST', $storePayload, $agent);
        $storeResp = $this->wizardCtrl->store($storeReq, app(WebPackSlotResolver::class));
        $storeData = json_decode($storeResp->getContent(), true) ?? [];
        if ($storeResp->getStatusCode() >= 400) {
            throw new \RuntimeException('esign store failed: ' . $storeResp->getContent());
        }
        $flow = Flow::where('user_id', $agent->id)->where('type', 'esign')->orderByDesc('id')->first();
        $flowId = $flow->id;

        // ── Step 2: property ──
        $this->saveStep($flowId, 2, [
            'address' => $s['property_address'], 'title' => $s['property_address'],
            'suburb' => trim(explode(',', $s['property_address'])[1] ?? ''),
            'erf' => null, 'complex_name' => null, 'property_type' => 'house',
            'property_id' => $s['property_id'], '_property_source' => 'properties',
            'rental_amount' => null, 'deposit_amount' => null, 'commission_percent' => 7.5, 'marketing_fee' => null,
        ], $agent);

        // ── Step 3: recipients (agent + 1 seller) ──
        $this->saveStep($flowId, 3, [
            'recipients' => [
                [
                    'order' => 1, 'role' => 'agent', 'name' => $agent->name,
                    'first_name' => explode(' ', $agent->name)[0] ?? $agent->name,
                    'last_name' => explode(' ', $agent->name, 2)[1] ?? '',
                    'id_number' => '', 'email' => $agent->email, 'cell' => '',
                    'address' => '', '_contact_id' => null, 'readonly' => true,
                ],
                [
                    'order' => 2, 'role' => 'seller', 'name' => $s['seller_name'],
                    'first_name' => explode(' ', $s['seller_name'])[0] ?? $s['seller_name'],
                    'last_name' => explode(' ', $s['seller_name'], 2)[1] ?? '',
                    'id_number' => '8001015800081', 'email' => $s['seller_email'], 'cell' => '0821234567',
                    'address' => $s['property_address'], '_contact_id' => $s['seller_contact_id'],
                ],
            ],
        ], $agent, $docName);

        // ── Step 4: details ──
        $this->saveStep($flowId, 4, [
            'price' => 1850000,
            'mandate_start' => now()->format('Y-m-d'),
            'mandate_expiry' => now()->addMonths(6)->format('Y-m-d'),
            'commission' => 7.5, 'marketing_fee' => null, '_duration' => 6,
        ], $agent);

        // ── Step 5: fill & review (nothing to override) ──
        $this->saveStep($flowId, 5, [
            'fieldValues' => [], 'partyOverrides' => [], 'clauses' => [],
            'other_conditions_text' => '', 'other_condition_frames' => [],
        ], $agent);

        // ── Step 6: signing setup / send ──
        $this->saveStep($flowId, 6, [
            'delivery_mode' => 'esign',
            'parties' => [
                ['signing_order' => 1, 'action' => 'signs_now', 'role' => 'agent', 'name' => $agent->name, 'email' => $agent->email, 'skipEmail' => true, 'fica_required' => false],
                ['signing_order' => 2, 'action' => 'send_after', 'role' => 'seller', 'name' => $s['seller_name'], 'email' => $s['seller_email'], 'skipEmail' => false, 'fica_required' => false],
            ],
        ], $agent);

        // ── prepare-signing: creates Document + SignatureTemplate + SignatureRequests, composes canonical ──
        $prepReq = $this->makeReq("/docuperfect/esign/{$flowId}/prepare-signing", 'POST', [], $agent);
        $prepResp = $this->wizardCtrl->prepareSigning($prepReq, $flowId);
        $prepData = json_decode($prepResp->getContent(), true) ?? [];
        if ($prepResp->getStatusCode() >= 400 || !($prepData['ok'] ?? false)) {
            throw new \RuntimeException('prepareSigning failed: ' . $prepResp->getContent());
        }

        $document = Document::where('name', $docName)->orderByDesc('id')->firstOrFail();
        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();

        if ($s['stage'] === 'ready') {
            return "document {$document->id}, signature_template {$template->id}, status=ready (created, not yet sent)";
        }

        // ── Agent completes their own signing turn ──
        $agentReq = $this->makeReq("/docuperfect/documents/{$document->id}/web-sign-complete", 'POST', [
            'signatures' => ['agent-sig-0' => self::INK_PNG],
            'initials' => [],
            'party_role' => 'agent',
        ], $agent);
        $agentResp = $this->sigCtrl->webSignComplete($agentReq, $document);
        if ($agentResp->getStatusCode() >= 400) {
            throw new \RuntimeException('agent webSignComplete failed: ' . $agentResp->getContent());
        }

        if ($s['stage'] === 'awaiting_seller') {
            $template->refresh();
            return "document {$document->id}, signature_template {$template->id}, status={$template->status} (agent signed, sent to seller)";
        }

        // ── Seller completes their signing turn ──
        $sellerToken = $template->requests()->where('party_role', 'seller')->value('token');
        $sellerReq = $this->makeReq("/sign/{$sellerToken}/complete-web", 'POST', [
            'signatures' => ['seller-sig-0' => self::INK_PNG],
            'initials' => [],
            'field_values' => [
                'seller_address' => $s['property_address'],
                'seller_phone' => '0821234567',
                'seller_email' => $s['seller_email'],
            ],
            'consented' => true,
            'consent_timestamp' => now()->toIso8601String(),
        ], $agent); // user resolver unused by completeWeb (token-authenticated), harmless
        $sellerResp = $this->signingCtrl->completeWeb($sellerReq, $sellerToken);
        if ($sellerResp->getStatusCode() >= 400) {
            throw new \RuntimeException('seller completeWeb failed: ' . $sellerResp->getContent());
        }

        if ($s['stage'] === 'pending_agent_approval') {
            $template->refresh();
            return "document {$document->id}, signature_template {$template->id}, status={$template->status} (awaiting agent counter-approval)";
        }

        // ── Agent approves and finalises — files + generates PDF (may be async; queue worker on this box processes it) ──
        $approveReq = $this->makeReq("/docuperfect/documents/{$document->id}/signatures/approve-and-advance", 'POST', [], $agent);
        $approveResp = $this->sigCtrl->approveAndAdvance($approveReq, $document);
        if ($approveResp->getStatusCode() >= 400) {
            throw new \RuntimeException('approveAndAdvance failed: ' . $approveResp->getContent());
        }

        // Give a real queue worker a moment, in case completion is async and
        // this seeder is running standalone (Bus/Queue not faked).
        $filed = $this->pollForFiledDocument($template->id, attempts: 6, delaySeconds: 1.5);

        // FIX (2026-09-01, cc4's finding on the controlled reset) — when this
        // seeder runs as part of DemoDataSeeder::run(), that method wraps the
        // WHOLE run in Queue::fake()/Bus::fake() (deliberate, belt-and-braces
        // — see its own comment — so no OTHER seeded action fires a real
        // notification/matching job either; not something to weaken
        // globally). With DOCUPERFECT_ASYNC_COMPLETION=true on this agency,
        // SignatureService::completeDocument() dispatches
        // FinalizeSignedDocumentJob to do the actual filing/PDF/finalization-
        // status work — and the fake swallows that dispatch silently. The
        // signature_template ends up 'completed' with NO filed Document, NO
        // PDF, and a blank finalization_status. This reproduced exactly:
        // after a demo:reset, 3 "completed" templates had nothing filed.
        //
        // Fix: if nothing filed within the poll window above (whether
        // because Bus is faked, or a real worker just hasn't gotten to it
        // yet), run the SAME job's real handle() method directly — bypassing
        // Bus/Queue entirely (a direct method call is never intercepted by
        // Bus::fake()/Queue::fake(), unlike dispatch()/dispatchSync(), which
        // both are) — via the container so its SignatureService dependency
        // is resolved exactly as the queue worker would. This is not a
        // reimplementation: it is the real job class's real logic, the same
        // shared recordFinalizationStarted/Succeeded/Failed() pair the
        // synchronous (non-async) completion path already uses — so
        // finalization_status ends up 'succeeded' through the identical code
        // path either way. Safe to call unconditionally when nothing is
        // filed yet: FinalizeSignedDocumentJob is itself idempotent
        // (fileSingleDocument() returns the existing filed Document instead
        // of duplicating one — see that method's own docblock) and a no-op
        // if the template no longer exists.
        if (!$filed) {
            app()->call([new \App\Jobs\Docuperfect\FinalizeSignedDocumentJob($template->id, null), 'handle']);
            $filed = \App\Models\Document::where('source_type', 'esign')->where('source_id', $template->id)->first();
        }

        $template->refresh();
        return $filed
            ? "document {$document->id}, signature_template {$template->id}, status={$template->status}, finalization_status={$template->finalization_status}, FILED as documents.id={$filed->id} ({$filed->storage_path})"
            : "document {$document->id}, signature_template {$template->id}, status={$template->status}, finalization_status={$template->finalization_status}, NOT FILED — investigate";
    }

    private function pollForFiledDocument(int $signatureTemplateId, int $attempts, float $delaySeconds): ?\App\Models\Document
    {
        for ($i = 0; $i < $attempts; $i++) {
            $filed = \App\Models\Document::where('source_type', 'esign')->where('source_id', $signatureTemplateId)->first();
            if ($filed) {
                return $filed;
            }
            usleep((int) ($delaySeconds * 1000000));
        }
        return null;
    }
}
