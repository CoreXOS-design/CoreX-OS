<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Readiness;

use App\Models\Agency;
use App\Models\Docuperfect\FieldGroup;
use App\Models\Docuperfect\NamedField;
use App\Models\Docuperfect\Template;
use App\Models\RentalLeaseTemplate;
use App\Services\Docuperfect\TemplateTransfer\TemplateContentSafety;
use App\Services\Rentals\LeaseAgreementTemplateGuard;
use App\Support\Docuperfect\SignatureBlock;
use App\Support\Documents\AgencyLetterhead;
use Illuminate\Support\Facades\View;

/**
 * .ai/specs/esign-template-readiness.md — the read-only check of ONE template: "can this be used for a real signing, and if
 * not, what exactly is wrong?". It reads the template and the guards that already exist, writes nothing, and never throws:
 * a column that is null, double-encoded or missing is treated as empty and the result says so (prevent-or-absorb).
 *
 * forTemplate() returns ['verdict' => not_ready|ready_with_warnings|ready, 'counts' => [fail, warn, info, pass],
 * 'checks' => list of ['id','title','status','detail','action','items'] in id order]. Callers that show it put failing
 * and warning checks first (see sorted()).
 */
final class TemplateReadinessReport
{
    public const PASS = 'pass';
    public const WARN = 'warn';
    public const FAIL = 'fail';
    public const INFO = 'info';

    public const VERDICT_NOT_READY = 'not_ready';
    public const VERDICT_WARNINGS = 'ready_with_warnings';
    public const VERDICT_READY = 'ready';

    private const FRAME_TOKENS = ['PROPERTY', 'SURNAME', 'YEAR', 'PAGE'];
    private const MAX_ITEMS = 25;

    /** Tokens a template may use for a signer, folded to one name so "Lessor" and "owner_party" are the same signer. */
    private const SIGNER_ALIASES = [
        'lessor' => 'owner_party', 'landlord' => 'owner_party', 'owner' => 'owner_party', 'owner_party' => 'owner_party', 'seller' => 'owner_party',
        'lessee' => 'acquiring_party', 'tenant' => 'acquiring_party', 'acquiring_party' => 'acquiring_party', 'buyer' => 'acquiring_party', 'purchaser' => 'acquiring_party',
        'agent' => 'agent',
    ];

    /** @return array{verdict:string, counts:array<string,int>, checks:array<int,array<string,mixed>>} */
    public function forTemplate(Template $t): array
    {
        $ctx = $this->context($t);
        $checks = [];
        foreach ([
            'r01' => fn () => $this->r01Wording($t, $ctx),
            'r02' => fn () => $this->r02PageFile($t, $ctx),
            'r03' => fn () => $this->r03Signers($t, $ctx),
            'r04' => fn () => $this->r04SignersHavePlaces($t, $ctx),
            'r05' => fn () => $this->r05PlacesNameSigners($t, $ctx),
            'r06' => fn () => $this->r06Initials($t, $ctx),
            'r07' => fn () => $this->r07Blanks($t, $ctx),
            'r08' => fn () => $this->r08LinkedFieldsExist($t, $ctx),
            'r09' => fn () => $this->r09TypedByHand($t, $ctx),
            'r10' => fn () => $this->r10HeaderFooter($t, $ctx),
            'r11' => fn () => $this->r11ImporterLoss($t, $ctx),
            'r12' => fn () => $this->r12TypeAndEsign($t, $ctx),
            'r13' => fn () => $this->r13Letterhead($t, $ctx),
            'r14' => fn () => $this->r14Lease($t, $ctx),
            'r15' => fn () => $this->r15PackageAccepted($t, $ctx),
            'r16' => fn () => $this->r16Archived($t, $ctx),
            'r17' => fn () => $this->r17PageCount($t, $ctx),
        ] as $id => $run) {
            try {
                $checks[] = $run();
            } catch (\Throwable $e) {
                // A check that cannot run is reported, never allowed to take the report down.
                $checks[] = $this->check(strtoupper($id), 'Check could not run', self::INFO, 'This check could not be completed for this template.', 'Tell CoreX support if this keeps showing.');
            }
        }

        $counts = [self::FAIL => 0, self::WARN => 0, self::INFO => 0, self::PASS => 0];
        foreach ($checks as $c) {
            $counts[$c['status']]++;
        }
        $verdict = $counts[self::FAIL] > 0 ? self::VERDICT_NOT_READY : ($counts[self::WARN] > 0 ? self::VERDICT_WARNINGS : self::VERDICT_READY);

        return ['verdict' => $verdict, 'counts' => $counts, 'checks' => $checks];
    }

