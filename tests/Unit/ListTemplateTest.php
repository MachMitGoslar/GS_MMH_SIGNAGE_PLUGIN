<?php

use Kirby\Cms\Page;
use Kirby\Data\Yaml;
use PHPUnit\Framework\TestCase;

/**
 * Template "Liste": cards for the player and a duration that grows with
 * the cards.
 */
class ListTemplateTest extends TestCase
{
    private function slide(array $items, array $content = []): Page
    {
        return new Page(['slug' => 'liste', 'template' => 'slide', 'content' => $content + [
            'slide_type' => 'template',
            'template_preset' => 'list',
            'template_title' => 'Angebote',
            'template_items' => Yaml::encode($items),
        ]]);
    }

    public function testItemsSkipEmptyRowsAndStopAtSix(): void
    {
        $rows = [['title' => '', 'text' => '', 'meta' => 'nur Meta']];
        for ($i = 1; $i <= 8; $i++) {
            $rows[] = ['title' => 'Karte ' . $i, 'text' => '', 'meta' => ''];
        }

        $items = AccessController::templateItems($this->slide($rows));

        $this->assertCount(6, $items);
        $this->assertSame(['title' => 'Karte 1', 'text' => '', 'meta' => ''], $items[0]);
    }

    public function testDurationGrowsWithCards(): void
    {
        $one = $this->slide([['title' => 'Repair-Café', 'text' => 'Kurz', 'meta' => 'Mo']]);
        $four = $this->slide(array_fill(0, 4, ['title' => 'Repair-Café', 'text' => 'Kurz', 'meta' => 'Mo']));

        $this->assertSame(10, DurationCalculator::calculate($one));
        $this->assertGreaterThan(DurationCalculator::calculate($one), DurationCalculator::calculate($four));
        $this->assertStringContainsString('Liste mit 4 Karten', DurationCalculator::getCalculationDetails($four));
    }

    public function testEveryCardGetsAtLeastFourSeconds(): void
    {
        $six = $this->slide(array_fill(0, 6, ['title' => 'A', 'text' => '', 'meta' => '']));

        // heading "Angebote" ≈ 1 s + 2 s, then 6 × 4 s
        $this->assertSame(27, DurationCalculator::calculate($six));
    }
}
