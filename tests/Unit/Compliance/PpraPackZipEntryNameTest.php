<?php

declare(strict_types=1);

namespace Tests\Unit\Compliance;

use App\Jobs\GeneratePpraInspectionPackJob;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Audit fix L2: archive entry names must never carry traversal segments. */
final class PpraPackZipEntryNameTest extends TestCase
{
    private function call(string $method, string $arg): string
    {
        $job = new GeneratePpraInspectionPackJob(1);
        $m = new ReflectionMethod($job, $method);
        $m->setAccessible(true);

        return $m->invoke($job, $arg);
    }

    public function test_safe_entry_drops_traversal_segments(): void
    {
        $this->assertSame('etc/passwd', $this->call('safeEntry', '../../etc/passwd'));
        $this->assertSame('a/b.pdf', $this->call('safeEntry', 'a\\..\\b.pdf'));
        $this->assertSame('sales/deal-1/file.pdf', $this->call('safeEntry', 'sales/deal-1/file.pdf'));
    }

    public function test_safe_segment_has_no_separators(): void
    {
        $this->assertSame('..-evil', $this->call('safeSegment', '../evil'));
        $this->assertSame('file', $this->call('safeSegment', '..'));
    }
}
