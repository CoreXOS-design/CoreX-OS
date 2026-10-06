<?php

namespace App\Console\Commands;

use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Signer;
use App\Models\PlatformEsign\WordingVersion;
use App\Models\User;
use App\Services\PlatformEsign\Agreement\AgreementCompany;
use App\Services\PlatformEsign\Agreement\AgreementContent;
use App\Services\PlatformEsign\Agreement\AgreementFidelity;
use App\Services\PlatformEsign\Agreement\AgreementFields;
use App\Services\PlatformEsign\Agreement\AgreementPricing;
use App\Services\PlatformEsign\Agreement\AgreementSample;
use App\Services\PlatformEsign\Agreement\AgreementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Word-for-word proof of what the client sees and signs (spec §11.15) — see AgreementFidelity.
 *
 * Phased so a REAL browser can read the recipient page in the middle (scripts/verify-agreement-wording.sh drives it):
 *   --prepare                      creates a throwaway agreement (mail faked — nothing is sent) and prints its id and recipient URL
 *   --doc=ID --web-text=FILE       compares the browser's text of the recipient page, then the wet-ink PDF; with --seal also signs
 *                                  both sides (mail faked) and compares the sealed PDF; --cleanup retires the throwaway afterwards
 *   --stored                       checks the stored seed version 1.0 against the two source files, and the v1.0 footer
 * Exit code is non-zero when any difference is found.
 */
class PlatformEsignVerifyWording extends Command
{
    protected $signature = 'platform-esign:verify-wording
        {--prepare : create a throwaway agreement and print its recipient URL}
        {--user= : sender user id for --prepare (default: the latest agreement sender)}
        {--pin=1.0 : wording version the throwaway is pinned to for --prepare (the seeded 1.0 by default)}
        {--stored : check the stored seed version against the source files}
        {--doc= : the throwaway agreement id}
        {--web-text= : file holding the browser text of the recipient page (scripts/verify-agreement-web-text.cjs)}
        {--seal : sign both sides (mail faked) and check the sealed PDF}
        {--cleanup : retire the throwaway agreement afterwards}
        {--source-dir= : read the two source files from this folder instead of resources/legal (agreement.md, netcash-mandate.md)}';

    protected $description = 'Word-for-word proof: recipient page, wet-ink PDF and sealed PDF against the signed-off Subscription Agreement text.';

    /** Text the product adds around the signed-off wording on purpose (spec §11) — reported, never silently allowed. */
    private const DECLARED = [
        '/^Contract reference number:( CX\d+)?$/u' => 'Part A: contract reference line under the Part A heading (spec §11.3 — the one addition to Part A)',
        '/^Number of agents Number of branches( .*)?$/u' => 'Recipient web form only: the labels of the two entries (agents, branches) side by side above the fee table (and the >40 agents note)',
    ];

    private int $defects = 0;

