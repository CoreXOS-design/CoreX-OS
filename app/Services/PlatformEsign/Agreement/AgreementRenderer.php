<?php

namespace App\Services\PlatformEsign\Agreement;

use App\Models\PlatformEsign\WordingVersion;
use Carbon\Carbon;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;

/**
 * Turns a wording version's tokenised markdown into HTML blocks (spec §11.4/§11.6).
 *
 * Modes — edit: wording editor (fields shown as labelled markers) · form: recipient edits · rr: recipient entries read-only + RR inputs · pdf: sealed values · wet: values + blank
 * initial/signature lines (hand-signing copy) · preview: owner read-only (sensitive masked) · canon: layout estimation ·
 * text: every token blank (fidelity tests).
 * The block structure never depends on the mode or on values, so one stored layout (pages) fits every rendering.
 */
class AgreementRenderer
{
    private const TOKEN = '/\{\{(f|o|q|rate|amt|rr|sig|ini|ref|auto|ctl|co)(?::([a-z0-9_]+))?(?::([a-z0-9_]+))?\}\}/';
    /** Screen-only helper text beside the dates RR sets (never in a PDF; a declared addition of the proof). */
    public const TAKE_ON_TIP = 'Set by CoreX as agreed for your take-on month.';
    public const AMOUNT_TIP = 'Fills in automatically from your monthly fee in section 3.';

    private const LINE_APPLIES = ['team' => ['team_seats'], 'agency' => ['agency_base', 'agency_t1', 'agency_t2', 'agency_t3', 'branches']];

    /** @var array<string,mixed> */
    private array $ctx = [];
    private string $mode = 'text';

    /**
     * @param array<string,mixed> $ctx values, rr, calc, rates, ref, initials{agency,rr}, sigs{agency,mandate,rr}, sign_date, mask, errors, doc_id
     * @return string[] top-level HTML blocks
     */
    public function blocks(WordingVersion $v, string $part, string $mode, array $ctx = []): array
    {
        $md = (string) ($v->content_json[$part] ?? '');
        $this->mode = $mode;
        $this->ctx = $ctx + ['values' => [], 'rr' => [], 'calc' => null, 'rates' => $v->rates_json ?? [], 'ref' => '', 'initials' => [], 'sigs' => [], 'sign_date' => null, 'mask' => false, 'errors' => [], 'doc_id' => null];

        $blocks = array_map(fn (string $html) => $this->finish($html), $this->markdownBlocks($md));
        if ($part === 'intro') {
            // Cover typography only (a class on the first two paragraphs — the block count and text are untouched).
            foreach (['cover-title', 'cover-sub'] as $i => $cls) {
                if (isset($blocks[$i]) && str_starts_with($blocks[$i], '<p>')) {
                    $blocks[$i] = '<p class="' . $cls . '">' . substr($blocks[$i], 3);
                }
            }
        }

        return $blocks;
    }

    /** @return string[] */
    public function markdownBlocks(string $md): array
    {
        $env = new Environment(['html_input' => 'allow', 'allow_unsafe_links' => false]);
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new GithubFlavoredMarkdownExtension());
        $doc = (new MarkdownParser($env))->parse($md);
        $renderer = new HtmlRenderer($env);
        $out = [];
        foreach ($doc->children() as $child) {
            $out[] = trim((string) $renderer->renderNodes([$child]));
        }