    /** Failing checks first, then warnings, then the rest (stable within a status). */
    public static function sorted(array $checks): array
    {
        $rank = [self::FAIL => 0, self::WARN => 1, self::INFO => 2, self::PASS => 3];
        usort($checks, fn ($a, $b) => [$rank[$a['status']], $a['id']] <=> [$rank[$b['status']], $b['id']]);

        return $checks;
    }

    // ───────────────────────────── data ─────────────────────────────

    /** Everything the checks share, read once and decoded defensively (also used by the demo view for its places and fields tables). */
    public function context(Template $t): array
    {
        $editor = $this->col($t->getRawOriginal('editor_state'));
        $cds = $this->col($t->getRawOriginal('cds_json'));
        $mappings = is_array($editor['mappings'] ?? null) ? $editor['mappings'] : $this->col($t->getRawOriginal('field_mappings'));
        $tags = is_array($editor['tags'] ?? null) ? array_values($editor['tags']) : [];
        $declared = [];
        foreach ((array) $this->col($t->getRawOriginal('signing_parties'), true) as $p) {
            if (is_string($p) && trim($p) !== '') {
                $declared[$this->signer($p)] = $p;
            }
        }

        // Every signature / initial place and the signer name(s) it carries.
        $places = [];
        foreach ($tags as $i => $tag) {
            $type = (string) ($tag['type'] ?? '');
            if (! in_array($type, ['signature', 'initial'], true)) {
                continue;
            }
            $m = $mappings[$tag['id'] ?? ''] ?? [];
            $names = array_values(array_filter((array) ($m['parties'] ?? []), fn ($n) => is_string($n) && trim($n) !== ''));
            $places[] = ['kind' => $type, 'ref' => '#' . ($i + 1), 'names' => $names];
        }
        foreach ($t->signatureZones()->get() as $z) {
            $places[] = ['kind' => (string) ($z->type ?: 'signature'), 'ref' => 'page ' . ((int) $z->page_index + 1), 'names' => array_values(array_filter((array) ($z->assigned_parties ?? []), 'is_string'))];
        }
        foreach ((array) ($cds['sections'] ?? []) as $s) {
            if (is_array($s) && in_array(($s['type'] ?? ''), ['signature_section', 'inline_signature'], true)) {
                $names = [];
                foreach ((array) ($s['parties'] ?? []) as $p) {
                    if (is_array($p) && is_string($p['role'] ?? null)) {
                        $names[] = $p['role'];
                    }
                }
                $places[] = ['kind' => 'signature', 'ref' => 'signature section', 'names' => $names];
            }
        }
        foreach ((array) $this->col($t->getRawOriginal('fields_json'), true) as $f) {
            if (is_array($f) && in_array(($f['type'] ?? null), ['signature', 'initial'], true)) {
                $n = $f['assignedTo'] ?? $f['party'] ?? $f['role'] ?? null;
                $places[] = ['kind' => (string) $f['type'], 'ref' => (string) ($f['label'] ?? 'field'), 'names' => is_string($n) ? [$n] : []];
            }
        }

        $block = SignatureBlock::resolve($editor['signature_block'] ?? null, is_string($editor['tagged_html'] ?? null) ? $editor['tagged_html'] : null);

        return compact('editor', 'cds', 'mappings', 'tags', 'declared', 'places', 'block');
    }

    /** Decode a raw JSON column; tolerates null, an array, a JSON string and a double-encoded JSON string. */
    private function col(mixed $raw, bool $list = false): array
    {
        $v = $raw;
        for ($i = 0; $i < 3 && is_string($v); $i++) {
            $d = json_decode($v, true);
            if ($d === null && json_last_error() !== JSON_ERROR_NONE) {
                break;
            }
            $v = $d;
        }

        return is_array($v) ? $v : [];
    }

