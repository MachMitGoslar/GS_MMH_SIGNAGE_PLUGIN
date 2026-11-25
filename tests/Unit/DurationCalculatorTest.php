<?php

use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;

class DurationCalculatorTest extends TestCase
{
    private function slide(array $content): Page
    {
        return new Page(['slug' => 'slide', 'content' => $content]);
    }

    public function testCalculationIgnoresTheOldSlideDuration(): void
    {
        $slide = $this->slide(['duration_mode' => 'manual', 'duration_override' => '42', 'slide_type' => 'something-else']);

        $this->assertSame(10, DurationCalculator::calculate($slide));
        $this->assertSame(42, DurationCalculator::legacyDuration($slide));
    }

    public function testUnknownSlideTypeDefaultsToTenSeconds(): void
    {
        $slide = $this->slide(['duration_mode' => 'auto', 'slide_type' => 'something-else']);

        $this->assertSame(10, DurationCalculator::calculate($slide));
        $this->assertSame('Default: 10 seconds', DurationCalculator::getCalculationDetails($slide));
    }
}
