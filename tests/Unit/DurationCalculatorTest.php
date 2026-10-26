<?php

use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;

class DurationCalculatorTest extends TestCase
{
    private function slide(array $content): Page
    {
        return new Page(['slug' => 'slide', 'content' => $content]);
    }

    public function testManualOverrideIsUsed(): void
    {
        $slide = $this->slide(['duration_mode' => 'manual', 'duration_override' => '42']);

        $this->assertSame(42, DurationCalculator::calculate($slide));
    }

    public function testManualOverrideHasAFloorAndACeiling(): void
    {
        $short = $this->slide(['duration_mode' => 'manual', 'duration_override' => '1']);
        $long = $this->slide(['duration_mode' => 'manual', 'duration_override' => '9999']);

        $this->assertSame(5, DurationCalculator::calculate($short));
        $this->assertSame(300, DurationCalculator::calculate($long));
    }

    public function testUnknownSlideTypeDefaultsToTenSeconds(): void
    {
        $slide = $this->slide(['duration_mode' => 'auto', 'slide_type' => 'something-else']);

        $this->assertSame(10, DurationCalculator::calculate($slide));
        $this->assertSame('Default: 10 seconds', DurationCalculator::getCalculationDetails($slide));
    }
}
