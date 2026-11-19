<?php

namespace Tests\Unit;

use App\Services\DayTimeline;
use PHPUnit\Framework\TestCase;

/** 08-Nov-2026 (Ejaz): last night's after-midnight activity stays on last night's day. */
class DayTimelineSpillTest extends TestCase
{
    private function spill(array $sess, array $ev, string $day): int
    {
        $m = new \ReflectionMethod(DayTimeline::class, 'spill');
        $ws = strtotime($day . ' 00:00:00');

        return $m->invoke(null, $sess, $ev, $ws - 86400, $ws, $ws + 86400);
    }

    public function test_after_midnight_activity_stays_on_last_night(): void
    {
        $t = fn ($s) => strtotime($s);
        $sess = [['s' => $t('2026-11-07 23:45:15'), 'e' => $t('2026-11-07 23:59:59')], ['s' => $t('2026-11-08 19:44:18'), 'e' => null]];
        $ev = [['s' => $t('2026-11-07 23:50:00'), 'e' => $t('2026-11-08 00:02:00')],
            ['s' => $t('2026-11-08 00:02:30'), 'e' => $t('2026-11-08 00:14:10')],
            ['s' => $t('2026-11-08 19:45:00'), 'e' => $t('2026-11-08 19:50:00')]];

        $this->assertSame($t('2026-11-08 00:14:10'), $this->spill($sess, $ev, '2026-11-08'));
    }

    public function test_no_session_last_night_changes_nothing(): void
    {
        $t = fn ($s) => strtotime($s);
        $this->assertSame($t('2026-11-08 00:00:00'), $this->spill([['s' => $t('2026-11-07 10:00'), 'e' => $t('2026-11-07 18:00')]],
            [['s' => $t('2026-11-08 00:05'), 'e' => $t('2026-11-08 00:10')]], '2026-11-08'));
    }

    public function test_never_passes_the_days_own_sign_in(): void
    {
        $t = fn ($s) => strtotime($s);
        $this->assertSame($t('2026-11-08 08:00'), $this->spill([['s' => $t('2026-11-07 23:00'), 'e' => null], ['s' => $t('2026-11-08 08:00'), 'e' => null]],
            [['s' => $t('2026-11-08 00:00'), 'e' => $t('2026-11-08 09:00')]], '2026-11-08'));
    }
}
