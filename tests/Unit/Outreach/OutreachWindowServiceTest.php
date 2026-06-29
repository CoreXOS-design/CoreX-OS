<?php

namespace Tests\Unit\Outreach;

use App\Models\Agency;
use App\Services\Leave\PublicHolidayService;
use App\Services\Outreach\OutreachWindowService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * AT-117 §4a — the canonical send-window guard. Pure unit test (no DB): an
 * unsaved Agency carries the cast JSON window; the holiday source is stubbed so
 * we control the holiday axis. Covers the input matrix from the spec: in/out of
 * window, weekday vs Saturday vs Sunday, the boundary edges, a closed/empty
 * config, and the public-holiday override.
 */
class OutreachWindowServiceTest extends TestCase
{
    private function service(bool $isHoliday = false): OutreachWindowService
    {
        $holidays = new class($isHoliday) extends PublicHolidayService {
            public function __construct(private bool $flag) {}
            public function isPublicHoliday(Carbon $date, string $country = 'ZA'): bool
            {
                return $this->flag;
            }
        };
        return new OutreachWindowService($holidays);
    }

    private function agency(?array $window): Agency
    {
        $a = new Agency();
        $a->outreach_send_window = $window; // array cast; null => defaults
        return $a;
    }

    private function at(string $dt): Carbon
    {
        return Carbon::parse($dt, 'Africa/Johannesburg');
    }

    public function test_defaults_resolve_when_column_is_null(): void
    {
        $w = $this->agency(null)->outreachSendWindow();
        $this->assertSame('08:00', $w['mon']['start']);
        $this->assertSame('20:00', $w['mon']['end']);
        $this->assertSame('09:00', $w['sat']['start']);
        $this->assertSame('13:00', $w['sat']['end']);
        $this->assertFalse($w['sun']['enabled']);
        $this->assertTrue($w['public_holidays_off']);
    }

    public function test_weekday_in_and_out_of_window(): void
    {
        $svc = $this->service();
        $a = $this->agency(null);
        $this->assertTrue($svc->isSendAllowed($a, $this->at('2026-06-29 10:00')));  // Mon 10:00
        $this->assertFalse($svc->isSendAllowed($a, $this->at('2026-06-29 21:00'))); // Mon 21:00
        $this->assertFalse($svc->isSendAllowed($a, $this->at('2026-06-29 07:59'))); // before open
    }

    public function test_saturday_and_sunday(): void
    {
        $svc = $this->service();
        $a = $this->agency(null);
        $this->assertTrue($svc->isSendAllowed($a, $this->at('2026-07-04 11:00')));  // Sat 11:00
        $this->assertFalse($svc->isSendAllowed($a, $this->at('2026-07-04 14:00'))); // Sat 14:00
        $this->assertFalse($svc->isSendAllowed($a, $this->at('2026-07-05 10:00'))); // Sun
    }

    public function test_boundary_edges_inclusive(): void
    {
        $svc = $this->service();
        $a = $this->agency(null);
        $this->assertTrue($svc->isSendAllowed($a, $this->at('2026-07-03 08:00'))); // open edge
        $this->assertTrue($svc->isSendAllowed($a, $this->at('2026-07-03 20:00'))); // close edge
    }

    public function test_public_holiday_blocks_even_in_hours(): void
    {
        $svc = $this->service(isHoliday: true);
        $a = $this->agency(null); // public_holidays_off = true by default
        $this->assertFalse($svc->isSendAllowed($a, $this->at('2026-06-29 10:00')));
    }

    public function test_holiday_allowed_when_flag_off(): void
    {
        $svc = $this->service(isHoliday: true);
        $a = $this->agency(['public_holidays_off' => false]);
        $this->assertTrue($svc->isSendAllowed($a, $this->at('2026-06-29 10:00')));
    }

    public function test_fully_closed_config_blocks_always(): void
    {
        $svc = $this->service();
        $closed = [];
        foreach (Agency::OUTREACH_SEND_WINDOW_DAYS as $d) {
            $closed[$d] = ['enabled' => false, 'start' => null, 'end' => null];
        }
        $a = $this->agency($closed);
        $this->assertFalse($svc->isSendAllowed($a, $this->at('2026-06-29 10:00')));
    }

    public function test_next_opens_at_skips_closed_days(): void
    {
        $svc = $this->service();
        $a = $this->agency(null);
        // Saturday 14:00 (after close) → next open is Monday 08:00 (Sunday skipped).
        $next = $svc->nextOpensAt($a, $this->at('2026-07-04 14:00'));
        $this->assertNotNull($next);
        $this->assertSame('2026-07-06 08:00', $next->format('Y-m-d H:i'));
    }

    public function test_describe_window_groups_consecutive_days(): void
    {
        $svc = $this->service();
        $this->assertSame('Mon–Fri 08:00–20:00, Sat 09:00–13:00', $svc->describeWindow($this->agency(null)));
    }
}
