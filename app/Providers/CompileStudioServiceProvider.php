<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Docuperfect\Compiler\Contracts\BindingSuggester;
use App\Services\Docuperfect\Compiler\Contracts\CompileDraftManager;
use App\Services\Docuperfect\Compiler\Contracts\CompilePipeline;
use App\Services\Docuperfect\Compiler\Contracts\SegmentationService;
use App\Services\Docuperfect\Compiler\Ingest\DeterministicSegmenter;
use App\Services\Docuperfect\Compiler\Pipeline\CompileDraftService;
use App\Services\Docuperfect\Compiler\Pipeline\CompileGatePipeline;
use App\Services\Docuperfect\Compiler\Pipeline\HeuristicBindingSuggester;
use Illuminate\Support\ServiceProvider;

/**
 * AT-177 / WS4-S — the Compile Studio (WS4-S, cc1) consumes cc2's WS4-E engine through the
 * declared contracts. cc2 ships the concrete implementations but no container bindings, so the
 * Studio — as the consumer — wires each contract to cc2's implementation here.
 *
 * `bindIf` is deliberate: if WS4-E later ships its own binding (a dedicated provider), that
 * concrete binding wins and this becomes a no-op — no conflict, no rework at the gate.
 *
 * `IngestorRegistry` is a concrete class with an all-defaults constructor, so the container
 * auto-resolves it — no binding needed; the Studio injects it directly for `for($mime)`.
 */
class CompileStudioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(CompilePipeline::class, CompileGatePipeline::class);
        $this->app->bindIf(CompileDraftManager::class, CompileDraftService::class);
        $this->app->bindIf(SegmentationService::class, DeterministicSegmenter::class);
        $this->app->bindIf(BindingSuggester::class, HeuristicBindingSuggester::class);
    }
}
