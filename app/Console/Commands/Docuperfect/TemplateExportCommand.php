<?php

namespace App\Console\Commands\Docuperfect;

use App\Models\Docuperfect\Template;
use App\Models\User;
use App\Services\Docuperfect\TemplateTransfer\TemplatePackageExporter;
use App\Services\Docuperfect\TemplateTransfer\TemplateTransferException;
use Illuminate\Console\Command;

/**
 * .ai/specs/esign-template-transfer.md §13.9 — export one template as a .cxpkg from the terminal (the same package the Export button makes:
 * wording, fields, signers, settings, and now the lease link and field map). Read-only on the template; writes only the package file.
 * No audit row unless --audit (then --actor is the CoreX owner who is doing it).
 *
 *   php artisan templates:export {template_id} [--out=DIR] [--audit] [--actor=USER_ID]
 */
class TemplateExportCommand extends Command
{
    protected $signature = 'templates:export {template_id : docuperfect_templates.id} {--out= : directory for the .cxpkg (default: the current directory)} {--audit : write the transfer-log row and fire the export event} {--actor= : user id of the owner doing it (needed by --audit)}';

    protected $description = 'Export one e-sign template as a .cxpkg package (read-only; no audit row unless --audit)';

    public function handle(TemplatePackageExporter $exporter): int
    {
        $template = Template::withoutGlobalScopes()->find((int) $this->argument('template_id'));
        if (! $template) {
            $this->error('There is no template with that number.');

            return self::FAILURE;
        }

        $audit = (bool) $this->option('audit');
        $actor = $this->option('actor') ? User::withoutGlobalScopes()->find((int) $this->option('actor')) : User::withoutGlobalScopes()->get()->first(fn (User $u) => $u->isOwnerRole());
        if (! $actor || ($audit && ! $this->option('actor'))) {
            $this->error($audit ? '--audit needs --actor=<the owner\'s user id>.' : 'No owner user was found to attribute the export to; pass --actor=<user id>.');

            return self::FAILURE;
        }

        $dir = rtrim((string) ($this->option('out') ?: getcwd()), '/');
        if (! is_dir($dir) || ! is_writable($dir)) {
            $this->error("The folder \"{$dir}\" does not exist or cannot be written to.");

            return self::FAILURE;
        }

        try {
            $pkg = $exporter->export($template, $actor, $audit);
        } catch (TemplateTransferException $e) {
            $this->error(trim($e->getMessage() . ' ' . implode(' ', $e->details)));

            return self::FAILURE;
        }

        $path = $dir . '/' . $pkg['filename'];
        file_put_contents($path, $pkg['bytes']);
        $this->info("Wrote {$path} ({$template->name}; " . strlen($pkg['bytes']) . ' bytes; checksum ' . $pkg['checksum'] . ')');
        foreach ($pkg['warnings'] as $w) {
            $this->warn('  warning: ' . $w);
        }

        return self::SUCCESS;
    }
}
