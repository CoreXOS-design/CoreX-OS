<?php

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Models\Compliance\RmcpSection;
use App\Models\Compliance\RmcpVariable;
use App\Models\Compliance\RmcpVersion;
use InvalidArgumentException;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * AT-506 — loads an approved RMCP text (database/data/rmcp/v2.md) into CoreX as a NEW DRAFT version for one agency.
 *
 * The text is the agency-neutral master: per-agency facts are {{merge tokens}} that RmcpVariableResolver fills from the
 * agency's own profile when the section is shown or printed. The importer never touches an existing version: it only
 * creates version N as a draft (and says so, doing nothing, when N already exists). The version is approved through the
 * normal RmcpController::approve flow, which sets approved_at / effective_from and supersedes the active version.
 *
 * Source format: see the comment at the top of the .md file.
 */
class RmcpVersionImporter
{
    /** Schedules / annexures (besides the numbered sections 2+) whose staff acknowledgement step is required — as in v1. */
    private const ACK_REQUIRED_EXTRAS = ['Schedule 8', 'Annexure A'];

    public function __construct(private readonly RmcpVariableResolver $resolver) {}

    /**
     * Parse the source into a title and ordered sections (HTML bodies with the merge tokens still in them).
     *
     * @return array{title:string, sections:list<array{type:string,number:string,title:string,html:string,ack:bool,prompt:?string}>}
     */
    public function parse(string $markdown): array
    {
        // Drop the explanatory comment block(s) — anything inside <!-- ... --> that sits on its own.
        $markdown = preg_replace('/<!--.*?-->\s*/s', '', str_replace("\r\n", "\n", $markdown));

        if (! preg_match('/^#\s+(.+?)\s*$/m', $markdown, $t)) {
            throw new InvalidArgumentException('The source has no "# Title — Version N" heading.');
        }
        $title = trim(preg_replace('/\s+[—–-]\s+Version\s+\d+\s*$/u', '', $t[1]));

        $parts = preg_split('/^##\s+(.+?)\s*$/m', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);
        // $parts[0] is the cover (before the first "## "); then heading, body, heading, body …
        $converter = new GithubFlavoredMarkdownConverter(['html_input' => 'allow', 'allow_unsafe_links' => false]);

        $sections = [];
        for ($i = 1; $i < count($parts); $i += 2) {
            [$type, $number, $sectionTitle] = $this->classify(trim($parts[$i]));
            $html = trim((string) $converter->convert(trim($parts[$i + 1] ?? '')));

            $ack = ($type === 'section' && ctype_digit($number) && (int) $number >= 2)
                || in_array($number, self::ACK_REQUIRED_EXTRAS, true);

            $sections[] = [
                'type'   => $type,
                'number' => $number,
                'title'  => $sectionTitle,
                'html'   => $html,
                'ack'    => $ack,
                'prompt' => $ack ? $this->prompt($number, $sectionTitle) : null,
            ];
        }

        if ($sections === []) {
            throw new InvalidArgumentException('The source has no "## " sections.');
        }

        return ['title' => $title, 'sections' => $sections];
    }

    /** @return array{0:string,1:string,2:string} [section_type, section_number, title] */
    private function classify(string $heading): array
    {
        if (preg_match('/^(\d+)\.\s+(.+)$/u', $heading, $m)) {
            return ['section', $m[1], trim($m[2])];
        }
        if (preg_match('/^(Schedules?\s+[\d–-]+)\.\s+(.+)$/u', $heading, $m)) {
            return ['schedule', $m[1], trim($m[2])];
        }
        if (preg_match('/^(Annexure\s+[A-Z])\.\s+(.+)$/u', $heading, $m)) {
            // The staff acknowledgement is the declaration the e-sign step shows (RmcpAcknowledgementController::sign).
            $type = strcasecmp(trim($m[2]), 'Staff acknowledgement') === 0 ? 'acknowledgement' : 'annexure';

            return [$type, $m[1], trim($m[2])];
        }
        if (strcasecmp($heading, 'Approval') === 0) {
            return ['section', 'Approval', 'Approval'];
        }

        throw new InvalidArgumentException("Cannot place the heading \"{$heading}\" — expected \"N. Title\", \"Schedule N. Title\", \"Annexure X. Title\" or \"Approval\".");
    }

    private function prompt(string $number, string $title): string
    {
        $label = ctype_digit($number) ? "section {$number}" : $number;

        return mb_substr("I have read and understood {$label} ({$title}).", 0, 500);
    }