    private function signer(string $token): string
    {
        $k = preg_replace('/_\d+$/', '', str_replace([' ', '-'], '_', mb_strtolower(trim($token))));

        return self::SIGNER_ALIASES[$k] ?? $k;
    }

    private function check(string $id, string $title, string $status, string $detail, string $action = '', array $items = []): array
    {
        $more = max(0, count($items) - self::MAX_ITEMS);
        $items = array_slice(array_values($items), 0, self::MAX_ITEMS);
        if ($more > 0) {
            $items[] = "and {$more} more";
        }

        return compact('id', 'title', 'status', 'detail', 'action', 'items');
    }

    private function isWeb(Template $t): bool
    {
        return (string) $t->render_type !== 'pdf';
    }

    /** Blanks = fill-in tags (anything that is not a signature or initial place), numbered in document order. */
    private function blanks(array $ctx): array
    {
        $out = [];
        foreach ($ctx['tags'] as $i => $tag) {
            if (! in_array((string) ($tag['type'] ?? ''), ['signature', 'initial'], true)) {
                $out[] = ['n' => $i + 1, 'tag' => $tag, 'map' => $ctx['mappings'][$tag['id'] ?? ''] ?? null];
            }
        }

        return $out;
    }

    private function blankLabel(array $b): string
    {
        $l = trim((string) ($b['map']['label'] ?? $b['map']['manualLabel'] ?? $b['tag']['label'] ?? ''));

        return '#' . $b['n'] . ($l !== '' ? ' ' . $l : '');
    }

    // ───────────────────────────── checks ─────────────────────────────

    private function r01Wording(Template $t, array $c): array
    {
        if (! $this->isWeb($t)) {
            return (int) $t->page_count > 0
                ? $this->check('R01', 'The document has wording', self::PASS, 'The template has its document content.')
                : $this->check('R01', 'The document has wording', self::FAIL, 'There is no document content saved on this template.', 'Open it in the builder and save it once.');
        }
        // The package exporter moves the saved wording (sections or tagged text), never the generated page file: a template that has only the
        // page file reads fine here but is refused at release ("cannot be exported"), so it is not ready.
        $saved = ! empty($c['cds']['sections']) || (is_string($c['editor']['tagged_html'] ?? null) && trim((string) $c['editor']['tagged_html']) !== '');
        if ($saved) {
            return $this->check('R01', 'The document has wording', self::PASS, 'The template has its document content.');
        }
        $pageOnly = ! empty($t->blade_view) || ! empty($this->col($t->getRawOriginal('sections'), true));

        return $this->check(
            'R01', 'The document has wording', self::FAIL,
            $pageOnly ? 'The wording is held only in a generated page file, so the template cannot be moved to an agency.' : 'There is no document content saved on this template.',
            'Open it in the builder and save it once.'
        );
    }

    private function r02PageFile(Template $t, array $c): array
    {
        if (! $this->isWeb($t)) {
            return $this->check('R02', 'The page file is there', self::PASS, 'A PDF template shows its page images; there is no page file.');
        }
        $ok = is_string($t->blade_view) && $t->blade_view !== '' && View::exists($t->blade_view);

        return $ok
            ? $this->check('R02', 'The page file is there', self::PASS, 'The page file for this document exists.')
            : $this->check('R02', 'The page file is there', self::WARN, 'The page file for this document is missing. It is regenerated the first time the template is used.', 'Open the template in the builder and Save to regenerate it.');
    }

    private function r03Signers(Template $t, array $c): array
    {
        if ($c['declared'] !== []) {
            return $this->check('R03', 'Who signs is declared', self::PASS, 'Signers: ' . implode(', ', array_map(fn ($tok) => PartyLabels::label($t, (string) $tok), $c['declared'])) . '.');
        }

        return $t->is_esign
            ? $this->check('R03', 'Who signs is declared', self::FAIL, 'This e-sign document does not say who signs.', 'Add the signers in Template settings.')
            : $this->check('R03', 'Who signs is declared', self::INFO, 'No signers declared (this is not an e-sign document).');
    }

