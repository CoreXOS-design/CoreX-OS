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

    /** @var array<int,array{0:string,1:bool,2:string}> */
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
            $st->requests()->update(['signer_id_number' => '']); // disposable: skip gateway
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
            $mkCond($ordered[0] ?? 'na', 'EATS-ONLY CONDITION ZZZ');
            $mkCond($ordered[1] ?? 'nb', 'MDF-ONLY CONDITION YYY');

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
            $this->assert('3b) condition-initial type tab is editable (typeable — x-model typedName, not readonly/disabled, opens shared modal)', $typeInputEditable && (bool) $condOpensModal, "editableTypeInput=" . ($typeInputEditable ? 'Y' : 'n') . " conditionOpensSharedModal=" . ($condOpensModal ? 'Y' : 'n'));

            // ── DRIVE completions (each party initials every condition, then completes) ──
            $initialConds = function ($token) use ($st) {
                foreach (DocumentCondition::where('signature_template_id', $st->id)->whereNull('deleted_at')->whereNull('superseded_at')->get() as $c) {
                    $r = Request::create('/x', 'POST', ['initial_image' => self::PNG]);
                    app(SigningController::class)->initialCondition($r, $token, $c->id);
                }
            };
            $complete = function ($token, $ceremony) use ($initialConds) {
                $initialConds($token);
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
            $complete($agentReq->token, ['agent_location' => 'AGENT-LOC', 'agent_day' => '03', 'agent_month' => 'Mar', 'agent_year' => '25']);
            $afterAgent = $statusNow();
            $s1->refresh();
            $this->assert('1a) agent clean -> advances to rec1 with NO agent-approval gate', $afterAgent !== $PAA && $s1->status !== SignatureRequest::STATUS_WAITING, "status_after_agent={$afterAgent} rec1={$s1->status}");

            $complete($s1->token, ['seller_location' => 'REC1-LOC', 'seller_day' => '01', 'seller_month' => 'Jan', 'seller_year' => '25']);
            $afterR1 = $statusNow();
            $s2 = $st->requests()->where('party_role', 'seller')->where('role_index', 2)->firstOrFail();
            $this->assert('1b) rec1 clean -> advances to rec2 with NO agent-approval gate', $afterR1 !== $PAA && $s2->status !== SignatureRequest::STATUS_WAITING, "status_after_rec1={$afterR1} rec2={$s2->status}");

            $complete($s2->token, ['seller_2_location' => 'REC2-LOC', 'seller_2_day' => '02', 'seller_2_month' => 'Feb', 'seller_2_year' => '26']);
            $afterR2 = $statusNow();
            $this->assert('2) final recipient clean -> HOLDS at pending_agent_approval (never auto-files)', $afterR2 === $PAA, "status_after_rec2={$afterR2}");

            // ── RULE 4: per-recipient location across ALL docs, correct attribution ──
            $doc->refresh();
            $locs = function (string $party) use ($doc) {
                $c = $doc->web_template_data['canonical_html'] ?? ($doc->web_template_data['merged_html'] ?? '');
                $o = [];
                if (preg_match_all('/<span[^>]*data-marker-party="' . preg_quote($party, '/') . '"[^>]*data-marker-type="location"[^>]*>(.*?)<\/span>/is', $c, $m)) {
                    foreach ($m[1] as $v) {
                        $o[] = trim(strip_tags($v));
                    }
                }
                return $o;
            };
            $sl = $locs('seller');
            $s2l = $locs('seller_2');
            $ok4 = count($sl) > 0 && (new Collection($sl))->every(fn ($v) => $v === 'REC1-LOC')
                && count($s2l) > 0 && (new Collection($s2l))->every(fn ($v) => $v === 'REC2-LOC')
                && ! in_array('REC2-LOC', $sl, true) && ! in_array('REC1-LOC', $s2l, true);
            $this->assert('4) per-recipient location correct across all pack docs (rec1!=rec2, no swap, none missing)', $ok4, "seller=[" . implode('|', $sl) . "] seller_2=[" . implode('|', $s2l) . "]");

            // ── RULE 4b: approve as agent -> final APPROVED doc keeps each recipient's values ──
            $signatureService->approveAndAdvance($st->fresh());
            $doc->refresh();
            $sl2 = $locs('seller');
            $s2l2 = $locs('seller_2');
            $ok4b = count($sl2) > 0 && (new Collection($sl2))->every(fn ($v) => $v === 'REC1-LOC')
                && count($s2l2) > 0 && (new Collection($s2l2))->every(fn ($v) => $v === 'REC2-LOC');
            $this->assert('4b) final APPROVED doc keeps each recipient location across all docs', $ok4b, "approved seller=[" . implode('|', $sl2) . "] seller_2=[" . implode('|', $s2l2) . "] finalStatus=" . $st->fresh()->status);
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
        foreach ($this->results as [$name, $pass, $art]) {
            $this->line(($pass ? '<info>[PASS]</info>' : '<error>[FAIL]</error>') . " {$name}");
            $this->line("        {$art}");
            if (! $pass) {
                $fail++;
            }
        }
        $this->newLine();
        $this->line($fail === 0 ? "<info>ALL " . count($this->results) . " PASS</info> (disposable data cleaned up)" : "<error>{$fail} FAILED</error> (disposable data cleaned up)");

        return $fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function assert(string $name, bool $pass, string $artifact): void
    {
        $this->results[] = [$name, $pass, $artifact];
    }
}
