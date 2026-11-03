<?php

namespace App\Services\AdStudio;

use Illuminate\Container\Attributes\Bind;

/**
 * AT-527 - Ad Studio's one door to the render pipeline (spec .ai/specs/training-videos.md 9.16.1 / 9.16.2). cc1's renderer implements the other side; until its
 * adapter is bound, UnavailableAdRenderGateway says so in a sentence and nothing renders. Tests swap in FakeAdRenderGateway.
 */
#[Bind(UnavailableAdRenderGateway::class)]
interface AdRenderGateway
{
    /**
     * Queue the render of an ad (the AdRenderRequest of 9.16.1) and return the batch id.
     *
     * @param  array<string,mixed>  $request
     * @throws \RuntimeException with ONE plain sentence
     */
    public function queue(array $request): string;

    /**
     * @return array{state: string, percent: int, stage: ?string, failure: ?string, files: array<string,array<string,mixed>>}
     *         state: queued | rendering | ready | failed; files: format => [mp4, poster, seconds, width, height, size] (absolute paths) once ready
     */
    public function status(string $batchId): array;
}