    private function r04SignersHavePlaces(Template $t, array $c): array
    {
        if ($c['declared'] === []) {
            return $this->check('R04', 'Every signer has a place to sign', self::INFO, 'No signers are declared, so there is nothing to compare.');
        }
        $have = [];
        foreach ($c['places'] as $p) {
            if ($p['kind'] === 'signature') {
                foreach ($p['names'] as $n) {
                    $have[$this->signer($n)] = ($have[$this->signer($n)] ?? 0) + 1;
                }
            }
        }
        $standard = $c['block'] === SignatureBlock::COREX;   // CoreX's own block puts a place for every declared signer after the wording
        $missing = [];
        $agentOnly = true;
        foreach ($c['declared'] as $canon => $label) {
            if (! $standard && empty($have[$canon])) {
                $missing[] = 'Place a signature for ' . PartyLabels::label($t, (string) $label) . '.';
                $agentOnly = $agentOnly && $canon === 'agent';
            }
        }
        if ($missing === []) {
            return $this->check('R04', 'Every signer has a place to sign', self::PASS, $standard ? 'CoreX adds a signature block for every signer after the wording.' : 'Every signer has at least one signature place in the document.');
        }

        // A client signer with nowhere to sign cannot complete the document (fail). An agent with no place of their own can still approve
        // and send it — worth a look, not a blocker (Mega's lease is built this way).
        return $agentOnly
            ? $this->check('R04', 'Every signer has a place to sign', self::WARN, 'The agent has no signature place of their own in the document; they can approve and send it but not sign on the page.', 'Place a signature for the agent if the agent must sign.', $missing)
            : $this->check('R04', 'Every signer has a place to sign', self::FAIL, count($missing) . ' signer(s) have no signature place.', 'Place a signature for each signer in the builder.', $missing);
    }

    private function r05PlacesNameSigners(Template $t, array $c): array
    {
        if ($c['declared'] === []) {
            return $this->check('R05', 'Every signature and initial place names a declared signer', self::INFO, 'No signers are declared, so there is nothing to compare.');
        }
        $bad = [];
        foreach ($c['places'] as $p) {
            if ($p['names'] === []) {
                $bad[] = ucfirst($p['kind']) . ' place ' . $p['ref'] . ' names nobody.';
                continue;
            }
            foreach ($p['names'] as $n) {
                if (! isset($c['declared'][$this->signer($n)])) {
                    $bad[] = ucfirst($p['kind']) . ' place ' . $p['ref'] . " names \"{$n}\", who is not a declared signer.";
                }
            }
        }

        return $bad === []
            ? $this->check('R05', 'Every signature and initial place names a declared signer', self::PASS, count($c['places']) . ' place(s) checked.')
            : $this->check('R05', 'Every signature and initial place names a declared signer', self::FAIL, count($bad) . ' place(s) do not match a declared signer.', 'Change each place to one of: ' . implode(', ', array_map(fn ($tok) => PartyLabels::label($t, (string) $tok), $c['declared'])) . '.', $bad);
    }

    private function r06Initials(Template $t, array $c): array
    {
        $by = [];
        foreach ($c['places'] as $p) {
            if ($p['kind'] === 'initial') {
                foreach ($p['names'] ?: ['(nobody)'] as $n) {
                    $by[$n] = ($by[$n] ?? 0) + 1;
                }
            }
        }
        if ($by === []) {
            return $this->check('R06', 'Initial places', self::INFO, 'The document has no initial places of its own; CoreX adds its per-page initials strip when it is signed.');
        }
        $items = [];
        foreach ($by as $n => $k) {
            $items[] = "{$n}: {$k}";
        }

        return $this->check('R06', 'Initial places', self::INFO, array_sum($by) . ' initial place(s) in the document.', '', $items);
    }

