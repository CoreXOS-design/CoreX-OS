<?php

namespace Tests\Unit\Presentations;

use App\Services\Presentations\PresentationDataQualityService;
use PHPUnit\Framework\TestCase;

class PresentationDataQualityServiceTest extends TestCase
{
    private PresentationDataQualityService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new PresentationDataQualityService();
    }

    // ── Grade computation ───────────────────────────────────────────────

    public function test_grade_a_at_85(): void
    {
        $this->assertSame('A', $this->svc->computeGrade(85.0));
    }

    public function test_grade_a_above_85(): void
    {
        $this->assertSame('A', $this->svc->computeGrade(100.0));
        $this->assertSame('A', $this->svc->computeGrade(92.5));
    }

    public function test_grade_b_at_70(): void
    {
        $this->assertSame('B', $this->svc->computeGrade(70.0));
    }

    public function test_grade_b_between_70_and_85(): void
    {
        $this->assertSame('B', $this->svc->computeGrade(84.99));
        $this->assertSame('B', $this->svc->computeGrade(75.0));
    }

    public function test_grade_c_at_50(): void
    {
        $this->assertSame('C', $this->svc->computeGrade(50.0));
    }

    public function test_grade_c_between_50_and_70(): void
    {
        $this->assertSame('C', $this->svc->computeGrade(69.99));
        $this->assertSame('C', $this->svc->computeGrade(55.0));
    }

    public function test_grade_d_below_50(): void
    {
        $this->assertSame('D', $this->svc->computeGrade(49.99));
        $this->assertSame('D', $this->svc->computeGrade(0.0));
        $this->assertSame('D', $this->svc->computeGrade(25.0));
    }

    public function test_grade_null_when_null(): void
    {
        $this->assertNull($this->svc->computeGrade(null));
    }

    // ── Deterministic ───────────────────────────────────────────────────

    public function test_grade_is_deterministic(): void
    {
        $a = $this->svc->computeGrade(72.5);
        $b = $this->svc->computeGrade(72.5);
        $this->assertSame($a, $b);
    }
}
