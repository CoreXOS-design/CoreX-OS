<?php

declare(strict_types=1);

namespace App\Console\Commands\Esign;

use App\Models\Docuperfect\CompiledTemplate;
use App\Services\Docuperfect\Compiler\Linter\CompiledTemplateLinter;
use App\Services\Docuperfect\Compiler\Rendering\CdsRenderParityVerifier;
use App\Services\Docuperfect\Compiler\Support\EloquentDataDictionaryResolver;
use App\Support\Docuperfect\Cds\Cds;
use App\Support\Docuperfect\Cds\Reference\ReferencePackCds;
use Illuminate\Console\Command;
use Throwable;

/**
 * AT-177 / WS5 — publish the campaign's reference pack (templates 117, 119, 116) as immutable,
 * content-hashed, versioned CoreX-standard `compiled_templates` rows (spec §5/§8).
 *
 * For each reference it lints with the LIVE render-parity verifier (so L6 is truly proven, not
 * pending) and, only on a clean gate, freezes it via {@see CompiledTemplate::publishAsNewVersion()}
 * — the real publish path (hash pin · monotonic version · supersede prior · rebuild the thin
 * binding index · emit `TemplatePublished`). Idempotent: a reference whose content is already
 * published (same content_hash) is skipped, so the command is safe to re-run on every deploy.
 *
 * These are PARALLEL artifacts — the existing e-sign runtime is untouched; cutover/retirement
 * stays gated on the §9 map.
 */
class PublishReferencePack extends Command
{
    protected $signature = 'esign:publish-reference-pack {--dry-run : Lint only; report what would publish without writing}';

    protected $description = 'Publish the CoreX-standard e-sign reference pack (116/117/119) as immutable compiled_templates versions.';

    /** family => ReferencePackCds provider method. */
    private const PACK = [
        '117' => 'template117',
        '119' => 'template119',
        '116' => 'template116',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $resolver = new EloquentDataDictionaryResolver();
        $linter = new CompiledTemplateLinter();
        $parity = new CdsRenderParityVerifier();

        $failures = 0;

        foreach (self::PACK as $family => $method) {
            /** @var array<string,mixed> $structure */
            $structure = ReferencePackCds::{$method}();

            try {
                $cds = Cds::fromArray($structure);
            } catch (Throwable $e) {
                $this->error("[{$family}] structure could not be parsed: {$e->getMessage()}");
                $failures++;
                continue;
            }

            $lint = $linter->lint($cds, $resolver, $parity);
            if (! $lint->publishable()) {
                $this->error("[{$family}] lint gate FAILED: " . implode(', ', $lint->failedRules()));
                $failures++;
                continue;
            }

            $hash = $cds->contentHash();
            $existing = CompiledTemplate::query()->standard()->published()->where('content_hash', $hash)->first();
            if ($existing !== null) {
                $this->line("[{$family}] already published as #{$existing->id} v{$existing->version} — skipping (idempotent).");
                continue;
            }

            if ($dry) {
                $this->line("[{$family}] lint clean — WOULD publish (dry-run).");
                continue;
            }

            $template = new CompiledTemplate();
            $template->agency_id = null; // CoreX-standard (Door A)
            $template->family = $family;
            $template->legal_class = (string) ($structure['legal_class'] ?? 'general');
            $template->delivery_modes = (array) ($structure['delivery_modes'] ?? []);
            $template->data_dictionary_version = (int) ($structure['data_dictionary_version'] ?? 1);
            $template->structure = $structure;
            $template->lint_status = CompiledTemplate::LINT_PASSED;
            $template->lint_report = $lint->toArray();
            $template->status = CompiledTemplate::STATUS_DRAFT;
            $template->save();

            $template->publishAsNewVersion();

            $this->info("[{$family}] published as #{$template->id} v{$template->version} (hash " . substr($hash, 0, 12) . ").");
        }

        if ($failures > 0) {
            $this->error("Reference pack: {$failures} template(s) failed the gate and were NOT published.");

            return self::FAILURE;
        }

        $this->info('Reference pack published (or already current).');

        return self::SUCCESS;
    }
}