    private function r07Blanks(Template $t, array $c): array
    {
        if (! $this->isWeb($t)) {
            $n = count((array) $this->col($t->getRawOriginal('fields_json'), true));

            return $this->check('R07', 'Every blank is linked to a field', self::INFO, "PDF template: {$n} field(s) placed on the pages.");
        }
        $blanks = $this->blanks($c);
        $loose = [];
        foreach ($blanks as $b) {
            $m = $b['map'];
            $label = trim((string) ($m['label'] ?? $m['manualLabel'] ?? ''));
            $linked = is_array($m) && ($label !== '') && (! empty($m['typeKey']) || ! empty($m['namedFieldId']) || ! empty($m['fieldGroupId']) || ($m['mappingType'] ?? '') === 'manual');
            if (! $linked) {
                $loose[] = 'Blank ' . $this->blankLabel($b) . ' is not linked to a field.';
            }
        }
        if ($blanks === []) {
            return $this->check('R07', 'Every blank is linked to a field', self::INFO, 'The document has no fill-in blanks.');
        }

        return $loose === []
            ? $this->check('R07', 'Every blank is linked to a field', self::PASS, count($blanks) . ' blank(s), all linked.')
            : $this->check('R07', 'Every blank is linked to a field', self::FAIL, count($loose) . ' of ' . count($blanks) . ' blank(s) are not linked.', 'Link each blank in the builder (name it and choose what fills it).', $loose);
    }

    private function r08LinkedFieldsExist(Template $t, array $c): array
    {
        $nf = [];
        $fg = [];
        foreach ($this->blanks($c) as $b) {
            $m = $b['map'] ?? [];
            if (! empty($m['namedFieldId'])) {
                $nf[(int) $m['namedFieldId']][] = $this->blankLabel($b);
            }
            $gid = $m['fieldGroupId'] ?? (preg_match('/^fg:(\d+)$/', (string) ($m['typeKey'] ?? ''), $x) ? $x[1] : null);
            if (! empty($gid)) {
                $fg[(int) $gid][] = $this->blankLabel($b);
            }
        }
        if ($nf === [] && $fg === []) {
            return $this->check('R08', 'Linked fields still exist', self::PASS, 'No named fields or field groups are used.');
        }
        $missing = [];
        if ($nf !== []) {
            $have = NamedField::withoutGlobalScopes()->whereIn('id', array_keys($nf))->pluck('id')->map(fn ($i) => (int) $i)->all();
            foreach ($nf as $id => $labels) {
                if (! in_array($id, $have, true)) {
                    $missing[] = 'Named field #' . $id . ' (used by ' . implode(', ', array_slice($labels, 0, 3)) . ') no longer exists.';
                }
            }
        }
        if ($fg !== []) {
            $have = FieldGroup::withoutGlobalScopes()->whereIn('id', array_keys($fg))->pluck('id')->map(fn ($i) => (int) $i)->all();
            foreach ($fg as $id => $labels) {
                if (! in_array($id, $have, true)) {
                    $missing[] = 'Field group #' . $id . ' (used by ' . implode(', ', array_slice($labels, 0, 3)) . ') no longer exists.';
                }
            }
        }

        return $missing === []
            ? $this->check('R08', 'Linked fields still exist', self::PASS, 'Every named field and field group the document uses exists.')
            : $this->check('R08', 'Linked fields still exist', self::FAIL, count($missing) . ' linked field(s) are gone.', 'Re-link those blanks in the builder.', $missing);
    }

    private function r09TypedByHand(Template $t, array $c): array
    {
        $manual = [];
        foreach ($this->blanks($c) as $b) {
            if (($b['map']['mappingType'] ?? '') === 'manual') {
                $manual[] = $this->blankLabel($b);
            }
        }

        return $this->check('R09', 'Blanks typed by hand', self::INFO, $manual === [] ? 'Every blank is filled automatically.' : count($manual) . ' blank(s) are typed by the agent while preparing the document.', '', $manual);
    }

