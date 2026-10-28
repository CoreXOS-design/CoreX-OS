<?php

namespace App\Console\Commands\Docuperfect;

use App\Models\Docuperfect\Template;
use App\Services\Docuperfect\Readiness\TemplateReadinessReport;
use Illuminate\Console\Command;

/**
 * .ai/specs/esign-template-readiness.md §3 — the same readiness checks as the screen, for the preparer's terminal and for rehearsals.
 * Read-only. Exit code 1 when the verdict is not_ready (or the template does not exist).
 *
 *   php artisan templates:readiness {template_id} [--json]
 */
class TemplateReadinessCommand extends Command
{
    protected $signature = 'templates:readiness {template_id : docuperfect_templates.id} {--json : print the result as JSON}';

    protected $description = 'Read-only readiness check of one e-sign template (is it ready to be used for a real signing?)';

    public function handle(TemplateReadinessReport $report): int
    {
        $template = Template::withoutGlobalScopes()->withTrashed()->find((int) $this->argument('template_id'));
        if (! $template) {
            $this->error('There is no template with that number.');

            return self::FAILURE;
        }

        $result = $report->forTemplate($template);
        $checks = TemplateReadinessReport::sorted($result['checks']);

        if ($this->option('json')) {
            $this->line(json_encode(['template' => ['id' => $template->id, 'name' => $template->name]] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $result['verdict'] === TemplateReadinessReport::VERDICT_NOT_READY ? self::FAILURE : self::SUCCESS;
        }

        $this->line("Template #{$template->id}: {$template->name}");
        $this->line('Verdict: ' . $result['verdict'] . '  (fail ' . $result['counts']['fail'] . ', warn ' . $result['counts']['warn'] . ', info ' . $result['counts']['info'] . ', pass ' . $result['counts']['pass'] . ')');
        foreach ($checks as $c) {
            $this->line(sprintf('  [%-4s] %s %s — %s', strtoupper($c['status']), $c['id'], $c['title'], $c['detail']));
            foreach ($c['items'] as $item) {
                $this->line('           · ' . $item);
            }
            if ($c['action'] !== '' && in_array($c['status'], ['fail', 'warn'], true)) {
                $this->line('           → ' . $c['action']);
            }
        }

        return $result['verdict'] === TemplateReadinessReport::VERDICT_NOT_READY ? self::FAILURE : self::SUCCESS;
    }
}