    /**
     * A read-only plan: what would be imported for this agency, and what in it needs attention.
     *
     * @return array{title:string, sections:list<array>, exists:bool, existing_status:?string,
     *               tokens:array<string,string>, blank:list<string>, unknown:list<string>, at_approval:list<string>}
     */
    public function plan(Agency $agency, int $versionNumber, string $markdown): array
    {
        $parsed = $this->parse($markdown);

        $existing = RmcpVersion::withoutGlobalScopes()
            ->where('agency_id', $agency->id)->where('version_number', $versionNumber)->first();

        // Tokens used, and what each resolves to for THIS agency right now.
        $variables = $this->resolver->resolve($agency);
        $used = [];
        foreach ($parsed['sections'] as $s) {
            if (preg_match_all('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', $s['html'], $m)) {
                foreach ($m[1] as $key) {
                    $used[$key] = $variables[$key] ?? null;
                }
            }
        }
        ksort($used);

        // rmcp.* values only exist once the version is approved (they are filled then); the rest should resolve.
        $atApproval = array_values(array_filter(array_keys($used), fn ($k) => str_starts_with($k, 'rmcp.')));
        $unknown = array_values(array_filter(array_keys($used), fn ($k) => ! array_key_exists($k, $variables) && ! in_array($k, $atApproval, true)));
        $blank = array_values(array_filter(array_keys($used), fn ($k) => array_key_exists($k, $variables) && trim((string) $variables[$k]) === ''));

        return [
            'title'           => $parsed['title'],
            'sections'        => $parsed['sections'],
            'exists'          => $existing !== null,
            'existing_status' => $existing?->status,
            'tokens'          => array_map(fn ($v) => $v === null ? '' : (string) $v, $used),
            'blank'           => $blank,
            'unknown'         => $unknown,
            'at_approval'     => $atApproval,
        ];
    }

    /**
     * Create the draft. Idempotent: version N already there → nothing is changed and null is returned.
     * Manual variables (key => value) are created only when the agency has no value for the key yet.
     *
     * @param  array<string,string> $manualVariables
     * @return array{version:?RmcpVersion, variables_created:list<string>, variables_kept:list<string>}
     */
    public function import(Agency $agency, int $versionNumber, string $markdown, ?int $createdBy = null, array $manualVariables = [], string $sourceLabel = 'database/data/rmcp/v2.md'): array
    {
        $exists = RmcpVersion::withoutGlobalScopes()
            ->where('agency_id', $agency->id)->where('version_number', $versionNumber)->exists();
        if ($exists) {
            return ['version' => null, 'variables_created' => [], 'variables_kept' => array_keys($manualVariables)];
        }

        $parsed = $this->parse($markdown);

        return \DB::transaction(function () use ($agency, $versionNumber, $parsed, $createdBy, $manualVariables, $sourceLabel) {
            $version = RmcpVersion::create([
                'agency_id'      => $agency->id,
                'version_number' => $versionNumber,
                'title'          => $parsed['title'],
                'status'         => 'draft',
                'created_by'     => $createdBy,
                'change_notes'   => "Loaded from {$sourceLabel} (AT-506). Approve it in RMCP Manager to make it the active version.",
            ]);

            foreach ($parsed['sections'] as $i => $s) {
                RmcpSection::create([
                    'agency_id'                => $agency->id,
                    'rmcp_version_id'          => $version->id,
                    'section_type'             => $s['type'],
                    'display_order'            => $i + 1,
                    'section_number'           => $s['number'],
                    'title'                    => $s['title'],
                    'body_html'                => $s['html'],
                    'requires_acknowledgement' => $s['ack'],
                    'acknowledgement_prompt'   => $s['prompt'],
                ]);
            }

            $created = [];
            $kept = [];
            foreach ($manualVariables as $key => $value) {
                $row = RmcpVariable::withoutGlobalScopes()->where('agency_id', $agency->id)->where('variable_key', $key)->first();
                if ($row && trim((string) $row->value) !== '') {
                    $kept[] = $key;   // never overwrite what the agency has already set
                    continue;
                }
                RmcpVariable::withoutGlobalScopes()->updateOrCreate(
                    ['agency_id' => $agency->id, 'variable_key' => $key],
                    ['value' => $value, 'data_source' => 'manual']
                );
                $created[] = $key;
            }

            return ['version' => $version, 'variables_created' => $created, 'variables_kept' => $kept];
        });
    }
}