    private function r10HeaderFooter(Template $t, array $c): array
    {
        $texts = [];
        foreach (['page_header', 'page_footer', 'page_footer_first'] as $k) {
            foreach ((array) ($c['cds'][$k]['lines'] ?? []) as $line) {
                foreach (['text', 'right'] as $f) {
                    if (is_string($line[$f] ?? null) && trim($line[$f]) !== '') {
                        $texts[$k][] = $line[$f];
                    }
                }
            }
        }
        if ($texts === []) {
            return $this->check('R10', 'Header and footer words', self::PASS, 'The document has no running header or footer.');
        }
        $warn = [];
        foreach ($texts as $where => $lines) {
            foreach ($lines as $line) {
                if (preg_match_all('/\{([A-Za-z_]+)\}/', $line, $m)) {
                    foreach ($m[1] as $tok) {
                        if (! in_array(strtoupper($tok), self::FRAME_TOKENS, true)) {
                            $warn[] = "The {$where} uses {{$tok}}, which CoreX does not fill in.";
                        }
                    }
                }
                if (! str_contains($line, '{') && preg_match('/\b\d{1,4}[A-Za-z]?\s+[A-Z][A-Za-z]+(?:\s+[A-Za-z]+){0,3}\b.*\b(?:19|20)\d{2}\b/', $line)) {
                    $warn[] = "The {$where} \"{$line}\" looks like one particular tenancy (a street number and a year).";
                }
            }
        }

        return $warn === []
            ? $this->check('R10', 'Header and footer words', self::PASS, 'The running header and footer use only merge fields CoreX fills in.')
            : $this->check('R10', 'Header and footer words', self::WARN, count($warn) . ' header/footer line(s) need a look.', 'Replace the specific words with the merge fields {PROPERTY}, {SURNAME}, {YEAR} and {PAGE}.', $warn);
    }

    private function r11ImporterLoss(Template $t, array $c): array
    {
        $r = $c['cds']['parse_report'] ?? null;
        if (! is_array($r)) {
            return $this->check('R11', 'Nothing the Word file printed was lost', self::INFO, 'This template has no Word-import report (it was not imported from a Word file, or before reports were kept).');
        }
        $dropped = array_values(array_filter((array) ($r['dropped'] ?? []), fn ($d) => is_string($d) ? $d !== '' : ! empty($d)));
        $dropped = array_map(fn ($d) => is_string($d) ? $d : json_encode($d, JSON_UNESCAPED_UNICODE), $dropped);
        $unread = (int) ($r['unread_chars'] ?? 0);

        return ($dropped === [] && $unread === 0)
            ? $this->check('R11', 'Nothing the Word file printed was lost', self::PASS, (string) ($r['line'] ?? 'Everything the Word file printed was carried over.'))
            : $this->check('R11', 'Nothing the Word file printed was lost', self::WARN, (string) ($r['line'] ?? 'Part of the Word file was not carried over.') . ($unread > 0 ? " ({$unread} characters not read.)" : ''), 'Re-create the missing part by hand in the builder.', $dropped);
    }

    private function r12TypeAndEsign(Template $t, array $c): array
    {
        $lease = RentalLeaseTemplate::withoutGlobalScopes()->where('docuperfect_template_id', $t->id)->exists();
        $items = [];
        $status = self::PASS;
        if (! $t->document_type_id) {
            $items[] = 'No document type is set.';
            $status = self::WARN;
        }
        if (! $t->is_esign) {
            $items[] = $lease ? 'This template is linked as a lease but is not an e-sign document.' : 'This is not an e-sign document.';
            $status = $lease ? self::FAIL : self::WARN;
        }

        return $items === []
            ? $this->check('R12', 'Document type and e-sign', self::PASS, 'A document type is set and the template is an e-sign document.')
            : $this->check('R12', 'Document type and e-sign', $status, implode(' ', $items), 'Set these in Template settings.');
    }

