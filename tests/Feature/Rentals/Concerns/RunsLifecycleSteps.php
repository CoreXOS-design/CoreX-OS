<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals\Concerns;

use PHPUnit\Framework\Assert;

/**
 * The step ledger of the rental-lifecycle end-to-end tests.
 *
 * A normal test stops at its first failed assertion, which would hide every step after it. The lifecycle tests are ONE long
 * chain (listing -> application -> lease -> signed -> inspection -> fault -> work order -> closed) owned by several lanes, so a
 * break in the middle must say WHICH step broke, WHOSE it is, and what it took down with it - and the steps that do not depend
 * on it must still run. Each step() therefore runs inside a catch: a failure is recorded against its id and owner, and any later
 * step that lists it in $needs is recorded as BLOCKED (not run) instead of failing for a reason that is not its own. The test
 * ends with finishLifecycle(), which writes the ledger to storage/logs/lifecycle-<test>.txt and FAILS with the table when any
 * step is not a PASS - so the chain is red the moment any lane breaks it, and the red message is the hand-over note.
 *
 * Nothing is mocked here: a step is whatever real request / service call the test body makes. (Mail and notifications are
 * faked and asserted by the steps themselves.)
 */
trait RunsLifecycleSteps
{
    /** @var array<string, array{label:string, owner:string, status:string, detail:string}> */
    private array $ledger = [];

    /**
     * @param string        $id     e.g. '3.2' - unique in the chain; later steps name it in $needs
     * @param string        $owner  the lane that fixes it: cc1 leases/notice, cc3 rental applications, cc4 inspections, cc6 faults/work orders/portal
     * @param array<string> $needs  step ids that must have passed for this one to be meaningful
     * @return mixed the step's return value (null when it failed or was blocked)
     */
    protected function step(string $id, string $label, string $owner, callable $fn, array $needs = []): mixed
    {
        foreach ($needs as $need) {
            if (($this->ledger[$need]['status'] ?? 'MISSING') !== 'PASS') {
                $this->ledger[$id] = ['label' => $label, 'owner' => $owner, 'status' => 'BLOCKED', 'detail' => "needs step {$need} (" . ($this->ledger[$need]['label'] ?? 'not run') . ')'];

                return null;
            }
        }

        try {
            $result = $fn();
            $this->ledger[$id] = ['label' => $label, 'owner' => $owner, 'status' => 'PASS', 'detail' => ''];

            return $result;
        } catch (\Throwable $e) {
            $this->ledger[$id] = ['label' => $label, 'owner' => $owner, 'status' => 'FAIL', 'detail' => $this->whereItBroke($e)];

            return null;
        }
    }

    /** First line of the message plus the file:line inside THIS test (or the nearest frame in the test tree). */
    private function whereItBroke(\Throwable $e): string
    {
        $message = trim(strtok($e->getMessage(), "\n") ?: get_class($e));
        $message = mb_substr($message, 0, 230);
        $at = null;
        foreach (array_merge([['file' => $e->getFile(), 'line' => $e->getLine()]], $e->getTrace()) as $frame) {
            if (isset($frame['file']) && str_contains($frame['file'], '/tests/Feature/Rentals/')) {
                $at = basename($frame['file']) . ':' . ($frame['line'] ?? '?');
                break;
            }
        }
        $origin = $e->getFile() && ! str_contains($e->getFile(), '/tests/') && ! str_contains($e->getFile(), '/vendor/phpunit/')
            ? ' [thrown in ' . str_replace(base_path() . '/', '', $e->getFile()) . ':' . $e->getLine() . ']' : '';

        return $message . ($at ? "  (test {$at})" : '') . $origin;
    }

    /** Write the ledger and fail the test when anything is not a PASS. Call as the LAST line of every lifecycle test. */
    protected function finishLifecycle(string $name): void
    {
        $lines = [];
        $bad = 0;
        foreach ($this->ledger as $id => $row) {
            $lines[] = sprintf('%-6s %-7s %-4s %s%s', $id, $row['status'], $row['owner'], $row['label'], $row['detail'] !== '' ? "\n         -> " . $row['detail'] : '');
            $bad += $row['status'] === 'PASS' ? 0 : 1;
        }
        $table = "LIFECYCLE LEDGER - {$name}\n" . implode("\n", $lines) . "\n";
        @file_put_contents(storage_path('logs/lifecycle-' . preg_replace('/[^a-z0-9]+/i', '-', $name) . '.txt'), $table);

        Assert::assertSame(0, $bad, "{$bad} lifecycle step(s) did not pass:\n" . $table);
    }
}
