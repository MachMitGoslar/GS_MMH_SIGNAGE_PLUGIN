<?php

use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;

class DurationCalculatorTest extends TestCase
{
    private function slide(array $content): Page
    {
        return new Page(['slug' => 'slide', 'content' => $content]);
    }

    public function testSlideNoLongerHasItsOwnManualDuration(): void
    {
        // the duration is set per channel now; old slide fields are ignored
        $slide = $this->slide(['duration_mode' => 'manual', 'duration_override' => '42', 'slide_type' => 'something-else']);

        $this->assertSame(10, DurationCalculator::calculate($slide));
    }

    public function testUnknownSlideTypeDefaultsToTenSeconds(): void
    {
        $slide = $this->slide(['duration_mode' => 'auto', 'slide_type' => 'something-else']);

        $this->assertSame(10, DurationCalculator::calculate($slide));
        $this->assertSame('Default: 10 seconds', DurationCalculator::getCalculationDetails($slide));
    }
}
