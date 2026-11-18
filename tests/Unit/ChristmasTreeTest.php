<?php

use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;

class ChristmasTreeTest extends TestCase
{
    private function tree(array $content = [], array $children = []): Page
    {
        return new Page([
            'slug' => 'baum',
            'template' => 'slide',
            'content' => array_merge(['slide_type' => 'christmas_tree'], $content),
            'children' => $children,
        ]);
    }

    private function greeting(string $slug, string $name, string $text, string $date, bool $listed = true): array
    {
        return [
            'slug' => $slug,
            'num' => $listed ? 0 : null,
            'isDraft' => !$listed,
            'template' => 'signage-greeting',
            'content' => [
                'greeting_name' => $name,
                'greeting_text' => $text,
                'submitted_at' => $date,
            ],
        ];
    }

    public function testSanitizeTrimsAndNormalisesWhitespace(): void
    {
        $result = ChristmasTree::sanitize("  Anna \n Lena ", "Frohe   Weihnachten!\r\n\r\n  Euer Team  ");

        $this->assertSame(['name' => 'Anna Lena', 'text' => "Frohe Weihnachten!\nEuer Team"], $result);
    }

    public function testSanitizeRejectsTooLongOrEmptyInput(): void
    {
        $this->assertIsString(ChristmasTree::sanitize('', 'Hallo'));
        $this->assertIsString(ChristmasTree::sanitize('Anna', '   '));
        $this->assertIsString(ChristmasTree::sanitize(str_repeat('a', 41), 'Hallo'));
        $this->assertIsString(ChristmasTree::sanitize('Anna', str_repeat('ä', 141)));
        $this->assertIsString(ChristmasTree::sanitize('Anna', "1\n2\n3\n4"));
    }

    public function testSanitizeAcceptsTheLimitsExactly(): void
    {
        $result = ChristmasTree::sanitize(str_repeat('ö', 40), str_repeat('ü', 140));

        $this->assertIsArray($result);
    }

    public function testDataContainsOnlyApprovedGreetingsNewestFirst(): void
    {
        $tree = $this->tree(['tree_title' => 'Frohe Feiertage'], [
            $this->greeting('alt', 'Alt', 'Erster', '2026-12-01 10:00:00'),
            $this->greeting('neu', 'Neu', 'Zweiter', '2026-12-03 10:00:00'),
            $this->greeting('wartet', 'Wartet', 'Nicht freigegeben', '2026-12-04 10:00:00', listed: false),
        ]);

        $data = ChristmasTree::data($tree);

        $this->assertSame('Frohe Feiertage', $data['title']);
        $this->assertSame(['Neu', 'Alt'], array_column($data['greetings'], 'name'));
        $this->assertSame(6, $data['visible_balls']);
    }

    public function testDurationCoversOneRoundOfGreetings(): void
    {
        $children = [];
        for ($i = 1; $i <= 9; $i++) {
            $children[] = $this->greeting('g' . $i, 'N' . $i, 'Text', '2026-12-0' . $i . ' 10:00:00');
        }

        $tree = $this->tree(['duration_mode' => 'auto', 'tree_seconds_per_greeting' => '5'], $children);

        // 6 visible baubles × 5 s + 4 s intro/outro
        $this->assertSame(34, DurationCalculator::calculate($tree));
    }

    public function testEmptyTreeIsShownBriefly(): void
    {
        $this->assertSame(8, DurationCalculator::calculate($this->tree(['duration_mode' => 'auto'])));
    }
}
