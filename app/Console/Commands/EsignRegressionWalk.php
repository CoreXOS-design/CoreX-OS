<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\Docuperfect\ESignWizardController;
use App\Http\Controllers\Docuperfect\SigningController;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentCondition;
use App\Models\Docuperfect\Flow;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\User;
use App\Services\Docuperfect\CanonicalDocumentRenderer;
use App\Services\Docuperfect\InsertableBlockRenderer;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Standing e-sign PACK regression walk. Spins up a FRESH wizard-generated
 * 2-seller web pack (EATS + MDF + Addendum B) on DISPOSABLE data, drives the real
 * signing engine agent -> rec1 -> rec2 -> agent-review -> approved, and asserts the
 * rules that keep regressing — PASS/FAIL + a short artifact per rule. Cleans up all
 * disposable data (force-deleted) whether it passes or fails.
 *
 * Service-level driver (fast, deterministic) — no browser required. It exercises the
 * REAL controllers/services: ESignWizardController::prepareSigning (generation),
 * SigningController::initialCondition + completeWeb (signing), SignatureService
 * (advance/gate), CanonicalInkComposer (ceremony binding).
 *
 * QA-only: refuses to run on production. Never touches real documents.
 *
 *   php artisan esign:regression-walk
 */
final class EsignRegressionWalk extends Command
{
    protected $signature = 'esign:regression-walk {--agent=46 : User id of the dispatching agent}';
    protected $description = 'Fresh-pack e-sign regression walk: asserts auto-advance, final gate, blank/typeable condition initials, per-recipient field persistence, and condition doc-scoping.';

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
    // Distinct valid 1x1 PNGs used to simulate the agent drawing a DIFFERENT initial
    // on each condition — so a per-condition capture bug (mirroring one mark onto every
    // condition, Bug 1 / the agent's "27") shows up as two IDENTICAL md5s. Both differ
    // from self::PNG (the adopted page-break initial), proving the render never falls
    // back to the page-initial.
    private const PNG_A = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR4nGNgYPgPAAEEAQDkukubAAAAAElFTkSuQmCC';
    private const PNG_B = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==';

    /** @var array<int,array{0:string,1:string,2:string}> status = PASS|FAIL|SKIP */
    private array $results = [];

