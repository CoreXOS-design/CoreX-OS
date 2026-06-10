<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use App\Services\AI\AnthropicGateway;
use App\Services\AI\DTOs\NarrativeRequest;
use Illuminate\Support\Facades\Log;

/**
 * ES-6.7 — AI extraction-fidelity verifier (PDF imports only).
 *
 * Sends BOTH the original PDF (native Anthropic document block, vision) AND the
 * extracted CDS text/structure to Claude in ONE call, asking it to list
 * divergences (dropped/reordered/merged/mangled content, lost line breaks,
 * misplaced ~~~~ markers). Returns plain flag DATA + a run-level status — it
 * does NOT touch the database (persistence + the human gate live in the
 * controller, so this stays a pure, testable transform).
 *
 * Guarantees:
 *   - Word is never sent here (the controller only calls this for PDF).
 *   - Fail-OPEN: any AI/transport/parse failure → status 'could_not_run' with
 *     zero flags. The import still succeeds; the could-not-run state is
 *     surfaced as a warning (never a silent pass).
 *   - Severity is config-driven: the AI suggests a type, config decides the
 *     band (unknown type → default_severity, fail-safe high).
 *
 * Uses AnthropicGateway::generate() directly (NOT generateStructured, which
 * drops the `documents` payload — see audit) and parses JSON defensively here.
 */
class CdsExtractionVerifier
{
    public const STATUS_PASSED        = 'passed';
    public const STATUS_WARNINGS      = 'warnings';
    public const STATUS_BLOCKED       = 'blocked';
    public const STATUS_CLEARED       = 'cleared';
    public const STATUS_COULD_NOT_RUN = 'could_not_run';

    public function __construct(private readonly AnthropicGateway $gateway)
    {
    }

    /**
     * @return array{status: ?string, flags: list<array<string,mixed>>}
     *         status null  → verification disabled (skipped).
     */
    public function verify(string $pdfPath, array $cds): array
    {
        if (!config('docuperfect.import.fidelity.enabled', true)) {
            return ['status' => null, 'flags' => []];
        }
        if (!is_file($pdfPath)) {
            return ['status' => self::STATUS_COULD_NOT_RUN, 'flags' => []];
        }

        $divergences = $this->callVision($pdfPath, $cds);
        if ($divergences === null) {
            return ['status' => self::STATUS_COULD_NOT_RUN, 'flags' => []];
        }

        $flags = $this->mapToFlags($divergences);
        return ['status' => $this->statusFromFlagData($flags), 'flags' => $flags];
    }

    /**
     * The vision call. Returns the raw divergence list, or null on any
     * failure (fail-open). Isolated so the rest of the flow is deterministic.
     *
     * @return list<array<string,mixed>>|null
     */
    protected function callVision(string $pdfPath, array $cds): ?array
    {
        $bytes = @file_get_contents($pdfPath);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $request = new NarrativeRequest(
            narrativeType:   'esign_extraction_fidelity',
            cacheKey:        'esign_fidelity:' . hash('sha256', $bytes),
            modelAlias:      (string) config('docuperfect.import.fidelity.model_alias', 'quality'),
            systemPrompt:    $this->systemPrompt(),
            userPrompt:      $this->userPrompt($cds),
            inputData:       ['pdf_sha256' => hash('sha256', $bytes)],
            maxTokens:       6000,
            temperature:     0.0,
            forceRefresh:    true,
            promptVersion:   'es6.7-v1',
            // Deterministic fallback so generate() never throws — a fallback
            // response is detected (fromFallback) and treated as could-not-run.
            fallbackData:    ['text' => '{"divergences":[]}', 'json' => ['divergences' => []]],
            documents:       [[
                'type'       => 'pdf',
                'media_type' => 'application/pdf',
                'data'       => base64_encode($bytes),
            ]],
        );

        try {
            $response = $this->gateway->generate($request);
        } catch (\Throwable $e) {
            Log::warning('CdsExtractionVerifier: vision call failed', ['error' => $e->getMessage()]);
            return null;
        }

        // A fallback response means the API did not actually run (disabled / no
        // key / capped / error) — surface as could-not-run, never a false pass.
        if (!empty($response->fromFallback)) {
            return null;
        }

        $parsed = is_array($response->outputJson)
            ? $response->outputJson
            : $this->parseJson((string) $response->outputText);

        if ($parsed === null || !isset($parsed['divergences']) || !is_array($parsed['divergences'])) {
            Log::warning('CdsExtractionVerifier: unparseable / malformed AI response');
            return null;
        }

        return array_values(array_filter($parsed['divergences'], 'is_array'));
    }

    /**
     * Map raw AI divergences → persistable flag data, applying the
     * config-driven severity band and capping the count.
     *
     * @param  list<array<string,mixed>> $divergences
     * @return list<array<string,mixed>>
     */
    public function mapToFlags(array $divergences): array
    {
        $max = max(0, (int) config('docuperfect.import.fidelity.max_flags', 40));
        $flags = [];
        foreach ($divergences as $d) {
            $type        = $this->cleanStr($d['type'] ?? $d['divergence_type'] ?? 'unknown', 60) ?: 'unknown';
            $aiSeverity  = strtolower((string) ($d['severity'] ?? ''));
            // `description` is NOT-NULL — a divergence with no usable description
            // is noise and is skipped (never insert a null/empty description).
            $description = $this->cleanStr($d['description'] ?? '', 65000);
            if ($description === null) {
                continue;
            }
            $flags[] = [
                'severity'          => $this->severityFor($type, $aiSeverity),
                'divergence_type'   => $type,
                'location'          => $this->cleanStr($d['location'] ?? null, 255),
                'description'       => $description,
                'source_snippet'    => $this->cleanStr($d['source_snippet'] ?? null, 65000),
                'extracted_snippet' => $this->cleanStr($d['extracted_snippet'] ?? null, 65000),
            ];
            if (count($flags) >= $max) {
                break;
            }
        }
        return $flags;
    }

