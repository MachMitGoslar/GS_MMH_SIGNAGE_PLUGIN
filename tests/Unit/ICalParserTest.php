<?php

use PHPUnit\Framework\TestCase;

class ICalParserTest extends TestCase
{
    public function testAllDayEventHasNoClockTime(): void
    {
        $event = ICalParser::formatEventForDisplay([
            'title' => 'Fest',
            'location' => 'Hof',
            'description' => '',
            'start' => '2026-10-31',
            'end' => '',
            'all_day' => true,
        ]);

        $this->assertSame('2026-10-31', $event['date']);
        $this->assertSame('Ganztägig', $event['time']);
        $this->assertArrayNotHasKey('end_time', $event);
    }

    public function testTimedEventFormatsStartAndEnd(): void
    {
        $event = ICalParser::formatEventForDisplay([
            'title' => 'Treffen',
            'location' => '',
            'description' => '',
            'start' => '2026-10-31 14:30:00',
            'end' => '2026-10-31 16:00:00',
            'all_day' => false,
        ]);

        $this->assertSame('2026-10-31', $event['date']);
        $this->assertSame('14:30', $event['time']);
        $this->assertSame('16:00', $event['end_time']);
    }
}
