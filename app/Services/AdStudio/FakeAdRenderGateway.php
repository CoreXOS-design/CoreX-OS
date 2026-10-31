<?php

namespace App\Services\AdStudio;

/** AT-527 - for tests: records every request and answers status() from whatever the test set. Never renders anything. */
final class FakeAdRenderGateway implements AdRenderGateway
{
    /** @var array<int,array<string,mixed>> */
    public array $queued = [];
    /** @var array<string,array<string,mixed>> */
    public array $statuses = [];
    public ?string $failWith = null;

    public function queue(array $request): string
    {
        if ($this->failWith !== null) {
            throw new \RuntimeException($this->failWith);
        }
        $this->queued[] = $request;

        return 'batch-' . count($this->queued);
    }

    public function status(string $batchId): array
    {
        return $this->statuses[$batchId] ?? ['state' => 'rendering', 'percent' => 10, 'stage' => 'voice', 'failure' => null, 'files' => []];
    }
}
