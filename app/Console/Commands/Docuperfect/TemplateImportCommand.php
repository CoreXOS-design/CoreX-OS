<?php

namespace App\Console\Commands\Docuperfect;

use App\Models\Agency;
use App\Models\User;
use App\Services\Docuperfect\Release\TemplateReleaseException;
use App\Services\Docuperfect\Release\TemplateReleaseService;
use App\Services\Docuperfect\TemplateTransfer\TemplatePackageImporter;
use App\Services\Docuperfect\TemplateTransfer\TemplateTransferException;
use Illuminate\Console\Command;

/**
 * .ai/specs/esign-template-transfer.md §13.9 — import a .cxpkg from the terminal, with a dry run that writes NOTHING.
 *
 *   --dry-run   load + validate + preview: says exactly what an import would create (field definitions, groups, the name clash, the
 *               lease link, whether the agency exists on THIS system). No row, no file, no log entry.
 *   --release   land it NOT live, awaiting the agency's approval (TemplateReleaseService::releaseFromPackage) — the way a document
 *               moves from QA1 to live. Without it the template is imported live, as the Template Packages screen does.
 *
 *   php artisan templates:import {file} --agency=ID [--actor=OWNER_USER_ID] [--dry-run] [--release] [--allow-no-document-type]
 * A name already used in the agency becomes "<name> v2"; nothing is ever replaced. Any refusal is printed in plain words and exits 1.
 */
class TemplateImportCommand extends Command
{
    protected $signature = 'templates:import {file : path to the .cxpkg} {--agency= : agencies.id on THIS system} {--actor= : the CoreX owner\'s user id (needed to import for real)} {--dry-run : only say what would happen; write nothing} {--release : land it awaiting the agency\'s approval instead of live} {--allow-no-document-type : import without a document type when this system does not have it}';

    protected $description = 'Import an e-sign template package (use --dry-run to see exactly what would happen without writing anything)';

    public function handle(TemplatePackageImporter $importer, TemplateReleaseService $releases): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file) || ! is_readable($file)) {
            $this->error("The file \"{$file}\" does not exist or cannot be read.");

            return self::FAILURE;
        }

        $agency = $this->option('agency') ? Agency::withoutGlobalScopes()->find((int) $this->option('agency')) : null;
        if ($this->option('agency') && ! $agency) {
            $this->error('There is no agency with that number on this system.');

            return self::FAILURE;
        }

        try {
            $loaded = $importer->load($file);
            $previews = $importer->preview($loaded, $agency);
        } catch (TemplateTransferException $e) {
            $this->error(trim($e->getMessage()));
            foreach ($e->details as $d) {
                $this->line('  · ' . $d);
            }

            return self::FAILURE;
        }

        $blocked = false;
        foreach ($previews as $p) {
            $this->line('Package: ' . $p['name'] . ' (' . $p['render_type'] . ', ' . ($p['category'] ?? 'no category') . ', from ' . ($p['source_label'] ?? 'unknown') . ')');
            $this->line('  signers: ' . (implode(', ', $p['roles']) ?: 'none') . ' · pages: ' . $p['page_count'] . ' · tags: ' . $p['tag_count']);
            $this->line('  document type: ' . ($p['document_type']['slug'] ? $p['document_type']['slug'] . ' (' . $p['document_type']['status'] . ')' : 'none'));
            $this->line('  field definitions: ' . count($p['fields_existing']) . ' already here, ' . count($p['fields_new']) . ' would be created' . ($p['fields_new'] ? ' (' . implode(', ', $p['fields_new']) . ')' : ''));
            $this->line('  field groups: ' . count($p['groups_existing']) . ' already here, ' . count($p['groups_new']) . ' would be created');
            if ($p['esign_switched_off']) {
                $this->line('  e-signing would be switched OFF (a document type that needs an admin acknowledgement).');
            }
            $this->line('  lease agreement link: ' . ($p['lease_link'] ? $p['lease_link']['category'] . ', ' . $p['lease_link']['mappings'] . ' field mapping(s) — applied only when the agency approves a release' : 'none carried'));
            if ($agency) {
                $this->line('  agency: ' . $agency->name . ' (#' . $agency->id . ')' . ($p['clash']['exists'] ? ' — it already has a template with this name, so this would become "' . $p['name'] . ' v2"' : ''));
            } else {
                $this->line('  agency: not chosen (pass --agency=<id> to preview the name clash and field groups there)');
            }
            foreach ($p['warnings'] as $w) {
                $this->line('  warning: ' . $w);
            }
            foreach ($p['needs_ack'] as $n) {
                $this->line('  needs --allow-no-document-type: ' . $n);
            }
            foreach ($p['blocking'] as $b) {
                $this->error('  BLOCKED: ' . $b);
                $blocked = true;
            }
        }

        if ($this->option('dry-run')) {
            $this->info('DRY RUN — nothing was written.');

            return $blocked ? self::FAILURE : self::SUCCESS;
        }
        if ($blocked) {
            return self::FAILURE;
        }
        if (! $agency) {
            $this->error('Choose the agency with --agency=<id> (or use --dry-run to only look).');

            return self::FAILURE;
        }
        $actor = $this->option('actor') ? User::withoutGlobalScopes()->find((int) $this->option('actor')) : null;
        if (! $actor || ! $actor->isOwnerRole()) {
            $this->error('Importing for real needs --actor=<the CoreX owner\'s user id>.');

            return self::FAILURE;
        }

        try {
            if ($this->option('release')) {
                foreach ($loaded as $pkg) {
                    $r = $releases->releaseFromPackage($pkg, $agency, $actor, (bool) $this->option('allow-no-document-type'));
                    $this->info('Released to ' . $agency->name . ' as template #' . $r->template_id . ' (version ' . $r->version . '), waiting for approval. It is not live until the agency approves it.');
                }
            } else {
                $choices = [];
                foreach ($previews as $p) {
                    if ($p['clash']['exists']) {
                        $choices[$p['index']] = TemplatePackageImporter::CHOICE_NEW_VERSION;
                    }
                }
                foreach ($importer->import($loaded, $agency, $actor, $choices, (bool) $this->option('allow-no-document-type')) as $t) {
                    $this->info('Imported into ' . $agency->name . ' as template #' . $t->id . ' "' . $t->name . '" (live).');
                }
            }
        } catch (TemplateTransferException|TemplateReleaseException $e) {
            $this->error(trim($e->getMessage()));
            foreach ($e->details as $d) {
                $this->line('  · ' . $d);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
