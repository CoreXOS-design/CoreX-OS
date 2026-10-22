<?php

namespace App\Console\Commands\Compliance;

use App\Models\Agency;
use App\Services\Compliance\RmcpVersionImporter;
use Illuminate\Console\Command;

/**
 * AT-506 — load the approved RMCP text into CoreX as a NEW DRAFT version for one agency.
 *
 *   php artisan rmcp:import-version 1                      # dry run (default): what would be created, what needs attention
 *   php artisan rmcp:import-version 1 --apply              # create the draft
 *
 * Idempotent: if the agency already has that version number, nothing is changed. The existing active version is never
 * touched — the draft is approved in RMCP Manager (RmcpController::approve), which supersedes it. Per-agency facts the
 * text cannot get from the company profile are stated with --var=key=value (created only when the agency has no value yet).
 */
class RmcpImportVersion extends Command
{
    protected $signature = 'rmcp:import-version
        {agency : Agency id or slug}
        {--number=2 : Version number to create}
        {--file=database/data/rmcp/v2.md : Source markdown, relative to the app root}
        {--var=* : Manual RMCP variable to set when the agency has none, as key=value (repeatable)}
        {--user= : User id recorded as the draft\'s creator}
        {--apply : Actually create the draft (without it this is a dry run)}';

    protected $description = 'Load an approved RMCP text into an agency as a new DRAFT version (dry run unless --apply).';

    public function handle(RmcpVersionImporter $importer): int
    {
        $ref = (string) $this->argument('agency');
        $agency = ctype_digit($ref)
            ? Agency::withoutGlobalScopes()->find((int) $ref)
            : Agency::withoutGlobalScopes()->where('slug', $ref)->first();
        if (! $agency) {
            $this->error("No agency \"{$ref}\".");

            return self::FAILURE;
        }

        $file = base_path((string) $this->option('file'));
        if (! is_file($file)) {
            $this->error("Source file not found: {$file}");

            return self::FAILURE;
        }

        $vars = [];
        foreach ((array) $this->option('var') as $pair) {
            if (! str_contains((string) $pair, '=')) {
                $this->error("--var expects key=value, got \"{$pair}\".");

                return self::FAILURE;
            }
            [$k, $v] = explode('=', (string) $pair, 2);
            $k = trim($k);
            if (! preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $k)) {
                $this->error("Variable key \"{$k}\" must look like group.name (lowercase letters, digits, underscores).");

                return self::FAILURE;
            }
            $vars[$k] = $v;
        }

        $number = (int) $this->option('number');
        $markdown = (string) file_get_contents($file);

        try {
            $plan = $importer->plan($agency, $number, $markdown);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->line(($apply ? 'APPLY' : 'DRY RUN') . " — RMCP v{$number} for agency #{$agency->id} {$agency->name}");
        $this->line('Source: ' . $this->option('file') . " — \"{$plan['title']}\", " . count($plan['sections']) . ' sections');

        $rows = [];
        foreach ($plan['sections'] as $i => $s) {
            $rows[] = [$i + 1, $s['type'], $s['number'], mb_strimwidth($s['title'], 0, 52, '…'), $s['ack'] ? 'yes' : '—'];
        }
        $this->table(['#', 'Type', 'Number', 'Title', 'Staff must acknowledge'], $rows);

        $this->line('Merge fields in the text, and what each is for this agency now:');
        foreach ($plan['tokens'] as $key => $value) {
            // An ID number never goes to a console or a log in full: show it the way the FICA form does (**** + last four).
            $shown = str_ends_with($key, 'id_number') && strlen($value) > 4 ? '****' . substr($value, -4) : mb_strimwidth($value, 0, 70, '…');
            $note = in_array($key, $plan['at_approval'], true) ? '(filled when the version is approved)' : ($value === '' ? '*** BLANK ***' : $shown);
            $this->line(sprintf('  {{%s}}  %s', $key, $note));
        }
        foreach ($vars as $k => $v) {
            $this->line("  will set when empty: {$k} = " . mb_strimwidth($v, 0, 70, '…'));
        }
        if ($plan['unknown'] !== []) {
            $this->error('UNKNOWN merge fields (nothing fills them): ' . implode(', ', $plan['unknown']));
        }
        $blankAfterVars = array_values(array_diff($plan['blank'], array_keys($vars)));
        if ($blankAfterVars !== []) {
            $this->warn('Blank for this agency (fill in the company profile or add the variable first): ' . implode(', ', $blankAfterVars));
        }

        if ($plan['exists']) {
            $this->warn("Version {$number} already exists for this agency ({$plan['existing_status']}) — it is left exactly as it is.");
            if ($apply && $vars !== []) {
                // The stated variables are still filled in where the agency has none (never overwritten).
                $result = $importer->applyVariables($agency, $vars);
                $this->line('Variables set: ' . ($result['variables_created'] !== [] ? implode(', ', $result['variables_created']) : '(none)'));
                if ($result['variables_kept'] !== []) {
                    $this->line('Variables left as the agency has them: ' . implode(', ', $result['variables_kept']));
                }
            }

            return self::SUCCESS;
        }
        if ($plan['unknown'] !== []) {
            $this->error('Fix the unknown merge fields first.');

            return self::FAILURE;
        }
        if (! $apply) {
            $this->info('Dry run only — nothing was written. Add --apply to create the draft.');

            return self::SUCCESS;
        }

        $result = $importer->import($agency, $number, $markdown, $this->option('user') ? (int) $this->option('user') : null, $vars, (string) $this->option('file'));

        $v = $result['version'];
        $this->info("Created RMCP v{$v->version_number} (version id {$v->id}) as a DRAFT with " . $v->sections()->count() . ' sections.');
        if ($result['variables_created'] !== []) {
            $this->line('Variables set: ' . implode(', ', $result['variables_created']));
        }
        if ($result['variables_kept'] !== []) {
            $this->line('Variables left as the agency has them: ' . implode(', ', $result['variables_kept']));
        }
        $this->line("View/print: /corex/compliance/rmcp-manager/{$v->id}  ·  PDF: /corex/compliance/rmcp-manager/{$v->id}/pdf");

        return self::SUCCESS;
    }
}
