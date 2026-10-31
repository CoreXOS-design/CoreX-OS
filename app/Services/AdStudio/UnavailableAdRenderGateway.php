<?php

namespace App\Services\AdStudio;

/** AT-527 - bound until cc1's renderer adapter exists: nothing is rendered and the reason is one plain sentence. */
final class UnavailableAdRenderGateway implements AdRenderGateway
{
    public function queue(array $request): string
    {
        throw new \RuntimeException('The video renderer is not installed on this server yet.');
    }

    public function status(string $batchId): array
    {
        return ['state' => 'failed', 'percent' => 0, 'stage' => null, 'failure' => 'The video renderer is not installed on this server yet.', 'files' => []];
    }
}