        return $out;
    }

    private function finish(string $html): string
    {
        // Tables written as "|||" have an empty header row — drop it so it does not render as a blank bar.
        $html = preg_replace('#<thead>\s*<tr>(\s*<th[^>]*>\s*</th>)+\s*</tr>\s*</thead>#', '', $html);

        $html = $this->alignmentHooks($html);
        $html = preg_replace_callback(self::TOKEN, fn ($m) => $this->token($m[1], $m[2] ?? '', $m[3] ?? ''), $html);

        return $this->isForm() ? $this->gatherTips($html) : $html;
    }

    /** Mandate rows laid out on one grid: label text => true. The text and its order are untouched — only classes and spans are added. */
    private const MANDATE_ROWS = ['Address', 'Bank Name', 'Branch Name and Town', 'Branch Number', 'Account Number', 'Type of Account', 'Date', 'Contact Number', 'Amount',
        'To (Name of Beneficiary)', 'Abbreviated Shortname to be used'];

    /**
     * Layout hooks (spec §11.21) — classes only, never wording: the Agency / RR Technologies signature table gets equal columns and fixed row heights so
     * both blocks line up row by row; each mandate "Label: field" paragraph becomes a row of one aligned grid. Same on screen, preview, RR screen and both PDFs.
     */
    private function alignmentHooks(string $html): string
    {
        if (str_contains($html, 'For the Agency') && str_contains($html, 'For RR Technologies') && str_starts_with($html, '<table')) {
            $html = preg_replace('/^<table>/', '<table class="sigtable">', $html, 1);
            $html = preg_replace_callback('#<p>(Name|Capacity|Signature|Date|Place):#', fn ($m) => '<p class="sr sr-' . strtolower($m[1]) . '"><span class="sr-l">' . $m[1] . ':</span>', $html);

            return $html;
        }
        if (preg_match('#^<p>Given by <em>\(name of Accountholder\):\s*(.*)</em></p>$#s', $html, $m)) {
            return '<p class="mf"><span class="mf-l">Given by <em>(name of Accountholder):</em></span> <span class="mf-v">' . $m[1] . '</span></p>';
        }
        if (preg_match('#^<p>(' . implode('|', array_map(fn ($l) => preg_quote($l, '#'), self::MANDATE_ROWS)) . '):\s*(.*)</p>$#s', $html, $m)) {
            return '<p class="mf"><span class="mf-l">' . $m[1] . ':</span> <span class="mf-v">' . $m[2] . '</span></p>';
        }

        return $html;
    }

    /**
     * Screen-only tips: inside a mandate grid row they sit in their own third column (same line, equal row heights); inside a sentence
     * (first payment date / collection day) they move to the end of the paragraph so the printed sentence still reads straight through.
     */
    private function gatherTips(string $html): string
    {
        $tip = '#<span class="auto-tip"[^>]*>.*?</span>#s';

        return preg_replace_callback('#<p( class="mf")?>(.*?)</p>#s', function ($m) use ($tip) {
            if (!preg_match_all($tip, $m[2], $found)) {
                return $m[0];
            }
            $body = preg_replace($tip, '', $m[2]);
            if ($m[1] !== '') {
                return '<p class="mf">' . $body . ' <span class="mf-t">' . implode(' ', $found[0]) . '</span></p>';
            }

            return '<p>' . $body . ' <span class="tip-end">' . implode(' ', $found[0]) . '</span></p>';
        }, $html);
    }

    // ── tokens ─────────────────────────────────────────────────────────────

    private function token(string $kind, string $a, string $b): string
    {
        if ($this->mode === 'text' && $kind !== 'rate') {
            return ''; // fidelity mode: every field blank; rates are fixed wording of the pinned version
        }
        if ($this->mode === 'edit' && $kind !== 'rate') {
            return $this->chip($kind, $a, $b); // wording editor: a labelled marker, never a live field
        }

        return match ($kind) {
            'f' => $this->field($a),
            'o' => $this->option($a, $b),
            'q' => $this->quantity($a),
            'rate' => e(AgreementPricing::rate((float) ($this->ctx['rates'][$a] ?? AgreementPricing::DEFAULT_RATES[$a] ?? 0))),
            'amt' => $this->amount($a),
            'rr' => $this->rrField($a),
            'sig' => $this->signature($a),
            'ini' => $this->initials($a),
            'ref' => '<span class="val">' . ($this->mode === 'canon' ? 'CX000000' : e($this->ctx['ref'] ?: '—')) . '</span>',
            'auto' => $this->auto($a),
            'ctl' => $this->control($a),
            'co' => $this->company($a),
            default => '',
        };
    }

    /** A grey, labelled marker standing for a field in the wording editor (spec §11.14). */
    private function chip(string $kind, string $a, string $b): string
    {
        $token = implode(':', array_filter([$kind, $a, $b], fn ($p) => $p !== ''));
        $label = in_array($kind, AgreementTokens::GUARDED, true) ? AgreementTokens::describe($token) : match ($kind) {
            'ref' => '“contract reference”', 'auto' => '“' . ($a === 'day' ? 'day of signing' : 'month and year of signing') . '”', 'co' => '“company ' . ($a === 'address' ? 'address' : 'name') . '”', default => '“' . $token . '”',
        };

        return '<span class="tok" title="{{' . e($token) . '}}">' . e(trim($label, '“”')) . '</span>';
    }

    private function isForm(): bool
    {
        return $this->mode === 'form';
    }

    private function value(string $key, string $side = 'values'): string
    {
        return trim((string) ($this->ctx[$side][$key] ?? ''));
    }

    private function field(string $key): string
    {
        $f = AgreementFields::schema()[$key] ?? null;
        if (!$f) {
            return '';
        }
        if ($this->isForm()) {
            if (isset(AgreementTakeOn::FIELDS[$key]) && !empty($this->ctx['rr']['take_on_month'])) {
                // Start date, first collection date, collection day, mandate Amount: set by RR through the take-on month (spec §11.19) — shown, never typed.
                $v = $this->value($key);
                $shown = match ($key) {
                    'start_date', 'm_first_payment' => $this->date($v),
                    'm_amount' => $v === '' ? '' : 'R ' . AgreementPricing::number((float) $v),
                    default => $v,
                };

                return '<input type="text" class="fld' . ($key === 'm_amount' ? ' num' : '') . '" value="' . e($shown) . '" readonly tabindex="-1" data-derived="1"' . ($key === 'm_amount' ? ' data-mirror="total"' : '') . ' aria-label="' . e($f['label']) . '">'
                    . '<span class="auto-tip" data-screen-only="1">' . ($key === 'm_amount' ? self::AMOUNT_TIP : self::TAKE_ON_TIP) . '</span>';
            }
            if (!empty($this->ctx['rr']['single_entry']) && isset(AgreementFields::FOLLOW[$key])) {
                // Starts from (and follows) Part A until the recipient types their own value here; clearing the box makes it follow again.
                $own = in_array($key, (array) ($this->ctx['follow_own'] ?? []), true);
                $input = str_replace(' data-field="' . $key . '"', ' data-field="' . $key . '" data-follow="' . e(AgreementFields::FOLLOW[$key]) . '"' . ($own ? ' data-own="1"' : ''), $this->input($key, $f));

                return $input . '<span class="auto-tip" data-screen-only="1">' . e(self::FOLLOW_TIPS[$key]) . '</span>';
            }
            if (!empty($this->ctx['rr']['single_entry']) && isset(AgreementFields::MIRRORS[$key])) {
                return $this->mirrorField($key, $f);
            }
            if ($key === 'branches') {
                // Entered once, in section 3 beside the number of agents; this row of the original form just shows it.
                return '<input type="text" class="fld num" value="' . e($this->value('branches')) . '" readonly tabindex="-1" data-derived="1" data-mirror="branches" aria-label="' . e($f['label']) . '">' . $this->tip();
            }

            return $this->input($key, $f) . ($key === 'branches_start' ? $this->tip() : '');
        }
        if ($this->mode === 'canon') {
            return '<span class="val">' . str_repeat('x', $f['type'] === 'textarea' ? 70 : ($f['type'] === 'date' ? 12 : 26)) . '</span>';
        }

        return $this->staticValue($key, $f, 'values');
    }

    private function staticValue(string $key, array $f, string $side): string
    {
        $v = $this->value($key, $side);
        if ($v === '') {
            return '<span class="blank">&nbsp;</span>';
        }
        $shown = match ($f['type']) {
            'radio', 'select' => $f['options'][$v] ?? $v,
            'date' => $this->date($v),
            'money' => 'R ' . (is_numeric($v) ? AgreementPricing::number((float) $v) : $v),
            default => $v,
        };
        if (in_array($key, AgreementFields::SENSITIVE, true) && $this->ctx['mask']) {
            $last = substr(preg_replace('/\D+/', '', $v), -4);

            return '<span class="val masked" data-reveal="' . e($key) . '">••••' . e($last) . '</span>';
        }

        return '<span class="val">' . nl2br(e($shown)) . '</span>';
    }

    private function date(string $iso): string
    {
        try {
            return Carbon::parse($iso)->format('j F Y');
        } catch (\Throwable) {
            return $iso;
        }
    }

    private function input(string $key, array $f): string
    {
        $v = $this->value($key);
        $err = $this->ctx['errors'][$key] ?? null;
        $cls = 'fld' . ($err ? ' err' : '');
        $derived = in_array($key, AgreementService::DERIVED_KEYS, true);
        $attr = 'name="' . e($key) . '" id="fld-' . e($key) . '" data-field="' . e($key) . '"' . ($f['required'] && !$derived ? ' data-required="1"' : '') . ($derived ? ' readonly tabindex="-1" data-derived="1"' : '') . ' maxlength="' . min((int) $f['max'], 500) . '" aria-label="' . e($f['label']) . '"';
        $title = $err ? ' title="' . e($err) . '"' : '';
        $locked = !empty($this->ctx['locked']) ? ' disabled' : '';

        return match ($f['type']) {
            'textarea' => '<textarea class="' . $cls . '" rows="2" ' . $attr . $title . $locked . '>' . e($v) . '</textarea>',
            'select' => '<select class="' . $cls . '" ' . $attr . $title . $locked . '><option value="">Choose…</option>' . implode('', array_map(fn ($k, $l) => '<option value="' . e($k) . '"' . ($v === $k ? ' selected' : '') . '>' . e($l) . '</option>', array_keys($f['options']), $f['options'])) . '</select>',
            'date' => '<input type="date" class="' . $cls . '" value="' . e($v) . '" ' . $attr . $title . $locked . '>',
            'email' => '<input type="email" class="' . $cls . '" value="' . e($v) . '" autocomplete="off" ' . $attr . $title . $locked . '>',
            'tel' => '<input type="tel" class="' . $cls . '" value="' . e($v) . '" ' . $attr . $title . $locked . '>',
            'int' => '<input type="text" inputmode="numeric" class="' . $cls . ' num" value="' . e($v) . '" ' . $attr . $title . $locked . '>',
            'money' => '<input type="text" inputmode="decimal" class="' . $cls . ' num" value="' . e($v) . '" ' . $attr . $title . $locked . '>',
            default => '<input type="text" class="' . $cls . '" value="' . e($v) . '" autocomplete="off" ' . $attr . $title . $locked . '>',
        };
    }

    /**
     * Screen-only helper text beside the three read-only places (plan ticks, section 1 and section 3 branches): they fill in from the
     * two entries above the fee table. Form mode only — never in the PDFs, never part of the wording (spec §11.15, declared addition).
     */
    private function tip(bool $float = false): string
    {
        return '<span class="auto-tip' . ($float ? ' auto-tip-float' : '') . '" data-screen-only="1">Fills in automatically — enter your number of agents and branches in the '
            . '<a href="#fld-agents" data-goto="fld-agents">Monthly fee at start</a> section (section 3).</span>';
    }

    /**
     * Single entry (spec §11.20): a value typed in one place (the mandate, or Part A) is shown read-only in the other. Per target:
     * [tip sentence with {link}, link text, id of the field it is typed in, whether this field carries the tip]. Screen only.
     */
    public const MIRROR_TIPS = [
        'da_holder' => ['Fills in automatically from the {link}.', 'debit order mandate', 'fld-m_holder'],
        'da_bank' => null, // the bank and branch code share one row — the tip sits after the branch code
        'da_branch_code' => ['Fills in automatically from the {link}.', 'debit order mandate', 'fld-m_bank'],
        'da_account' => ['Fills in automatically from the {link}.', 'debit order mandate', 'fld-m_account'],
        'da_type' => ['Fills in automatically from the {link}.', 'debit order mandate', 'fld-m_account_type-current'],
        'm_place' => ['Fills in automatically from the {link} in section 6.', 'place', 'fld-sig_place'],
        'm_date' => ['Fills in automatically from the {link} in section 6.', 'date', 'fld-sig_date'],
    ];

    /** Mandate address / contact number: follow Part A but stay editable (Johan 2026-10-06 13:20). Screen only. */
    public const FOLLOW_TIPS = [
        'm_address' => 'Filled in from your details above — change it here if the debit order needs a different address.',
        'm_contact' => 'Filled in from your details above — change it here if the debit order needs a different number.',
    ];

    /** The plain sentence of a mirror tip (also what the word-for-word proof strips from the page text). */
    public static function mirrorTipText(string $key): string
    {
        [$sentence, $link] = self::MIRROR_TIPS[$key];

        return str_replace('{link}', $link, $sentence);
    }

    private function mirrorField(string $key, array $f): string
    {
        $source = AgreementFields::MIRRORS[$key];
        $v = $this->value($key);
        $sf = AgreementFields::schema()[$source];
        $shown = match ($sf['type']) {
            'radio', 'select' => $sf['options'][$v] ?? '',
            'date' => $v === '' ? '' : $this->date($v),
            default => $v,
        };
        $attrs = ' data-mirror-of="' . e($source) . '"' . ($sf['type'] === 'date' ? ' data-format="date"' : '')
            . (in_array($sf['type'], ['radio', 'select'], true) ? " data-map='" . e(json_encode($sf['options'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) . "'" : '');
        $tip = '';
        if ($spec = self::MIRROR_TIPS[$key] ?? null) {
            $link = '<a href="#' . e($spec[2]) . '" data-goto="' . e($spec[2]) . '">' . e($spec[1]) . '</a>';
            $tip = '<span class="auto-tip" data-screen-only="1">' . str_replace('{link}', $link, e($spec[0])) . '</span>';
        }
        $common = ' readonly tabindex="-1" data-derived="1"' . $attrs . ' aria-label="' . e($f['label']) . '"';
        if ($sf['type'] === 'textarea') {
            return '<textarea class="fld" rows="2"' . $common . '>' . e($shown) . '</textarea>' . $tip;
        }

        return '<input type="text" class="fld" value="' . e($shown) . '"' . $common . '>' . $tip;
    }

    private function option(string $key, string $val): string
    {
        $f = AgreementFields::schema()[$key] ?? null;
        if (!$f) {
            return '';
        }
        $on = $this->value($key) === $val;
        if ($this->isForm()) {
            $err = !empty($this->ctx['errors'][$key]) ? ' err' : '';
            $locked = !empty($this->ctx['locked']) ? ' disabled' : '';

            if ($key === 'plan') {
                // The plan is the result of the number of agents (section 3 completes itself) — shown, never tickable by the recipient.
                return ($val === 'team' ? $this->tip(true) : '')
                    . '<label class="opt" title="Chosen automatically from the number of agents"><input type="radio" name="plan" value="' . e($val) . '" data-field="plan" data-derived="1"' . ($on ? ' checked' : '') . ' disabled><span class="tick"></span></label>';
            }

            return '<label class="opt' . $err . '"><input type="radio" id="fld-' . e($key) . '-' . e($val) . '" name="' . e($key) . '" value="' . e($val) . '" data-field="' . e($key) . '"' . ($f['required'] ? ' data-required="1"' : '') . ($on ? ' checked' : '') . $locked . '><span class="tick"></span></label>';
        }
        if ($this->mode === 'canon') {
            return '<span class="box">☐</span>';
        }

        return '<span class="box">' . ($on ? '☒' : '☐') . '</span>';
    }

    private function quantity(string $line): string
    {
        $calc = $this->ctx['calc'];
        $q = $calc['lines'][$line]['qty'] ?? null;
        $applies = $this->applies($line);
        $text = $this->mode === 'canon' ? '00' : ($applies && $q !== null ? (string) $q : '');

        return '<span data-calc="q:' . e($line) . '">' . e($text) . '</span>';
    }

    private function amount(string $line): string
    {
        $calc = $this->ctx['calc'];
        if ($line === 'total') {
            $n = $calc['total'] ?? null;
            $hasPlan = !empty($this->ctx['values']['plan']);
            $text = $this->mode === 'canon' ? '0 000' : ($n !== null && $hasPlan ? AgreementPricing::number((float) $n) : '');
        } else {
            $n = $calc['lines'][$line]['amount'] ?? null;
            $text = $this->mode === 'canon' ? '0 000' : ($this->applies($line) && $n !== null ? AgreementPricing::number((float) $n) : '');
        }

        return '<span data-calc="amt:' . e($line) . '">' . e($text) . '</span>';
    }

    private function applies(string $line): bool
    {
        $plan = (string) ($this->ctx['values']['plan'] ?? '');

        return in_array($line, self::LINE_APPLIES[$plan] ?? [], true);
    }

    private function rrField(string $key): string
    {
        $f = AgreementFields::schema()[$key] ?? null;
        if (!$f) {
            return '';
        }
        $editable = $this->mode === 'rr' && !in_array($key, ['variation_text', 'variation_amount'], true);
        if ($editable) {
            return $this->rrInput($key, $f);
        }
        if ($this->mode === 'canon') {
            return '<span class="val">' . str_repeat('x', $key === 'variation_text' ? 60 : 18) . '</span>';
        }
        $v = $this->value($key, 'rr');
        if ($key === 'variation_amount') {
            return $v === '' ? '<span class="blank">&nbsp;</span>' : '<span class="val">' . e(AgreementPricing::number((float) $v)) . '</span>';
        }
        if ($key === 'rr_date' && $v !== '') {
            $v = $this->date($v);
        }

        return $v === '' ? '<span class="blank">&nbsp;</span>' : '<span class="val">' . nl2br(e($v)) . '</span>';
    }

    private function rrInput(string $key, array $f): string
    {
        $v = $this->value($key, 'rr');
        $err = $this->ctx['errors'][$key] ?? null;
        $type = $f['type'] === 'date' ? 'date' : 'text';

        return '<input type="' . $type . '" class="fld' . ($err ? ' err' : '') . '" name="' . e($key) . '" id="fld-' . e($key) . '" data-field="' . e($key) . '" data-required="1" maxlength="' . (int) $f['max'] . '" value="' . e($v) . '" aria-label="' . e($f['label']) . '"' . ($err ? ' title="' . e($err) . '"' : '') . '>';
    }

    private function signature(string $who): string
    {
        $key = ['agency' => 'sigA', 'mandate' => 'sigM', 'rr' => 'sigR'][$who] ?? null;
        if (!$key) {
            return '';
        }
        if ($this->mode === 'canon') {
            return '<span class="sigbox"></span>';
        }
        $img = (string) ($this->ctx['sigs'][$who] ?? '');
        if ($this->mode === 'wet' || ($this->mode === 'text')) {
            return '<span class="sigline">&nbsp;</span>';
        }
        $interactive = ($this->isForm() && $who !== 'rr') || ($this->mode === 'rr' && $who === 'rr');
        if ($interactive && empty($this->ctx['locked'])) {
            $err = !empty($this->ctx['errors'][$key]) ? ' err' : '';
            $copy = $who === 'mandate' ? '<button type="button" class="mini" data-sig-copy="sigA">Use the same signature</button>' : '';

            // <span>s (display:block in the CSS), not <div>s: a block inside a <p> makes the browser close the paragraph early and breaks the signature rows.
            return '<span class="sigpad' . $err . '" data-sig="' . e($key) . '" data-field="' . e($key) . '" data-required="1"><canvas></canvas>'
                . '<span class="sigtools"><button type="button" class="mini" data-sig-clear>Clear</button><button type="button" class="mini" data-sig-type>Type it instead</button>' . $copy . '</span>'
                . '<input type="hidden" name="' . e($key) . '" value="' . e($img) . '"></span>';
        }

        return $img !== '' ? '<img class="sigimg" src="' . e($img) . '" alt="Signature">' : '<span class="blank sigline">&nbsp;</span>';
    }

    private function initials(string $who): string
    {
        $ini = (string) ($this->ctx['initials'][$who] ?? '');
        if ($this->mode === 'wet' || $this->mode === 'text') {
            return '<span class="blank">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>';
        }
        if ($this->mode === 'canon') {
            return '<span class="val">XX</span>';
        }

        return '<span class="val ini" data-ini="' . e($who) . '">' . ($ini !== '' ? e($ini) : '&nbsp;&nbsp;&nbsp;') . '</span>';
    }

    private function auto(string $what): string
    {
        if ($this->mode === 'wet') {
            return '<span class="blank">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>';
        }
        if ($this->mode === 'canon') {
            return '<span class="val">00 xxxxxxxx 0000</span>';
        }
        $d = $this->ctx['sign_date'] instanceof Carbon ? $this->ctx['sign_date'] : now();

        return '<span class="val" data-auto="' . e($what) . '">' . e($what === 'day' ? $d->format('jS') : $d->format('F Y')) . '</span>';
    }

    /** Fixed RR Technologies party details, through the company adapter (spec §11.6). */
    private function company(string $what): string
    {
        $co = $this->ctx['company'] ?? app(AgreementCompany::class);

        return e($what === 'address' ? $co->beneficiaryAddress() : $co->legalName());
    }

    private function control(string $what): string
    {
        if ($what !== 'agents') {
            return '';
        }
        if ($this->mode === 'canon') {
            return '<span class="keep-next"></span>'; // layout estimation only: keep this block on the page of the fee table that follows
        }
        $calc = $this->ctx['calc'];
        $limit = (int) ($this->ctx['rates']['quote_above_agents'] ?? 40);
        if (in_array($this->mode, ['rr', 'preview'], true)) {
            // RR side: the quoted-rate reminder only (nothing is printed in the contract itself).
            return ($calc['over_quote_threshold'] ?? false) ? '<span class="ctl"><span class="ctl-note">Over ' . $limit . ' agents a quoted rate applies — record it under “Agreed variations”.</span></span>' : '';
        }
        if (!$this->isForm()) {
            return '';
        }
        $schema = AgreementFields::schema();
        $note = ($calc['over_quote_threshold'] ?? false) ? 'For more than ' . $limit . ' agents a quoted rate is recorded under “Agreed variations” — we will confirm it with you.' : '';

        // The only two entries section 3 needs, side by side directly above the fee table: the plan ticks and every line below follow them.
        return '<span class="ctl"><span class="ctl-pair">'
            . '<span class="ctl-item"><label for="fld-agents"><strong>Number of agents</strong></label> ' . $this->input('agents', $schema['agents']) . '</span>'
            . '<span class="ctl-item"><label for="fld-branches"><strong>Number of branches</strong></label> ' . $this->input('branches', $schema['branches']) . '</span>'
            . '</span><span class="ctl-note" data-calc="note:agents">' . e($note) . '</span></span>';
    }
}