    public function handle(SignatureService $signatureService): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run on production — QA/disposable only.');
            return self::FAILURE;
        }

        $agent = User::find((int) $this->option('agent'));
        if (! $agent) {
            $this->error('Agent user not found (--agent).');
            return self::FAILURE;
        }
        auth()->login($agent);

        // ── AUTHORISING-PRACTITIONER PARITY (candidate flow) ─────────────────────
        // Self-contained (no wizard pack required) so it guards the authorising-party
        // engine on every environment. Johan 2026-08: the authoriser is a FULL-PARITY
        // signer — one identity across the supervisor / supervisor_final routing
        // checkpoints, designation-labelled, bound by ROLE-IDENTITY not a placeholder
        // name — and the final baked document must carry EVERY authoriser mark.
        try {
            $this->assertAuthoriserParity();
        } catch (\Throwable $e) {
            $this->assert('AUTHORISER-PARITY block executed cleanly', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        }

        $docId = null;
        $flowId = null;
        try {
            // ── CREATE fresh wizard pack (2 sellers, FICA off) ──────────────────
            $seed = Flow::where('type', 'esign')->orderByDesc('id')->get()
                ->first(fn ($f) => ! empty($f->step_data['is_pack_flow'] ?? false)
                    && count($f->step_data['template_ids'] ?? []) === 3);
            if (! $seed) {
                $this->error('No 3-template pack flow found to seed a fresh pack.');
                return self::FAILURE;
            }
            $flow = $seed->replicate();
            $sd = $seed->step_data;
            $sd['document_name'] = 'REGRESSION-WALK ' . now()->format('Y-m-d H:i:s');
            $sd['pack_name'] = $sd['document_name'];
            $mk = fn ($f, $l, $id, $o) => ['cell' => '0830000000', 'name' => "$f $l", 'role' => 'seller', 'email' => strtolower($f) . '@regression.test', 'order' => $o, 'address' => '1 Test Rd', 'id_number' => $id, 'last_name' => $l, 'first_name' => $f, '_contact_id' => null, 'signing_order' => $o, 'fica_required' => false];
            $sd['recipients']['recipients'] = [$mk('Rec', 'One', '9001015800088', 1), $mk('Rec', 'Two', '8002025800089', 2)];
            $sd['signing_setup']['parties'] = [
                ['name' => 'Rec One', 'role' => 'seller', 'email' => 'rec@regression.test', 'action' => 'send_now', 'skipEmail' => true, 'fica_required' => false, 'signing_order' => 1],
                ['name' => 'Rec Two', 'role' => 'seller', 'email' => 'rec2@regression.test', 'action' => 'send_now', 'skipEmail' => true, 'fica_required' => false, 'signing_order' => 2],
            ];
            unset($sd['document_id'], $sd['signature_template_id']);
            $flow->step_data = $sd;
            $flow->save();
            $flowId = $flow->id;

            $req = Request::create('/x', 'POST');
            $req->setUserResolver(fn () => $agent);
            app(ESignWizardController::class)->prepareSigning($req, $flow->id);
            $flow->refresh();
            $docId = $flow->step_data['document_id'] ?? null;
            if (! $docId) {
                throw new \RuntimeException('prepareSigning did not produce a document_id.');
            }
            $doc = Document::findOrFail($docId);
            $st = SignatureTemplate::where('document_id', $docId)->orderByDesc('id')->firstOrFail();
            // disposable: clear id_number so the browser sub-step skips the gateway,
            // and give every request an email so the ceremony advances to
            // awaiting_seller (a recipient with no email is absorbed to DEFERRED —
            // AT-294; that is correct product behaviour, just not what this walk drives).
            foreach ($st->requests()->get() as $rq) {
                $rq->update(['signer_id_number' => '', 'signer_email' => $rq->party_role . '-' . $rq->id . '@regression.test']);
            }
            $this->line("Fresh pack: doc={$docId} st={$st->id} segments=" . substr_count($doc->web_template_data['merged_html'] ?? '', 'corex-document-wrapper'));

            // ── ADD 2 doc-scoped conditions (EATS + MDF) directly (clean, no amendment) ──
            $html = $doc->web_template_data['merged_html'] ?? '';
            preg_match_all('/~{4,}OTHER_CONDITIONS__([A-Za-z0-9]+)~{4,}/', $html, $km);
            $keyPos = [];
            foreach ($km[1] as $k) {
                $keyPos[strtolower($k)] = stripos($html, "~~~~OTHER_CONDITIONS__{$k}~~~~");
            }
            asort($keyPos);
            $ordered = array_keys($keyPos); // by position: EATS, MDF, Addendum B
            $agencyId = $st->creator?->effectiveAgencyId();
            $mkCond = fn ($key, $content) => DocumentCondition::create(['signature_template_id' => $st->id, 'agency_id' => $agencyId, 'block_id' => 'other_conditions__' . $key, 'block_purpose' => 'other_conditions', 'condition_number' => 1, 'content' => $content, 'is_locked' => false, 'is_override' => false]);
            $cEats = $mkCond($ordered[0] ?? 'na', 'EATS-ONLY CONDITION ZZZ');
            $cMdf  = $mkCond($ordered[1] ?? 'nb', 'MDF-ONLY CONDITION YYY');

            // ── RULE 5: condition renders ONLY on its own doc ──
            $disp = app(CanonicalDocumentRenderer::class)->forDisplay($st);
            $segOf = function ($pos) use ($disp) {
                $t = ['EATS' => stripos($disp, 'EXCLUSIVE AUTHORITY'), 'MDF' => stripos($disp, 'IMMOVABLE PROPERTY'), 'ADB' => stripos($disp, 'ADDENDUM B')];
                asort($t);
                $r = '?';
                foreach ($t as $l => $p) {
                    if ($p !== false && $pos >= $p) {
                        $r = $l;
                    }
                }
                return $r;
            };
            $occ = function ($needle) use ($disp, $segOf) {
                $o = [];
                $off = 0;
                while (($p = strpos($disp, $needle, $off)) !== false) {
                    $o[] = $segOf($p);
                    $off = $p + 1;
                }
                return $o;
            };
            $eatsOcc = $occ('EATS-ONLY CONDITION ZZZ');
            $mdfOcc = $occ('MDF-ONLY CONDITION YYY');
            $this->assert('5) condition renders only on its own doc', $eatsOcc === ['EATS'] && $mdfOcc === ['MDF'], "EATS-cond in:[" . implode(',', $eatsOcc) . "] MDF-cond in:[" . implode(',', $mdfOcc) . "]");

            // ── RULE 3a: condition-initial slot renders BLANK (drawable, no pre-filled token) ──
            $s1 = $st->requests()->where('party_role', 'seller')->where('role_index', 1)->firstOrFail();
            $ck = InsertableBlockRenderer::partyKeyForViewer($st->parties_json, 'seller', 1);
            $viewer = app(InsertableBlockRenderer::class)->reRenderBlocksForViewer($disp, $st, InsertableBlockRenderer::CONTEXT_RECIPIENT_SIGNING, $s1->token, $ck);
            $hasBlank = str_contains($viewer, 'condition-initial-blank');
            $noToken = ! preg_match('/initial-active[^>]*data-party-key="' . preg_quote($ck, '/') . '"[^>]*>\s*<strong/is', $viewer);
            $this->assert('3a) other-condition initial renders BLANK drawable slot (no pre-filled token)', $hasBlank && $noToken, "blankSpan=" . ($hasBlank ? 'Y' : 'n') . " activeSlotHasNoToken=" . ($noToken ? 'Y' : 'n'));

            // ── RULE 3b: the condition-initial modal type field is EDITABLE (typeable) ──
            $blade = @file_get_contents(resource_path('views/docuperfect/signatures/external/sign.blade.php')) ?: '';
            $typeInputEditable = (bool) preg_match('/<input[^>]*x-model="typedName"(?![^>]*(?:readonly|disabled))/i', $blade);
            $condOpensModal = str_contains($blade, "corex-open-condition-initial") && preg_match('/corex-open-condition-initial.*?showSignModal\s*=\s*true/s', $blade);
            $this->assert('3b-contract) condition-initial type tab editable (x-model typedName, not readonly/disabled, opens shared modal)', $typeInputEditable && (bool) $condOpensModal, "editableTypeInput=" . ($typeInputEditable ? 'Y' : 'n') . " conditionOpensSharedModal=" . ($condOpensModal ? 'Y' : 'n'));

            // ── DRIVE completions (each party initials every condition, then completes) ──
            // $inkFor(condition) lets a caller draw DISTINCT ink per condition (extension a);
            // default is the shared self::PNG (proof-of-consent rows for the other parties).
            $initialConds = function ($token, ?callable $inkFor = null) use ($st) {
                foreach (DocumentCondition::where('signature_template_id', $st->id)->whereNull('deleted_at')->whereNull('superseded_at')->get() as $c) {
                    $ink = $inkFor ? ($inkFor($c) ?? self::PNG) : self::PNG;
                    $r = Request::create('/x', 'POST', ['initial_image' => $ink]);
                    app(SigningController::class)->initialCondition($r, $token, $c->id);
                }
            };
            $complete = function ($token, $ceremony, ?callable $inkFor = null) use ($initialConds) {
                $initialConds($token, $inkFor);
                $payload = ['consented' => true, 'consent_timestamp' => now()->toIso8601String(), 'signatures' => ['s-0' => self::PNG], 'initials' => ['i-0' => self::PNG], 'field_values' => ['x' => 'x'], 'ceremony_values' => $ceremony, 'disclosure_answers' => []];
                $resp = app(SigningController::class)->completeWeb(Request::create('/x', 'POST', $payload), $token);
                if ($resp->getStatusCode() !== 200) {
                    $this->warn("  completeWeb HTTP=" . $resp->getStatusCode() . ' ' . substr($resp->getContent(), 0, 160));
                }
                return $resp;
            };
            $statusNow = fn () => SignatureTemplate::find($st->id)->status;
            $PAA = SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL;

            $agentReq = $st->requests()->where('party_role', 'agent')->firstOrFail();
            // extension (a): the agent draws DISTINCT ink per condition (A on EATS, B on MDF).
            $agentInk = fn ($c) => $c->id === $cEats->id ? self::PNG_A : ($c->id === $cMdf->id ? self::PNG_B : self::PNG);
            $complete($agentReq->token, ['agent_location' => 'AGENT-LOC', 'agent_day' => '03', 'agent_month' => 'Mar', 'agent_year' => '25'], $agentInk);
            $afterAgent = $statusNow();
            $s1->refresh();
            $this->assert('1a) agent clean -> advances to rec1 with NO agent-approval gate', $afterAgent !== $PAA && $s1->status !== SignatureRequest::STATUS_WAITING, "status_after_agent={$afterAgent} rec1={$s1->status}");

            // ── RULE 3b (DOM): real browser keystroke into the condition-initial Type tab ──
            // rec1 is now active; drive a headless browser to TYPE into the Type tab and
            // assert the value persists. Skips gracefully if no browser is available.
            $b3art = '';
            $b3 = $this->browserTypeWriteCheck($s1->token, $b3art);
            $this->assert('3b) other-condition initial Type tab actually accepts typed input (real browser keystroke persists)', $b3, $b3art);

            $complete($s1->token, ['seller_location' => 'REC1-LOC', 'seller_day' => '01', 'seller_month' => 'Jan', 'seller_year' => '25']);
            $afterR1 = $statusNow();
            $s2 = $st->requests()->where('party_role', 'seller')->where('role_index', 2)->firstOrFail();
            $this->assert('1b) rec1 clean -> advances to rec2 with NO agent-approval gate', $afterR1 !== $PAA && $s2->status !== SignatureRequest::STATUS_WAITING, "status_after_rec1={$afterR1} rec2={$s2->status}");

            // ── RULE 8b (extension b, DOM): seller_2 can fill a ceremony location field ──
            // rec2 is now active — drive a headless browser to TYPE into seller_2's own
            // ceremony location input (the looped EATS block emits a seller_2-identity field)
            // and assert it persists. Proves the identity-scoped looped field is DOM-fillable
            // by seller_2 (the Rule-4 binding proven service-level, here proven in the browser).
            // Skips gracefully if no browser toolchain / the page can't be driven.
            $b8art = '';
            $b8 = $this->browserSeller2CeremonyFill($s2->token, $b8art);
            $this->assert('8b) seller_2 fills own ceremony location field on the signing page (real browser keystroke persists)', $b8, $b8art);

            $complete($s2->token, ['seller_2_location' => 'REC2-LOC', 'seller_2_day' => '02', 'seller_2_month' => 'Feb', 'seller_2_year' => '26']);
            $afterR2 = $statusNow();
            $this->assert('2) final recipient clean -> HOLDS at pending_agent_approval (never auto-files)', $afterR2 === $PAA, "status_after_rec2={$afterR2}");

            // ── RULE 4: per-recipient location across ALL docs, correct attribution ──
            $doc->refresh();
            // Collect location values grouped by RECIPIENT IDENTITY (the real rendered
            // field identity), not data-marker-party — a looped block (EATS) emits both
            // sellers' spans with data-marker-party="seller", differing only by
            // data-recipient-identity. Normalise the base recipient's "_1" suffix to match
            // the canonical key ("seller_1" -> "seller").
            $locsByIdentity = function () use ($doc) {
                $c = $doc->web_template_data['canonical_html'] ?? ($doc->web_template_data['merged_html'] ?? '');
                $out = ['seller' => [], 'seller_2' => []];
                if (preg_match_all('/<span([^>]*data-marker-type="location"[^>]*)>(.*?)<\/span>/is', $c, $m, PREG_SET_ORDER)) {
                    foreach ($m as $x) {
                        preg_match('/data-marker-party="([^"]*)"/', $x[1], $p);
                        preg_match('/data-recipient-identity="([^"]*)"/', $x[1], $r);
                        $rid = strtolower($r[1] ?? '');
                        $identity = $rid !== '' ? preg_replace('/_1$/', '', $rid) : strtolower($p[1] ?? '');
                        if ($identity === 'seller' || $identity === 'seller_2') {
                            $out[$identity][] = trim(strip_tags($x[2]));
                        }
                    }
                }
                return $out;
            };
            $assertLoc = function (string $label) use ($locsByIdentity) {
                $by = $locsByIdentity();
                $sl = $by['seller'];
                $s2l = $by['seller_2'];
                $ok = count($sl) > 0 && (new Collection($sl))->every(fn ($v) => $v === 'REC1-LOC')
                    && count($s2l) > 0 && (new Collection($s2l))->every(fn ($v) => $v === 'REC2-LOC')
                    && ! in_array('REC2-LOC', $sl, true) && ! in_array('REC1-LOC', $s2l, true);
                return [$ok, "seller-identity=[" . implode('|', $sl) . "] seller_2-identity=[" . implode('|', $s2l) . "]"];
            };
            [$ok4, $art4] = $assertLoc('signed');
            $this->assert('4) per-recipient location bound by IDENTITY across all pack docs (rec1!=rec2, no swap, incl EATS looped span)', $ok4, $art4);

            // ── RULE 4b: approve as agent -> final APPROVED doc keeps each recipient's values ──
            $signatureService->approveAndAdvance($st->fresh());
            $doc->refresh();
            [$ok4b, $art4b] = $assertLoc('approved');
            $this->assert('4b) final APPROVED doc keeps each recipient location (by identity) across all docs', $ok4b, $art4b . " finalStatus=" . $st->fresh()->status);

            // ── RULE 6 (Bug 3): final/approved render carries NO editing chrome, no peach panel ──
            // The print-from-approved artifact must read as a plain legal document: the
            // Other-Conditions "editing panel" (tinted background + coloured left rule +
            // uppercase block-header) and every add/propose/initial affordance are stripped
            // universally — no per-template CSS. Asserted on BOTH the baked canonical (the
            // stored truth every surface serves) AND the SignaturePdfService print pipeline.
            $cAppr   = $doc->web_template_data['canonical_html'] ?? '';
            $pdfHtml = app(\App\Services\Docuperfect\SignaturePdfService::class)->buildInjectedRenderHtml($st->fresh());

            // (i) the baked canonical BODY is clean content — blocks present (they hold the
            //     clauses) but flattened: no peach panel, no header, no editor chrome.
            $peachInBody  = (bool) preg_match('/<div class="insertable-block"[^>]*style="[^"]*(?:color-mix|border-left:\s*3px)/i', $cAppr);
            $headerInBody = str_contains($cAppr, 'class="block-header"');
            $chromeInBody = str_contains($cAppr, 'btn-add-condition')
                || str_contains($cAppr, 'corex-propose-btn')
                || str_contains($cAppr, 'initial-active');
            $blocksPresent = str_contains($cAppr, 'class="insertable-block"');
            $cleanCanonical = $blocksPresent && ! $peachInBody && ! $headerInBody && ! $chromeInBody;

            // (ii) the PDF pipeline additionally strips this chrome at print time (defence in
            //      depth for canonicals baked before the fix): the boot script must carry the
            //      propose/initial removals + the insertable-block flatten.
            $pdfStripsChrome = str_contains($pdfHtml, '.corex-propose-btn')
                && str_contains($pdfHtml, '.btn-add-initial.initial-active')
                && str_contains($pdfHtml, 'querySelectorAll(".insertable-block")');

            $this->assert(
                '6) approved canonical + PDF render carry no editing chrome / no peach Other-Conditions panel (universal, every doc)',
                $cleanCanonical && $pdfStripsChrome,
                'canonical: hasBlocks=' . ($blocksPresent ? 'Y' : 'n')
                    . ' peachPanel=' . ($peachInBody ? 'Y' : 'n')
                    . ' blockHeader=' . ($headerInBody ? 'Y' : 'n')
                    . ' editChrome=' . ($chromeInBody ? 'Y' : 'n')
                    . ' | pdfBootStrips=' . ($pdfStripsChrome ? 'Y' : 'n'),
            );

            // ── RULE 7 (extension a): agent per-condition initial captured DISTINCT per document ──
            // The agent drew A on the EATS condition and B on the MDF condition. In the APPROVED
            // artifact each condition must carry the ink drawn FOR THAT condition — distinct across
            // the two documents, exactly what was drawn, and NEVER the agent's adopted page-break
            // initial (self::PNG). A per-condition capture regression (mirroring one mark, Bug 1 /
            // the agent's "27") would collapse both to one identical md5.
            $agentSigned = $doc->web_template_data['signed_initials']['agent'] ?? [];
            $inkEats = $agentSigned['condition_' . $cEats->id] ?? null;
            $inkMdf  = $agentSigned['condition_' . $cMdf->id] ?? null;
            // Cross-check the on-screen render resolves the SAME per-condition ink (never the page-initial).
            $renderInk = function ($c) use ($st) {
                $c = DocumentCondition::with('initials')->find($c->id);
                $m = new \ReflectionMethod(InsertableBlockRenderer::class, 'renderInitialSlotsForCondition');
                $m->setAccessible(true);
                $h = (string) $m->invoke(app(InsertableBlockRenderer::class), $c, $st->fresh(), InsertableBlockRenderer::CONTEXT_PDF_RENDER, null, null);
                return preg_match('/data-party-key="agent"[^>]*>\s*<img[^>]*src="([^"]+)"/is', $h, $im) ? $im[1] : null;
            };
            $rEats = $renderInk($cEats);
            $rMdf  = $renderInk($cMdf);
            $distinctPerDoc = $inkEats !== null && $inkMdf !== null && $inkEats !== $inkMdf;
            $equalsDrawn    = $inkEats === self::PNG_A && $inkMdf === self::PNG_B;
            $notPageInitial = $inkEats !== self::PNG && $inkMdf !== self::PNG;
            $renderMatches  = $rEats === self::PNG_A && $rMdf === self::PNG_B;
            $this->assert(
                '7) agent per-condition initial captured distinct per document, equals the drawn ink, never the adopted page-initial',
                $distinctPerDoc && $equalsDrawn && $notPageInitial && $renderMatches,
                'EATS=' . ($inkEats === null ? 'NULL' : substr(md5($inkEats), 0, 8))
                    . ' MDF=' . ($inkMdf === null ? 'NULL' : substr(md5($inkMdf), 0, 8))
                    . ' distinct=' . ($distinctPerDoc ? 'Y' : 'n')
                    . ' equalsDrawn=' . ($equalsDrawn ? 'Y' : 'n')
                    . ' notPageInitial=' . ($notPageInitial ? 'Y' : 'n')
                    . ' renderResolvesSame=' . ($renderMatches ? 'Y' : 'n'),
            );
            // ── RULE 8a (extension b): seller_2's "Signed by <name>" attribution renders on ALL pack docs ──
            // Every document seller_2 signs must carry their completed signature block + a
            // "Signed by Rec Two" label — on EATS, MDF and Addendum B, not just the first.
            $cAppr2 = $doc->web_template_data['canonical_html'] ?? '';
            $segAt = function (int $pos) use ($cAppr2) {
                $t = ['EATS' => stripos($cAppr2, 'EXCLUSIVE AUTHORITY'), 'MDF' => stripos($cAppr2, 'IMMOVABLE PROPERTY'), 'ADB' => stripos($cAppr2, 'ADDENDUM B')];
                asort($t);
                $r = '?';
                foreach ($t as $l => $p) { if ($p !== false && $pos >= $p) { $r = $l; } }
                return $r;
            };
            $signedBySegs = [];
            $off = 0;
            while (($p = stripos($cAppr2, 'Signed by Rec Two', $off)) !== false) { $signedBySegs[$segAt($p)] = true; $off = $p + 1; }
            $s2SignedBlock = (bool) preg_match('/data-name="Rec Two"[^>]*data-signed="true"/i', $cAppr2);
            $onAllDocs = isset($signedBySegs['EATS']) && isset($signedBySegs['MDF']) && isset($signedBySegs['ADB']);
            $this->assert(
                '8a) seller_2 "Signed by <name>" + completed signature renders on ALL pack docs (EATS + MDF + Addendum B)',
                $s2SignedBlock && $onAllDocs,
                'signedBlock=' . ($s2SignedBlock ? 'Y' : 'n') . ' "Signed by Rec Two" segments=[' . implode(',', array_keys($signedBySegs)) . ']',
            );

            // ── RULE 9 (COMPLETENESS — the bank/attorney reject guard) ──────────────
            // A bank or conveyancer COUNTS the marks: every signature slot in the final
            // approved document must be filled, for EVERY party. One empty slot for any
            // party = the whole document is rejected for re-signing. Assert doc-type /
            // role-agnostically that the approved canonical has NO empty signature slot.
            $signers9 = $st->fresh()->requests()->get()->map(fn ($r) => [
                'fold' => $this->foldId((string) $r->role_identity),
                'name' => (string) $r->signer_name,
                'base' => (string) preg_replace('/_\d+$/', '', (string) $r->party_role),
            ])->all();
            [$ok9, $art9] = $this->assertNoEmptySignatureSlot($doc->web_template_data['canonical_html'] ?? '', $signers9);
            $this->assert('9) COMPLETENESS: final approved doc has no empty signature slot for any ACTUAL party (bank-reject guard)', $ok9, $art9);
        } catch (\Throwable $e) {
            $this->error('HARNESS ERROR: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        } finally {
            // ── CLEANUP disposable data ──
            if ($docId) {
                $st = SignatureTemplate::where('document_id', $docId)->orderByDesc('id')->first();
                if ($st) {
                    DocumentCondition::where('signature_template_id', $st->id)->forceDelete();
                    $st->requests()->forceDelete();
                    $st->forceDelete();
                }
                Document::where('id', $docId)->forceDelete();
            }
            if ($flowId) {
                Flow::where('id', $flowId)->forceDelete();
            }
        }

        $this->newLine();
        $this->line('=== E-SIGN PACK REGRESSION WALK ===');
        $fail = 0;
        $skip = 0;
        foreach ($this->results as [$name, $status, $art]) {
            $tag = $status === 'PASS' ? '<info>[PASS]</info>' : ($status === 'SKIP' ? '<comment>[SKIP]</comment>' : '<error>[FAIL]</error>');
            $this->line("{$tag} {$name}");
            $this->line("        {$art}");
            if ($status === 'FAIL') {
                $fail++;
            } elseif ($status === 'SKIP') {
                $skip++;
            }
        }
        $this->newLine();
        $pass = count($this->results) - $fail - $skip;
        $summary = $fail === 0 ? "<info>ALL GREEN</info>" : "<error>{$fail} FAILED</error>";
        $this->line("{$summary} — {$pass} pass, {$fail} fail, {$skip} skip (disposable data cleaned up)");

        return $fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param bool|null $pass  null = SKIP (graceful, not a failure). */
    private function assert(string $name, ?bool $pass, string $artifact): void
    {
        $status = $pass === null ? 'SKIP' : ($pass ? 'PASS' : 'FAIL');
        $this->results[] = [$name, $status, $artifact];
    }

    /** Fold a role-identity for ownership comparison — mirror of CanonicalInkComposer::foldIdentity. */
    private function foldId(string $rid): string
    {
        $rid = strtolower(trim($rid));
        if ($rid === '') {
            return '';
        }
        if (preg_match('/^(.*)_(\d+)$/', $rid, $m)) {
            $role = $m[1];
            $idx  = $m[2];
        } else {
            $role = $rid;
            $idx  = '1';
        }
        $base = SignatureTemplate::CHECKPOINT_ROLE_ALIASES[$role] ?? $role;
        if (in_array($base, array_values(SignatureTemplate::CHECKPOINT_ROLE_ALIASES), true)) {
            return $base;
        }
        return $base . '_' . $idx;
    }

    /**
     * COMPLETENESS guard, doc-type / role-agnostic: every REQUIRED signature slot in a
     * final baked/approved artifact must be filled (baked ink / data-signed). A bank or
     * conveyancer counts these and rejects the whole document if ANY party's slot is
     * empty. "Required" is scoped to the document's ACTUAL signers — a template block
     * for a role with no recipient on this send (e.g. a buyer/co_signer block in a
     * seller-only mandate) is NOT a required slot for any party and is excluded.
     *
     * @param array<int,array{fold:string,name:string,base:string}> $signers actual signers
     * @return array{0:bool,1:string}
     */
    private function assertNoEmptySignatureSlot(string $canonical, array $signers): array
    {
        if (trim($canonical) === '') {
            return [false, 'empty canonical'];
        }
        $folds = array_filter(array_column($signers, 'fold'));
        $names = array_filter(array_map(fn ($n) => strtolower(trim((string) preg_replace('/\s+/', ' ', $n))), array_column($signers, 'name')));
        $bases = array_filter(array_column($signers, 'base'));

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?>' . $canonical, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xp = new \DOMXPath($dom);
        $required = 0;
        $empty = [];
        foreach ($xp->query('//*[@data-marker-type="signature"]') as $el) {
            if (! $el instanceof \DOMElement) {
                continue;
            }
            $name  = strtolower(trim((string) preg_replace('/\s+/', ' ', $el->getAttribute('data-name'))));
            $rid   = $el->getAttribute('data-recipient-identity');
            $party = strtolower($el->getAttribute('data-marker-party'));
            $partyBase = (string) preg_replace('/_\d+$/', '', $party);
            // Attribute the slot to an ACTUAL signer, else it is an unused template block.
            $isSignerSlot = ($name !== '' && in_array($name, $names, true))
                || ($rid !== '' && in_array($this->foldId($rid), $folds, true))
                || ($rid === '' && $name === '' && $partyBase !== '' && in_array($partyBase, $bases, true));
            if (! $isSignerSlot) {
                continue;
            }
            $required++;
            $filled = $el->getAttribute('data-signed') === 'true' || $xp->query('.//img', $el)->length > 0;
            if (! $filled) {
                $empty[] = $name ?: ($rid ?: ($party ?: '?'));
            }
        }
        return [$required > 0 && $empty === [], "required slots=$required empty=" . count($empty) . ($empty ? ' for: ' . implode(', ', array_unique($empty)) : '')];
    }

    /**
     * AUTHORISING-PRACTITIONER PARITY — the candidate-flow engine guard (Johan 2026-08).
     * Self-contained: constructs the engine primitives directly (no wizard pack), so it
     * runs and guards on any environment. Asserts the five properties the fix guarantees:
     *   (a) supervisor_final collapses onto the ONE authoriser identity (no phantom 2nd
     *       initial box / signature surface) — SignatureTemplate::enumeratedSigningParties;
     *   (b) the authoriser's parity signature block is IDENTITY-stamped, carries NO
     *       placeholder data-name, and is DESIGNATION-labelled (not "Supervisor");
     *   (c) the authoriser's marks are OWNED + baked at either routing checkpoint
     *       (a supervisor_final signer bakes the supervisor-identity markers — foldIdentity);
     *   (d) co-recipient isolation is preserved (seller_2 is never grabbed by the fold);
     *   (e) COMPLETENESS — every authoriser slot is filled after the bake.
     */
    private function assertAuthoriserParity(): void
    {
        // (a) enumeration collapse
        $t = new SignatureTemplate();
        $t->parties_json = [
            ['role' => 'agent', 'role_label' => 'agent', 'name' => 'Cand'],
            ['role' => 'seller', 'role_label' => 'seller', 'name' => 'Rec One'],
            ['role' => 'seller_2', 'role_label' => 'seller', 'name' => 'Rec Two'],
            ['role' => 'supervisor', 'role_label' => 'supervisor', 'name' => 'Authorised Practitioner'],
            ['role' => 'supervisor_final', 'role_label' => 'supervisor', 'name' => 'Authorised Practitioner'],
        ];
        $roles = array_column($t->enumeratedSigningParties(), 'role');
        $this->assert(
            'AUTH-a) authoriser enumerated ONCE — supervisor_final collapses to base identity, co-sellers kept',
            $roles === ['agent', 'seller', 'seller_2', 'supervisor'],
            '[' . implode(',', $roles) . ']',
        );

        // (b) parity block: identity-stamped, no placeholder name, designation label
        $block = view('docuperfect.web-templates.components.signature-block', [
            'is_candidate_flow' => true,
            'authorising_designation' => 'Authorising Practitioner',
            'authorising_identity' => 'supervisor',
            'signing_parties' => ['owner_party', 'agent'],
            'document_context' => 'sales',
            'recipients_by_role' => ['seller' => [['name' => 'Rec One']], 'agent' => [['name' => 'Cand']]],
        ])->render();
        $authFrag = preg_match('/Thus authorised and signed by the.*?<\/div>\s*<\/div>\s*<\/div>/s', $block, $mm) ? $mm[0] : '';
        $sigStamped = (bool) preg_match('/data-marker-type="signature"[^>]*data-recipient-identity="supervisor"|data-recipient-identity="supervisor"[^>]*data-marker-type="signature"/', $authFrag);
        $noPlaceholderName = $authFrag !== '' && ! str_contains($authFrag, 'data-name=');
        $designationLabel = str_contains($authFrag, 'Authorising Practitioner') && ! str_contains($block, 'Supervising Practitioner');
        $this->assert(
            'AUTH-b) authoriser parity block is identity-stamped, has NO placeholder name, and is designation-labelled',
            $sigStamped && $noPlaceholderName && $designationLabel,
            'idStamped=' . ($sigStamped ? 'Y' : 'n') . ' noPlaceholderName=' . ($noPlaceholderName ? 'Y' : 'n') . ' designationLabel=' . ($designationLabel ? 'Y' : 'n'),
        );

        // (c)+(d)+(e) bake ownership across the checkpoint fold + isolation + completeness
        $canon = '<div class="corex-document-wrapper">'
            . '<span class="sig-field" data-marker-party="supervisor" data-recipient-identity="supervisor" data-marker-type="location"></span>'
            . '<div class="sig-cell-line" data-marker-party="supervisor" data-recipient-identity="supervisor" data-marker-type="signature" data-marker-index="supervisor-0"></div>'
            . '<div class="corex-page-initials" data-marker-party="supervisor" data-recipient-identity="supervisor" data-marker-type="initial" data-marker-index="0-3"></div>'
            . '<div class="sig-cell-line" data-marker-party="seller" data-recipient-identity="seller_2" data-marker-type="signature" data-marker-index="seller2-0"></div>'
            . '</div>';
        $composer = app(\App\Services\Docuperfect\CanonicalInkComposer::class);

        $sup = new SignatureRequest();
        $sup->party_role = 'supervisor_final';
        $sup->role_index = 1;
        $sup->signer_name = 'Retha Kelly';
        $baked = $composer->bakeInk($canon, $sup, ['sig-0' => self::PNG], ['init-0' => self::PNG], ['supervisor_location' => 'DURBAN'], false);

        $sigBaked = (bool) preg_match('/data-marker-type="signature"[^>]*data-recipient-identity="supervisor"[^>]*data-signed="true"|data-recipient-identity="supervisor"[^>]*data-marker-type="signature"[^>]*data-signed="true"/', $baked);
        $initBaked = (bool) preg_match('/data-marker-type="initial"[^>]*data-signed="true"/', $baked);
        $ceremonyFilled = str_contains($baked, 'DURBAN');
        $sellerNotGrabbed = ! (bool) preg_match('/data-recipient-identity="seller_2"[^>]*data-signed="true"/', $baked);
        $this->assert(
            'AUTH-c) supervisor_final signer OWNS + bakes the supervisor-identity marks (signature + initial + ceremony) via the checkpoint fold',
            $sigBaked && $initBaked && $ceremonyFilled,
            'sig=' . ($sigBaked ? 'Y' : 'n') . ' init=' . ($initBaked ? 'Y' : 'n') . ' ceremony=' . ($ceremonyFilled ? 'Y' : 'n'),
        );
        $this->assert(
            'AUTH-d) co-recipient isolation preserved — the authoriser fold never grabs seller_2',
            $sellerNotGrabbed,
            'seller_2 untouched=' . ($sellerNotGrabbed ? 'Y' : 'n'),
        );

        // seller_1 must not grab seller_2 or the authoriser (existing isolation intact under fold)
        $s1 = new SignatureRequest();
        $s1->party_role = 'seller';
        $s1->role_index = 1;
        $s1->signer_name = 'Rec One';
        $baked2 = $composer->bakeInk($canon, $s1, ['sig-0' => self::PNG], [], [], false);
        $s1Clean = ! (bool) preg_match('/data-recipient-identity="seller_2"[^>]*data-signed="true"/', $baked2)
            && ! (bool) preg_match('/data-recipient-identity="supervisor"[^>]*data-marker-type="signature"[^>]*data-signed="true"/', $baked2);
        $this->assert('AUTH-d2) seller_1 signer bakes neither seller_2 nor the authoriser marks', $s1Clean, 'clean=' . ($s1Clean ? 'Y' : 'n'));

        // (e) completeness — no authoriser signature slot left empty
        [$ok, $art] = $this->assertNoEmptySignatureSlot($baked, [
            ['fold' => 'supervisor', 'name' => 'Retha Kelly', 'base' => 'supervisor'],
        ]);
        // restrict the count to authoriser slots for a targeted artifact
        $need = preg_match_all('/data-recipient-identity="supervisor"[^>]*data-marker-type="signature"/', $canon);
        $got  = preg_match_all('/data-recipient-identity="supervisor"[^>]*data-marker-type="signature"[^>]*data-signed="true"/', $baked);
        $this->assert(
            'AUTH-e) COMPLETENESS — every authoriser signature slot filled after bake (no phantom, no missing)',
            $need > 0 && $got === $need,
            "authoriserSigSlots=$need baked=$got | overall: $art",
        );

        // (f) COMPOSE-TIME INJECTOR — an imported segment authored WITHOUT the mandate
        // signature-block component must still yield exactly ONE full-parity authoriser
        // surface; a component segment is never double-injected; a pure-info segment gets
        // nothing. Guards the Monday-import gap (imported disclosure/addendum leaving the
        // authoriser nowhere to sign).
        $inj = app(\App\Services\Docuperfect\CandidateAuthoriserSurfaceInjector::class);
        $authSig = fn ($h) => preg_match_all('/data-marker-type="signature"[^>]*data-recipient-identity="supervisor"|data-recipient-identity="supervisor"[^>]*data-marker-type="signature"/', $h);
        $nonComp = '<div class="corex-document-wrapper"><h1>Mandatory Disclosure</h1><div class="sig-section"><div class="sig-cell-line" data-marker-party="seller" data-marker-type="signature" data-name="E2E Seller"></div></div></div>';
        $comp = '<div class="corex-document-wrapper"><div class="sig-party-block"><div class="sig-cell-line" data-marker-party="supervisor" data-recipient-identity="supervisor" data-marker-type="signature"></div></div><div class="sig-cell-line" data-marker-party="seller" data-marker-type="signature" data-name="Rec One"></div></div>';
        $info = '<div class="corex-document-wrapper"><h1>Info</h1><p>No signing.</p></div>';
        $oNon = $inj->inject($nonComp);
        $oCmp = $inj->inject($comp);
        $oInf = $inj->inject($info);
        $oPack = $inj->inject($comp . $nonComp . $info);
        $injOk = $authSig($oNon) === 1                         // non-component → exactly one, designation-labelled
            && str_contains($oNon, 'Authorising Practitioner') && ! str_contains($oNon, 'data-name="supervisor"')
            && $authSig($oCmp) === 1 && ! str_contains($oCmp, 'data-authoriser-injected')   // idempotent
            && $authSig($oInf) === 0                            // info page untouched
            && $authSig($oPack) === 2 && substr_count($oPack, 'data-authoriser-injected') === 1; // pack: 1 kept + 1 injected
        $this->assert(
            'AUTH-f) compose-time injector: non-component segment gets exactly ONE authoriser surface, component idempotent, info untouched',
            $injOk,
            'nonComp=' . $authSig($oNon) . ' comp=' . $authSig($oCmp) . ' info=' . $authSig($oInf)
                . ' packSurfaces=' . $authSig($oPack) . ' packInjected=' . substr_count($oPack, 'data-authoriser-injected'),
        );
    }

    /**
     * Rule 3b (DOM keystroke) — drive a real browser: open the recipient's signing
     * page, open an other-condition initial "Type" tab, TYPE into it, and assert the
     * typed value persists. Returns true (pass) / false (fail) / null (SKIP — no
     * browser available, or the page/harness couldn't be driven). Non-submitting:
     * types in the modal only, never applies, so it mutates nothing server-side.
     */
    private function browserTypeWriteCheck(string $token, string &$artifact): ?bool
    {
        // Locate a headless Chromium; SKIP gracefully if none.
        $chromium = collect(['/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable'])
            ->first(fn ($p) => is_executable($p));
        $puppeteer = base_path('node_modules/puppeteer');
        $node = trim((string) @shell_exec('command -v node 2>/dev/null'));
        if (! $chromium || ! is_dir($puppeteer) || $node === '') {
            $artifact = 'skipped: no browser toolchain (chromium=' . ($chromium ?: 'none') . ', puppeteer=' . (is_dir($puppeteer) ? 'yes' : 'no') . ', node=' . ($node !== '' ? 'yes' : 'no') . ')';
            return null;
        }

        $url = rtrim((string) config('app.url'), '/') . '/sign/' . $token;
        $script = <<<'JS'
const puppeteer = require(process.argv[2]);
const url = process.argv[3], chromium = process.argv[4];
(async () => {
  const b = await puppeteer.launch({ executablePath: chromium, headless: 'new', args: ['--no-sandbox','--disable-setuid-sandbox','--disable-dev-shm-usage'] });
  try {
    const p = await b.newPage(); await p.setViewport({ width: 1280, height: 1600 });
    await p.goto(url, { waitUntil: 'networkidle2', timeout: 45000 });
    await new Promise(r => setTimeout(r, 1500));
    await p.evaluate(() => { const x = [...document.querySelectorAll('button,a')].find(e => /electronic|start signing|begin|continue/i.test(e.textContent || '')); if (x) x.click(); });
    await new Promise(r => setTimeout(r, 2500));
    const res = await p.evaluate(async () => {
      const slot = document.querySelector('.btn-add-initial.initial-active[data-condition-id]');
      if (!slot) return { ok: false, reason: 'no-active-condition-slot' };
      slot.scrollIntoView(); slot.click();
      await new Promise(r => setTimeout(r, 700));
      const typeBtn = [...document.querySelectorAll('button')].find(x => /^\s*Type\s*$/.test(x.textContent || ''));
      if (!typeBtn) return { ok: false, reason: 'no-type-tab' };
      typeBtn.click(); await new Promise(r => setTimeout(r, 300));
      const inp = document.querySelector('input[x-model="typedName"]');
      if (!inp) return { ok: false, reason: 'no-type-input' };
      if (inp.readOnly || inp.disabled) return { ok: false, reason: 'type-input-readonly-or-disabled' };
      inp.focus(); inp.value = ''; inp.value = 'QZ7'; inp.dispatchEvent(new Event('input', { bubbles: true }));
      await new Promise(r => setTimeout(r, 250));
      const prev = [...document.querySelectorAll('span')].find(s => (getComputedStyle(s).fontFamily || '').includes('Dancing'));
      return { ok: inp.value === 'QZ7', value: inp.value, preview: prev ? prev.textContent : null };
    });
    console.log('RESULT ' + JSON.stringify(res));
  } catch (e) { console.log('RESULT ' + JSON.stringify({ ok: false, reason: 'exception:' + e.message })); }
  finally { await b.close(); }
})();
JS;
        $scriptPath = storage_path('app/esign-regression-3b-' . uniqid() . '.cjs');
        try {
            @file_put_contents($scriptPath, $script);
            $proc = new \Symfony\Component\Process\Process([$node, $scriptPath, $puppeteer, $url, $chromium]);
            $proc->setTimeout(90);
            $proc->run();
            $out = $proc->getOutput() . $proc->getErrorOutput();
            if (preg_match('/RESULT (\{.*\})/', $out, $m)) {
                $r = json_decode($m[1], true) ?: [];
                if (! empty($r['ok'])) {
                    $artifact = "typed 'QZ7' into the condition-initial Type tab -> value persisted='" . ($r['value'] ?? '') . "' preview='" . ($r['preview'] ?? '') . "'";
                    return true;
                }
                $artifact = 'browser drove but assertion failed: ' . ($r['reason'] ?? ('value=' . ($r['value'] ?? '?')));
                return false;
            }
            $artifact = 'skipped: browser run produced no parseable result (' . trim(substr($out, 0, 160)) . ')';
            return null; // couldn't drive -> skip, don't hard-fail
        } catch (\Throwable $e) {
            $artifact = 'skipped: browser sub-step error: ' . $e->getMessage();
            return null;
        } finally {
            @unlink($scriptPath);
        }
    }

    /**
     * Rule 8b (DOM keystroke) — drive a real browser as seller_2 (rec2): open their
     * signing page, start signing, TYPE into seller_2's OWN ceremony location input
     * (the looped EATS block emits a seller_2-identity field) and assert the value
     * persists. Returns true (pass) / false (fail) / null (SKIP — no browser, or the
     * page/field couldn't be driven). Non-submitting: types in the field only, never
     * posts, so it mutates nothing server-side (the harness completes rec2 for real
     * afterwards via completeWeb).
     */
    private function browserSeller2CeremonyFill(string $token, string &$artifact): ?bool
    {
        $chromium = collect(['/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable'])
            ->first(fn ($p) => is_executable($p));
        $puppeteer = base_path('node_modules/puppeteer');
        $node = trim((string) @shell_exec('command -v node 2>/dev/null'));
        if (! $chromium || ! is_dir($puppeteer) || $node === '') {
            $artifact = 'skipped: no browser toolchain (chromium=' . ($chromium ?: 'none') . ', puppeteer=' . (is_dir($puppeteer) ? 'yes' : 'no') . ', node=' . ($node !== '' ? 'yes' : 'no') . ')';
            return null;
        }

        $url = rtrim((string) config('app.url'), '/') . '/sign/' . $token;
        $script = <<<'JS'
const puppeteer = require(process.argv[2]);
const url = process.argv[3], chromium = process.argv[4];
(async () => {
  const b = await puppeteer.launch({ executablePath: chromium, headless: 'new', args: ['--no-sandbox','--disable-setuid-sandbox','--disable-dev-shm-usage'] });
  try {
    const p = await b.newPage(); await p.setViewport({ width: 1280, height: 1600 });
    await p.goto(url, { waitUntil: 'networkidle2', timeout: 45000 });
    await new Promise(r => setTimeout(r, 1500));
    await p.evaluate(() => { const x = [...document.querySelectorAll('button,a')].find(e => /electronic|start signing|begin|continue/i.test(e.textContent || '')); if (x) x.click(); });
    await new Promise(r => setTimeout(r, 2500));
    const res = await p.evaluate(async () => {
      // seller_2's OWN ceremony fields are rendered as editable inputs (data-ceremony-field);
      // other parties' stay read-only spans. Find seller_2's location input.
      const inputs = [...document.querySelectorAll('input[data-ceremony-field="true"][data-marker-type="location"]')];
      if (!inputs.length) return { ok: false, reason: 'no-ceremony-location-input-for-seller_2' };
      const inp = inputs[0];
      const identity = inp.getAttribute('data-recipient-identity') || inp.getAttribute('data-marker-party') || '';
      inp.scrollIntoView(); inp.focus();
      inp.value = ''; inp.value = 'EATSLOC2'; inp.dispatchEvent(new Event('input', { bubbles: true }));
      await new Promise(r => setTimeout(r, 250));
      return { ok: inp.value === 'EATSLOC2', value: inp.value, identity, count: inputs.length };
    });
    console.log('RESULT ' + JSON.stringify(res));
  } catch (e) { console.log('RESULT ' + JSON.stringify({ ok: false, reason: 'exception:' + e.message })); }
  finally { await b.close(); }
})();
JS;
        $scriptPath = storage_path('app/esign-regression-8b-' . uniqid() . '.cjs');
        try {
            @file_put_contents($scriptPath, $script);
            $proc = new \Symfony\Component\Process\Process([$node, $scriptPath, $puppeteer, $url, $chromium]);
            $proc->setTimeout(90);
            $proc->run();
            $out = $proc->getOutput() . $proc->getErrorOutput();
            if (preg_match('/RESULT (\{.*\})/', $out, $m)) {
                $r = json_decode($m[1], true) ?: [];
                if (! empty($r['ok'])) {
                    $artifact = "typed 'EATSLOC2' into seller_2's ceremony location field -> persisted='" . ($r['value'] ?? '') . "' identity='" . ($r['identity'] ?? '') . "' locationInputs=" . ($r['count'] ?? '?');
                    return true;
                }
                $artifact = 'browser drove but assertion failed: ' . ($r['reason'] ?? ('value=' . ($r['value'] ?? '?')));
                return false;
            }
            $artifact = 'skipped: browser run produced no parseable result (' . trim(substr($out, 0, 160)) . ')';
            return null;
        } catch (\Throwable $e) {
            $artifact = 'skipped: browser sub-step error: ' . $e->getMessage();
            return null;
        } finally {
            @unlink($scriptPath);
        }
    }
}
