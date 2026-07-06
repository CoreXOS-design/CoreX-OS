<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Compiler\Serving;

use App\Models\Docuperfect\CompiledTemplate;
use App\Models\Docuperfect\Template;

/**
 * AT-177 / WS6 — resolves whether a legacy Docuperfect template is CUT OVER to compiled serving,
 * and to which published compiled version (spec §8.3, §9).
 *
 * A template serves compiled iff `compiled_serving` is on AND `compiled_family` names a published
 * CoreX-standard `compiled_templates` version. Otherwise this returns null and the caller serves
 * via the untouched legacy path — dual-path coexistence.
 */
final class CompiledServingResolver
{
    public function isCompiledServing(Template $template): bool
    {
        return (bool) $template->compiled_serving && ! empty($template->compiled_family);
    }

    /** The published compiled template this legacy template is cut over to, or null. */
    public function resolve(Template $template): ?CompiledTemplate
    {
        if (! $this->isCompiledServing($template)) {
            return null;
        }

        return CompiledTemplate::query()
            ->standard()
            ->published()
            ->family((string) $template->compiled_family)
            ->orderByDesc('version')
            ->first();
    }
}