    public function handle(AgreementService $svc): int
    {
        if ($this->option('prepare')) {
            return $this->prepare($svc);
        }
        $ok = true;
        if ($this->option('stored')) {
            $ok = $this->stored() && $ok;
        }
        if ($this->option('doc')) {
            $ok = $this->documentChecks($svc) && $ok;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    // ── prepare ────────────────────────────────────────────────────────────

    private function prepare(AgreementService $svc): int
    {
        $uid = (int) ($this->option('user') ?: Document::withoutGlobalScopes()->whereNotNull('created_by')->orderByDesc('id')->value('created_by'));
        Mail::fake();
        $doc = $svc->send(['name' => 'Wording Proof Throwaway', 'email' => 'wording-proof@example.test', 'cell' => '0820000000'], $uid);
        if ($pin = (string) $this->option('pin')) {
            // send() pins the newest PUBLISHED version; the proof is of the seeded text, so pin the throwaway to it explicitly.
            $v = WordingVersion::withTrashed()->where('template_id', $doc->template_id)->where('version', $pin)->first();
            if (!$v) {
                $this->error('No wording version ' . $pin . ' exists here.');

                return self::FAILURE;
            }
            $doc->update(['wording_version_id' => $v->id]);
        }
        $this->line('DOC_ID=' . $doc->id);
        $this->line('URL=' . route('platform-esign.agreement.show', $svc->agencySigner($doc)->token));

        return self::SUCCESS;
    }

    // ── stored seed version ────────────────────────────────────────────────

    private function sourceFiles(): array
    {
        $dir = $this->option('source-dir');
        $a = $dir ? rtrim($dir, '/') . '/agreement.md' : AgreementContent::sourcePath('agreement-v1.0.md');
        $m = $dir ? rtrim($dir, '/') . '/netcash-mandate.md' : AgreementContent::sourcePath('netcash-mandate-v1.0.md');

        return [(string) file_get_contents($a), (string) file_get_contents($m)];
    }

    private function stored(): bool
    {
        $this->info('— Stored seed version 1.0 —');
        [$agr, $man] = $this->sourceFiles();
        $tpl = AgreementContent::template();
        $v = $tpl ? WordingVersion::withTrashed()->where('template_id', $tpl->id)->where('version', AgreementContent::VERSION)->first() : null;
        if (!$v) {
            $this->error('  version 1.0 is not seeded');

            return false;
        }
        $ok = true;
        // 1. The stored content is exactly what the source produces.
        $built = (new AgreementContent)->buildV1();
        $same = $built === array_map(fn ($p) => (string) $p, (array) $v->content_json);
        $this->line('  stored v1.0 content equals the content built from the source files: ' . ($same ? 'YES (identical, every part)' : 'NO'));
        $ok = $same && $ok;
        // 2. Source files in the repo are the signed-off files (when a conductor copy is given).
        if ($this->option('source-dir')) {
            $same2 = file_get_contents(AgreementContent::sourcePath('agreement-v1.0.md')) === $agr && file_get_contents(AgreementContent::sourcePath('netcash-mandate-v1.0.md')) === $man;
            $this->line('  resources/legal source files are byte-identical to ' . $this->option('source-dir') . ': ' . ($same2 ? 'YES' : 'NO'));
            $ok = $same2 && $ok;
        }
        // 3. The stored text read as plain words (tokens = blanks) is the source word for word.
        $plain = fn (array $t) => array_values(array_filter($t, fn ($x) => $x !== AgreementFidelity::BLANK && $x !== AgreementFidelity::BOX));
        $rates = array_merge(AgreementPricing::DEFAULT_RATES, (array) $v->rates_json);
        // a rate token is fixed wording of this version (printed as R295 …); every other token stands where the source had a blank
        $strip = fn (string $md) => preg_replace('/\{\{[^}]*\}\}/', ' ', preg_replace_callback('/\{\{rate:([a-z0-9_]+)\}\}/', fn ($m) => AgreementPricing::rate((float) ($rates[$m[1]] ?? 0)), $md));
        $parts = array_diff_key((array) $v->content_json, ['mandate' => 1]);
        $storedWords = array_merge(AgreementFidelity::fromSource($strip(implode("\n\n", $parts))), AgreementFidelity::fromSource($strip((string) ($v->content_json['mandate'] ?? ''))));
        $r = AgreementFidelity::compare($plain(AgreementFidelity::sourceTokens($agr, $man)), $plain($storedWords));
        [$diffs, $declared] = $this->partition($r['differences']);
        $this->line('  stored words vs source words (blanks and tick boxes left out): ' . count($diffs) . ' differences, ' . $r['matched'] . ' words matched in order');
        foreach ($declared as [$d, $why]) {
            $this->line('  declared addition: "' . $d['actual'] . '" — ' . $why);
        }
        $this->listDiffs($diffs);
        $ok = !$diffs && $ok;
        // 4. Footer wording for v1.0.
        $label = $v->label();
        $this->line('  footer label for v1.0: "' . $label . '"');
        $ok = ($label === 'Version 1.0 — 28 September 2026') && $ok;
        // 5. Which versions exist here.
        $all = WordingVersion::withTrashed()->where('template_id', $tpl->id)->orderBy('id')->pluck('version')->all();
        $this->line('  versions present in this database: ' . implode(', ', $all) . (count($all) === 1 ? ' (only 1.0)' : ' (this database has more than 1.0 — QA1 test data)'));

        return $ok;
    }

    // ── a throwaway document ───────────────────────────────────────────────

    private function documentChecks(AgreementService $svc): bool
    {
        $doc = Document::withoutGlobalScopes()->with(['wording', 'signers'])->findOrFail((int) $this->option('doc'));
        [$agr, $man] = $this->sourceFiles();
        $expected = AgreementFidelity::sourceTokens($agr, $man);
        $this->info('— Document ' . $doc->contract_ref . ' pinned to wording ' . $doc->wording->label() . ' —');
        $ok = true;
        $summary = [];
        try {
            if ($file = $this->option('web-text')) {
                $actual = AgreementFidelity::fromRendered((string) file_get_contents($file));
                $ok = $this->report('RECIPIENT WEB PAGE (real browser)', $expected, $actual, $doc, 'web', $summary) && $ok;
            }

            $signer = $svc->agencySigner($doc);
            $layout = app(\App\Services\PlatformEsign\Agreement\AgreementLayout::class)->ensure($doc->wording);
            $wet = $svc->wetCopy($doc, $signer, null);
            $this->line('  wet-ink footer, page 1: ' . trim(preg_replace('/\s+/', ' ', AgreementFidelity::pdfFooterText($wet))));
            $actual = AgreementFidelity::fromRendered(AgreementFidelity::pdfText($wet, (int) $layout['total']), true);
            $ok = $this->report('WET-INK DOWNLOAD PDF', $expected, $actual, $doc, 'wet', $summary) && $ok;

            if ($this->option('seal')) {
                $ok = $this->seal($svc, $doc, $expected, $summary) && $ok;
            }
        } finally {
            if ($this->option('cleanup')) {
                $this->cleanup($svc, $doc);
            }
        }
        $this->line('');
        $this->info('RESULT — ' . implode(' · ', array_map(fn ($k, $v) => $k . ': ' . $v, array_keys($summary), $summary)));

        return $ok;
    }

    private function seal(AgreementService $svc, Document $doc, array $expected, array &$summary): bool
    {
        Mail::fake();
        $owner = User::withoutGlobalScopes()->findOrFail((int) $doc->created_by);
        $signer = $svc->agencySigner($doc);
        $sample = AgreementSample::ctx($doc->wording);
        $values = $sample['values'] + ['sigA' => $sample['sigs']['agency'], 'sigM' => $sample['sigs']['mandate']];
        $svc->save($doc, $signer, $values, (int) $doc->fresh()->form_rev);
        $svc->setInitials($doc->fresh(), $signer, 'jw');
        $total = $svc->totalPages($doc);
        foreach (range(1, $total) as $p) {
            $svc->initialPage($doc->fresh(), $signer->fresh(), $p, null);
        }
        $err = $svc->submit($doc->fresh(), $signer->fresh(), $values, ['id_number' => '8001015009087', 'consent' => 1], null, 'wording-proof');
        if ($err) {
            $this->error('  could not submit the throwaway: ' . json_encode($err));

            return false;
        }
        $rr = ['rr_name' => 'Firstname Surname', 'rr_capacity' => 'Director', 'rr_place' => 'Town Name', 'rr_date' => '2026-10-07', 'sigR' => $sample['sigs']['rr']];
        $err = $svc->countersign($doc->fresh(), $owner, $rr, 'jr', range(1, $total), null, 'wording-proof');
        if ($err) {
            $this->error('  could not countersign the throwaway: ' . json_encode($err));

            return false;
        }
        $fresh = Document::withoutGlobalScopes()->with(['wording', 'signers'])->findOrFail($doc->id);
        $pdf = $svc->sealedPdf($fresh);
        $layout = app(\App\Services\PlatformEsign\Agreement\AgreementLayout::class)->ensure($fresh->wording);
        $actual = AgreementFidelity::fromRendered(AgreementFidelity::pdfText($pdf, (int) $layout['total']), true);
        $this->line('  sealed PDF footer, page 1: ' . trim(preg_replace('/\s+/', ' ', AgreementFidelity::pdfFooterText($pdf))));

        return $this->report('SEALED PDF (after both parties sign)', $expected, $actual, $fresh, 'sealed', $summary);
    }

    private function cleanup(AgreementService $svc, Document $doc): void
    {
        $doc = Document::withoutGlobalScopes()->find($doc->id);
        if ($doc->status === 'completed') {
            // A signed throwaway cannot be voided (by design) — retire it: soft delete and drop its stored files.
            Storage::disk('local')->deleteDirectory('platform-esign/documents/' . $doc->id);
            $doc->delete();
            $this->line('  throwaway ' . $doc->contract_ref . ' (signed) retired: soft-deleted, stored copy removed');
        } else {
            app(\App\Services\PlatformEsign\EsignService::class)->void($doc, 'Wording proof throwaway', null);
            $this->line('  throwaway ' . $doc->contract_ref . ' voided');
        }
    }

    // ── reporting ──────────────────────────────────────────────────────────

    /** @return bool true when there are no defects */
    private function report(string $title, array $expected, array $actual, Document $doc, string $kind, array &$summary): bool
    {
        $this->line('');
        $this->info('— ' . $title . ' —');
        $r = AgreementFidelity::compare($expected, $actual);
        [$defects, $declared] = $this->partition($r['differences']);
        $this->line('  source words (incl. ' . count(array_filter($expected, fn ($t) => $t === AgreementFidelity::BLANK)) . ' blanks): ' . $r['expected'] . ' · rendered words: ' . $r['actual'] . ' · matched in order: ' . $r['matched']);

        $problems = $this->checkFills($r['fills'], $doc, $kind);
        foreach ($problems as $p) {
            $defects[] = ['at' => -1, 'context' => $p['context'], 'expected' => '(a blank filled only by a field value or the company record)', 'actual' => $p['text'], 'kind' => 'unexplained-fill'];
        }
        $nonEmpty = array_filter($r['fills'], fn ($f) => $f['text'] !== '');
        $this->line('  blanks filled by a value/field: ' . count($nonEmpty));
        foreach ($nonEmpty as $f) {
            $this->line('     ' . '… ' . mb_substr($f['context'], -50) . ' ⟶ ' . mb_substr($f['text'], 0, 90));
        }
        foreach ($declared as [$d, $why]) {
            $this->line('  declared addition: "' . $d['actual'] . '" — ' . $why);
        }
        $this->listDiffs($defects);
        $summary[$title === 'RECIPIENT WEB PAGE (real browser)' ? 'web' : ($kind === 'wet' ? 'wet-ink PDF' : 'sealed PDF')] = count($defects) . ' differences' . ($declared ? ' (+' . count($declared) . ' declared additions)' : '');

        return count($defects) === 0;
    }

    /** @return array{0:array,1:array} [defects, declared additions] */
    private function partition(array $diffs): array
    {
        $defects = [];
        $declared = [];
        foreach ($diffs as $d) {
            $why = null;
            if ($d['kind'] === 'extra' && $d['expected'] === '') {
                foreach (self::DECLARED as $re => $reason) {
                    if (preg_match($re, trim($d['actual']))) {
                        $why = $reason;
                        break;
                    }
                }
            }
            if (!$why && $d['kind'] === 'extra' && $d['expected'] === '' && trim($d['actual']) === '☐' && preg_match('/(Type of Account:|\(cheque\) \/|Savings \/)$/u', $d['context'])) {
                $why = 'Mandate: a tick box before each account type, so the type can be marked on the printed copy (the source lists the three types without boxes)';
            }
            if ($why) {
                $declared[] = [$d, $why];
            } else {
                $defects[] = $d;
            }
        }

        return [$defects, $declared];
    }

    /** Every filled blank must be explained by something the document legitimately holds. @return array<int,array{context:string,text:string}> */
    private function checkFills(array $fills, Document $doc, string $kind): array
    {
        $vocab = $this->vocabulary($doc);
        $bad = [];
        foreach ($fills as $f) {
            if ($f['text'] === '') {
                continue;
            }
            foreach (preg_split('/\s+/u', $f['text']) as $w) {
                if ($w !== '' && $w !== AgreementFidelity::BOX && !isset($vocab[$w])) {
                    $bad[] = $f;
                    break;
                }
            }
        }

        return $bad;
    }

    /** @return array<string,true> */
    private function vocabulary(Document $doc): array
    {
        $words = [];
        $add = function ($s) use (&$words) {
            foreach (AgreementFidelity::words((string) $s) as $w) {
                $words[$w] = true;
            }
        };
        $words['R'] = true; // the rand sign printed before a money value
        $co = AgreementCompany::for($doc);
        foreach ([$co->legalName(), $co->beneficiaryAddress(), $doc->contract_ref] as $s) {
            $add($s);
        }
        foreach ([(array) $doc->form_data, (array) $doc->rr_data] as $data) {
            foreach ($data as $k => $v) {
                if (is_scalar($v) && !str_starts_with((string) $v, 'data:')) {
                    $add($v);
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v)) {
                        $add(date('j F Y', strtotime((string) $v)));
                    }
                }
            }
        }
        foreach (AgreementFields::schema() as $f) {
            foreach ((array) ($f['options'] ?? []) as $label) {
                $add($label);
            }
        }
        $rates = (array) ($doc->wording->rates_json ?? []);
        $calc = app(AgreementService::class)->calc((array) $doc->form_data, (array) $doc->rr_data, $rates);
        foreach ($calc['lines'] as $l) {
            $add(AgreementPricing::number((float) $l['qty']));
            $add(AgreementPricing::number((float) $l['amount']));
        }
        $add(AgreementPricing::number((float) $calc['total']));
        foreach (Signer::where('document_id', $doc->id)->get() as $s) {
            $add($s->initials);
            $add($s->typed_name);
        }
        $d = $doc->signers->firstWhere('role_key', 'r1')?->signed_at ?? now();
        $add($d->format('jS') . ' ' . $d->format('F Y'));

        return $words;
    }

    private function listDiffs(array $diffs): void
    {
        foreach ($diffs as $d) {
            $this->error('  DEFECT [' . $d['kind'] . '] …' . mb_substr($d['context'], -60));
            $this->line('       source  : ' . ($d['expected'] === '' ? '(nothing)' : mb_substr($d['expected'], 0, 200)));
            $this->line('       rendered: ' . ($d['actual'] === '' ? '(nothing)' : mb_substr($d['actual'], 0, 200)));
            $this->defects++;
        }
    }
}