    private function r13Letterhead(Template $t, array $c): array
    {
        $display = (string) ($t->header_display ?: 'first_page');
        $ownHeader = ! empty($c['cds']['page_header']['lines']);
        if ($display === 'none') {
            return $ownHeader
                ? $this->check('R13', 'Letterhead', self::INFO, 'This document prints its own letterhead from the Word file; the agency letterhead is switched off.')
                : $this->check('R13', 'Letterhead', self::INFO, 'The agency letterhead is switched off and the document has no letterhead of its own.');
        }
        $agency = $t->agency_id ? Agency::withoutGlobalScopes()->find($t->agency_id) : null;
        if (! $agency) {
            return $this->check('R13', 'Letterhead', self::INFO, 'The template has no owning agency yet, so there is no agency letterhead to check.');
        }
        $lh = AgencyLetterhead::for($agency);
        $thin = ! $lh || ($lh->name === '' || ($lh->addressLines === [] && $lh->phoneLines === [] && $lh->email === ''));

        return $thin
            ? $this->check('R13', 'Letterhead', self::WARN, "The agency letterhead prints on this document but {$agency->name} has little or no company detail set.", 'Fill in the agency name, address and phone or e-mail in Company Settings.')
            : $this->check('R13', 'Letterhead', self::PASS, "The agency letterhead for {$agency->name} is set and prints on this document ({$display}).");
    }

    private function r14Lease(Template $t, array $c): array
    {
        $row = RentalLeaseTemplate::withoutGlobalScopes()->where('docuperfect_template_id', $t->id)->orderByDesc('id')->first();
        $guard = app(LeaseAgreementTemplateGuard::class);
        if (! $row) {
            $roles = $guard->signingRolesFor($t);
            $can = count(array_intersect($roles, LeaseAgreementTemplateGuard::REQUIRED_ROLES)) === count(LeaseAgreementTemplateGuard::REQUIRED_ROLES);

            return $this->check('R14', 'Lease readiness', self::INFO, $can ? 'Not linked as a lease agreement yet; it has signing places for agent, landlord and tenant, so it can be.' : 'Not linked as a lease agreement.');
        }
        $map = is_array($row->field_map) ? $row->field_map : [];
        $problems = $guard->problemsFor($t, (int) $row->agency_id, $map);
        $warns = $guard->unresolvedWarnings($t, $map, (int) $row->agency_id);
        if ($problems === [] && $warns === []) {
            return $this->check('R14', 'Lease readiness', self::PASS, 'Linked as "' . $row->name . '" and ready to use for leases.');
        }

        return $this->check('R14', 'Lease readiness', $problems !== [] ? self::FAIL : self::WARN, 'Linked as "' . $row->name . '".', 'Fix these in Settings → Rental lease agreements or in the builder.', array_merge($problems, $warns));
    }

    private function r15PackageAccepted(Template $t, array $c): array
    {
        $found = [];
        $content = [
            'signing_parties' => $this->col($t->getRawOriginal('signing_parties'), true),
            'cds_json' => $c['cds'],
            'editor_state' => $c['editor'],
            'fields_json' => $this->col($t->getRawOriginal('fields_json'), true),
            'field_mappings' => $this->col($t->getRawOriginal('field_mappings')),
            'wizard_config' => $this->col($t->getRawOriginal('wizard_config')),
            'sections' => $this->col($t->getRawOriginal('sections'), true),
            'insertable_blocks' => $this->col($t->getRawOriginal('insertable_blocks'), true),
        ];
        foreach ($content as $col => $val) {
            array_push($found, ...TemplateContentSafety::scan($val, $col));
        }
        array_push($found, ...TemplateContentSafety::checkRoleTokens(['signing_parties' => $content['signing_parties'], 'field_mappings' => $content['field_mappings'], 'editor_state' => $c['editor']]));
        $found = array_values(array_unique($found));

        return $found === []
            ? $this->check('R15', 'The package would be accepted', self::PASS, 'The content passes the safety scan a package import applies.')
            : $this->check('R15', 'The package would be accepted', self::FAIL, 'A package of this template would be refused by the safety scan.', 'Remove what the lines below name, then save again.', $found);
    }

    private function r16Archived(Template $t, array $c): array
    {
        return ($t->archived_at !== null || $t->deleted_at !== null)
            ? $this->check('R16', 'Archived or deleted', self::FAIL, 'This template is archived.', 'Restore it before it is verified or released.')
            : $this->check('R16', 'Archived or deleted', self::PASS, 'The template is active.');
    }

    private function r17PageCount(Template $t, array $c): array
    {
        $n = (int) $t->page_count;

        return $this->check('R17', 'Page count', self::INFO, $n > 0 ? "Stored page count: {$n}." : 'The page count is not stored for this template; the demo view measures it.');
    }
}