    /**
     * Run-level status from a set of flag arrays (all pending at verify time).
     */
    public function statusFromFlagData(array $flags): string
    {
        $hasPendingHigh = false;
        $hasLow = false;
        foreach ($flags as $f) {
            if (($f['severity'] ?? '') === 'high') {
                $hasPendingHigh = true;
            } elseif (($f['severity'] ?? '') === 'low') {
                $hasLow = true;
            }
        }
        if ($hasPendingHigh) {
            return self::STATUS_BLOCKED;
        }
        return $hasLow ? self::STATUS_WARNINGS : self::STATUS_PASSED;
    }

    /**
     * Recompute the run-level status from LIVE (DB) flags incl. their
     * resolution state. Used after a human resolves a flag.
     *
     * @param  \Illuminate\Support\Collection<int,\App\Models\Docuperfect\CdsExtractionFlag> $flags
     */
    public function statusFromLiveFlags($flags): string
    {
        $hasHigh = false;
        $hasPendingHigh = false;
        $hasLow = false;
        foreach ($flags as $f) {
            if ($f->severity === 'high') {
                $hasHigh = true;
                if ($f->status === \App\Models\Docuperfect\CdsExtractionFlag::STATUS_PENDING) {
                    $hasPendingHigh = true;
                }
            } elseif ($f->severity === 'low') {
                $hasLow = true;
            }
        }
        if ($hasPendingHigh) {
            return self::STATUS_BLOCKED;
        }
        if ($hasHigh) {
            return self::STATUS_CLEARED; // had high flags, all now resolved
        }
        return $hasLow ? self::STATUS_WARNINGS : self::STATUS_PASSED;
    }

    private function severityFor(string $type, string $aiSeverity): string
    {
        $map = (array) config('docuperfect.import.fidelity.severity_map', []);
        if (isset($map[$type])) {
            return $map[$type] === 'low' ? 'low' : 'high';
        }
        if (in_array($aiSeverity, ['high', 'low'], true)) {
            return $aiSeverity;
        }
        return ((string) config('docuperfect.import.fidelity.default_severity', 'high')) === 'low' ? 'low' : 'high';
    }

    private function cleanStr(mixed $v, int $max): ?string
    {
        if (!is_scalar($v)) {
            return null;
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        return mb_substr($s, 0, $max);
    }

    /** Defensive JSON parse: strip fences, locate the first object. */
    private function parseJson(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are an extraction-fidelity auditor for South African real-estate legal
documents in the CoreX OS e-signature system. You are given (1) the ORIGINAL PDF
and (2) the TEXT that was automatically extracted from it for building an
editable template. Your job is to find places where the extraction DIVERGES from
the original, so a human can review only those spots.

Compare the original PDF against the extracted text and report every divergence.
Look specifically for:
  - missing_clause      : a clause/sentence/paragraph in the PDF absent from the extract
  - dropped_content     : any other dropped text (headings, labels, list items)
  - reordered / scrambled_order : content appearing in a different order than the PDF
  - merged_columns      : two columns flattened into one run of text
  - lost_linebreaks     : line/paragraph breaks lost (paragraphs run together)
  - heading_absorbed    : a heading merged into body text
  - mangled_table       : table structure garbled
  - mangled_numbers     : numbers/amounts/dates altered or merged
  - misplaced_marker    : an insertable-block marker (~~~~OTHER_CONDITIONS~~~~ etc.)
                          landing at a position that does NOT match a real block in the PDF
  - whitespace / formatting / minor : cosmetic only

For EACH divergence return an object with:
  type              : one of the keys above
  severity          : "high" for content/order/structure problems, "low" for cosmetic
  location          : clause number, heading, or page (e.g. "clause 5.2" / "page 2")
  description       : one short sentence
  source_snippet    : the relevant text as it appears in the PDF (<= 200 chars)
  extracted_snippet : the corresponding extracted text (<= 200 chars)

If the extraction is faithful, return an empty list. Do not invent divergences.
PROMPT;
    }

    private function userPrompt(array $cds): string
    {
        $extracted = (string) ($cds['original_text'] ?? '');
        $extracted = mb_substr($extracted, 0, 24000);

        $markers = [];
        foreach (($cds['sections'] ?? []) as $i => $section) {
            foreach (($section['content'] ?? []) as $item) {
                if (($item['type'] ?? '') === 'insertable_block_placeholder') {
                    $markers[] = 'section ' . $i . ': ~~~~' . ($item['raw_token'] ?? $item['block_id'] ?? '') . '~~~~';
                }
            }
        }
        $markerLine = empty($markers)
            ? 'No insertable-block markers were detected in the extract.'
            : "Insertable-block markers detected in the extract:\n - " . implode("\n - ", $markers);

        return "Here is the TEXT extracted from the attached PDF.\n\n"
            . "=== EXTRACTED TEXT ===\n" . $extracted . "\n=== END EXTRACTED TEXT ===\n\n"
            . $markerLine . "\n\n"
            . "Compare the attached PDF against this extracted text and return the "
            . "divergences as JSON: {\"divergences\":[ {type,severity,location,description,"
            . "source_snippet,extracted_snippet}, ... ]}. JSON only.";
    }
}
