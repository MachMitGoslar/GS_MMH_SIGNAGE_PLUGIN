<?php

use PHPUnit\Framework\TestCase;

class ChannelScheduleTest extends TestCase
{
    private const PRIMARY = 'signage/channels/aktuelle-infos';

    /**
     * Thursday, 26 November 2026.
     */
    private function at(string $time = '10:00:00', string $date = '2026-11-26'): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' ' . $time, new DateTimeZone('Europe/Berlin'));
    }

    public function testEmptyEntryMatchesAlways(): void
    {
        $this->assertTrue(ChannelSchedule::matches([], $this->at()));
    }

    public function testWeekdayFilter(): void
    {
        $this->assertTrue(ChannelSchedule::matches(['days' => 'thu'], $this->at()));
        $this->assertTrue(ChannelSchedule::matches(['days' => 'mon, thu'], $this->at()));
        $this->assertFalse(ChannelSchedule::matches(['days' => 'mon, fri'], $this->at()));
        $this->assertTrue(ChannelSchedule::matches(['days' => ['thu']], $this->at()));
    }

    public function testDateRange(): void
    {
        $entry = ['date_start' => '2026-11-01', 'date_end' => '2026-11-30'];

        $this->assertTrue(ChannelSchedule::matches($entry, $this->at()));
        $this->assertFalse(ChannelSchedule::matches($entry, $this->at('10:00:00', '2026-12-01')));
        $this->assertFalse(ChannelSchedule::matches($entry, $this->at('10:00:00', '2026-10-31')));
    }

    public function testOpenEndedDates(): void
    {
        $this->assertTrue(ChannelSchedule::matches(['date_start' => '2026-11-26'], $this->at()));
        $this->assertFalse(ChannelSchedule::matches(['date_start' => '2026-11-27'], $this->at()));
        $this->assertTrue(ChannelSchedule::matches(['date_end' => '2026-11-26'], $this->at('23:59:00')));
        $this->assertFalse(ChannelSchedule::matches(['date_end' => '2026-11-25'], $this->at()));
    }

    public function testSingleDay(): void
    {
        $entry = ['date_start' => '2026-11-26', 'date_end' => '2026-11-26'];

        $this->assertTrue(ChannelSchedule::matches($entry, $this->at('00:00:00')));
        $this->assertFalse(ChannelSchedule::matches($entry, $this->at('10:00:00', '2026-11-27')));
    }

    public function testTimeWindowAcceptsKirbyTimeFormat(): void
    {
        $entry = ['time_start' => '09:00:00', 'time_end' => '12:00:00'];

        $this->assertTrue(ChannelSchedule::matches($entry, $this->at('09:00:00')));
        $this->assertTrue(ChannelSchedule::matches($entry, $this->at('11:59:59')));
        $this->assertFalse(ChannelSchedule::matches($entry, $this->at('12:00:00')));
        $this->assertTrue(ChannelSchedule::matches(['time_start' => '09:00', 'time_end' => '12:00'], $this->at()));
    }

    public function testOnlyStartOrEndTime(): void
    {
        $this->assertTrue(ChannelSchedule::matches(['time_start' => '09:00'], $this->at('23:00:00')));
        $this->assertFalse(ChannelSchedule::matches(['time_start' => '11:00'], $this->at()));
        $this->assertTrue(ChannelSchedule::matches(['time_end' => '11:00'], $this->at()));
    }

    public function testTimeWindowAcrossMidnight(): void
    {
        $entry = ['time_start' => '22:00', 'time_end' => '02:00'];

        $this->assertTrue(ChannelSchedule::matches($entry, $this->at('23:30:00')));
        $this->assertTrue(ChannelSchedule::matches($entry, $this->at('01:00:00')));
        $this->assertFalse(ChannelSchedule::matches($entry, $this->at('10:00:00')));
    }

    public function testCombinedRestrictions(): void
    {
        $entry = ['days' => 'thu', 'date_start' => '2026-11-01', 'time_start' => '16:00', 'time_end' => '20:00'];

        $this->assertTrue(ChannelSchedule::matches($entry, $this->at('17:00:00')));
        $this->assertFalse(ChannelSchedule::matches($entry, $this->at('10:00:00')));
        $this->assertFalse(ChannelSchedule::matches($entry, $this->at('17:00:00', '2026-11-27')));
    }

    public function testFallsBackToPrimaryChannel(): void
    {
        $entries = [['channel' => 'signage/channels/abend', 'time_start' => '18:00', 'time_end' => '22:00']];

        $this->assertSame([self::PRIMARY], ChannelSchedule::resolveIds(self::PRIMARY, $entries, $this->at()));
    }

    public function testFirstMatchingReplaceEntryWins(): void
    {
        $entries = [
            ['channel' => 'signage/channels/morgen', 'time_start' => '08:00', 'time_end' => '12:00'],
            ['channel' => 'signage/channels/ganztags'],
        ];

        $this->assertSame(['signage/channels/morgen'], ChannelSchedule::resolveIds(self::PRIMARY, $entries, $this->at()));
        $this->assertSame(['signage/channels/ganztags'], ChannelSchedule::resolveIds(self::PRIMARY, $entries, $this->at('14:00:00')));
    }

    public function testAddEntriesAreCombinedWithTheBaseChannel(): void
    {
        $entries = [
            ['channel' => 'signage/channels/donnerstags', 'mode' => 'add', 'days' => 'thu'],
            ['channel' => 'signage/channels/wochenende', 'mode' => 'add', 'days' => 'sat, sun'],
            ['channel' => 'signage/channels/abend', 'mode' => 'add', 'time_start' => '08:00'],
        ];

        $this->assertSame(
            [self::PRIMARY, 'signage/channels/donnerstags', 'signage/channels/abend'],
            ChannelSchedule::resolveIds(self::PRIMARY, $entries, $this->at()),
        );
    }

    public function testAddEntriesAlsoCombineWithAReplacement(): void
    {
        $entries = [
            ['channel' => 'signage/channels/donnerstags', 'mode' => 'add', 'days' => 'thu'],
            ['channel' => 'signage/channels/aktion', 'mode' => 'replace', 'date_start' => '2026-11-26'],
        ];

        $this->assertSame(
            ['signage/channels/aktion', 'signage/channels/donnerstags'],
            ChannelSchedule::resolveIds(self::PRIMARY, $entries, $this->at()),
        );
    }

    public function testDuplicateChannelsAreRemoved(): void
    {
        $entries = [['channel' => self::PRIMARY, 'mode' => 'add']];

        $this->assertSame([self::PRIMARY], ChannelSchedule::resolveIds(self::PRIMARY, $entries, $this->at()));
    }

    public function testChannelIdsAreNormalized(): void
    {
        $this->assertSame('signage/channels/foo', ChannelSchedule::normalizeChannelId('foo'));
        $this->assertSame('signage/channels/foo', ChannelSchedule::normalizeChannelId('signage/channels/foo'));
        $this->assertSame('signage/channels/foo', ChannelSchedule::normalizeChannelId('/signage/channels/foo/'));
        $this->assertSame('', ChannelSchedule::normalizeChannelId('  '));

        $entries = [['channel' => 'morgen']];
        $this->assertSame(['signage/channels/morgen'], ChannelSchedule::resolveIds(self::PRIMARY, $entries, $this->at()));
    }

    public function testActiveTimesWithinRangeAndDay(): void
    {
        $ranges = [['start' => '08:00:00', 'end' => '18:00:00', 'days' => 'mon, tue, wed, thu, fri']];

        $this->assertTrue(ChannelSchedule::isWithinActiveTimes($ranges, $this->at('08:00:00')));
        $this->assertTrue(ChannelSchedule::isWithinActiveTimes($ranges, $this->at('17:59:00')));
        $this->assertFalse(ChannelSchedule::isWithinActiveTimes($ranges, $this->at('18:00:00')));
        $this->assertFalse(ChannelSchedule::isWithinActiveTimes($ranges, $this->at('07:59:00')));
        // Saturday
        $this->assertFalse(ChannelSchedule::isWithinActiveTimes($ranges, $this->at('10:00:00', '2026-11-28')));
    }

    public function testActiveTimesAnyOfSeveralRanges(): void
    {
        $ranges = [
            ['start' => '08:00', 'end' => '12:00', 'days' => 'thu'],
            ['start' => '10:00', 'end' => '14:00', 'days' => 'sat'],
        ];

        $this->assertTrue(ChannelSchedule::isWithinActiveTimes($ranges, $this->at('09:00:00')));
        $this->assertFalse(ChannelSchedule::isWithinActiveTimes($ranges, $this->at('13:00:00')));
        $this->assertTrue(ChannelSchedule::isWithinActiveTimes($ranges, $this->at('13:00:00', '2026-11-28')));
    }

    public function testActiveTimesWithoutDaysMeansEveryDay(): void
    {
        $ranges = [['start' => '08:00', 'end' => '18:00']];

        $this->assertTrue(ChannelSchedule::isWithinActiveTimes($ranges, $this->at('10:00:00', '2026-11-29')));
    }

    public function testNoActiveTimesMeansStandby(): void
    {
        $this->assertFalse(ChannelSchedule::isWithinActiveTimes([], $this->at()));
        $this->assertFalse(ChannelSchedule::isWithinActiveTimes(null, $this->at()));
        $this->assertFalse(ChannelSchedule::isWithinActiveTimes([['days' => 'thu']], $this->at()));
    }

    public function testActiveTimesAcrossMidnight(): void
    {
        $ranges = [['start' => '20:00', 'end' => '01:00']];

        $this->assertTrue(ChannelSchedule::isWithinActiveTimes($ranges, $this->at('23:00:00')));
        $this->assertTrue(ChannelSchedule::isWithinActiveTimes($ranges, $this->at('00:30:00')));
        $this->assertFalse(ChannelSchedule::isWithinActiveTimes($ranges, $this->at('12:00:00')));
    }
}
