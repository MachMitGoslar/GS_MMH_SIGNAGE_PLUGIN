<?php

use Kirby\Cms\App;
use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;

/**
 * The channel decides how long a slide runs; the same slide can run with
 * different durations in different channels.
 */
class ChannelDurationTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        $this->app = App::instance()->clone([
            'site' => [
                'children' => [
                    [
                        'slug' => 'signage',
                        'children' => [
                            [
                                'slug' => 'slides',
                                'children' => [
                                    ['slug' => 'a', 'template' => 'slide', 'content' => ['slide_type' => 'other', 'uuid' => 'slide-a']],
                                    ['slug' => 'b', 'template' => 'slide', 'content' => ['slide_type' => 'other', 'uuid' => 'slide-b']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        App::destroy();
    }

    private function channel(string $slides): Page
    {
        return new Page(['slug' => 'channel', 'template' => 'channel', 'content' => ['slides' => $slides]]);
    }

    private function slide(string $slug): Page
    {
        return $this->app->page('signage/slides/' . $slug);
    }

    public function testRowsWithDurationAreRead(): void
    {
        $channel = $this->channel("-\n  slide:\n    - page://slide-a\n  duration: 20\n-\n  slide:\n    - page://slide-b\n  duration: \"\"\n");

        $this->assertSame([
            ['slide' => 'page://slide-a', 'duration' => 20],
            ['slide' => 'page://slide-b', 'duration' => null],
        ], SlideLibrary::entries($channel));
        $this->assertSame(['signage/slides/a', 'signage/slides/b'], SlideLibrary::slidesOf($channel)->keys());
    }

    public function testOldListOfReferencesIsStillRead(): void
    {
        $channel = $this->channel("- page://slide-a\n- page://slide-b\n");

        $this->assertSame(['signage/slides/a', 'signage/slides/b'], SlideLibrary::slidesOf($channel)->keys());
        $this->assertNull(SlideLibrary::durationIn($channel, $this->slide('a')));
    }

    public function testChannelDurationWinsOverCalculation(): void
    {
        $channel = $this->channel("-\n  slide:\n    - page://slide-a\n  duration: 20\n-\n  slide:\n    - page://slide-b\n");

        $this->assertSame(20, DurationCalculator::forChannel($this->slide('a'), $channel));
        // no value in the channel: calculated (unknown type = 10 s)
        $this->assertSame(10, DurationCalculator::forChannel($this->slide('b'), $channel));
        // without a channel (single slide preview) always calculated
        $this->assertSame(10, DurationCalculator::forChannel($this->slide('a')));
    }

    public function testSameSlideRunsDifferentlyPerChannel(): void
    {
        $short = $this->channel("-\n  slide:\n    - page://slide-a\n  duration: 6\n");
        $long = $this->channel("-\n  slide:\n    - page://slide-a\n  duration: 45\n");

        $this->assertSame(6, DurationCalculator::forChannel($this->slide('a'), $short));
        $this->assertSame(45, DurationCalculator::forChannel($this->slide('a'), $long));
    }

    public function testOldSlideDurationAppliesUntilTheChannelSetsOne(): void
    {
        $this->app = $this->app->clone([
            'site' => [
                'children' => [
                    [
                        'slug' => 'signage',
                        'children' => [
                            [
                                'slug' => 'slides',
                                'children' => [
                                    ['slug' => 'a', 'template' => 'slide', 'content' => [
                                        'slide_type' => 'other', 'uuid' => 'slide-a',
                                        'duration_mode' => 'manual', 'duration_override' => '300',
                                    ]],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $unset = $this->channel("- page://slide-a\n");
        $set = $this->channel("-\n  slide:\n    - page://slide-a\n  duration: 25\n");

        $this->assertSame(300, DurationCalculator::forChannel($this->slide('a'), $unset));
        $this->assertSame(25, DurationCalculator::forChannel($this->slide('a'), $set));
        $this->assertSame(300, DurationCalculator::forChannel($this->slide('a')));
    }

    public function testChannelDurationIsClamped(): void
    {
        $channel = $this->channel("-\n  slide:\n    - page://slide-a\n  duration: 1\n-\n  slide:\n    - page://slide-b\n  duration: 9999\n");

        $this->assertSame(3, DurationCalculator::forChannel($this->slide('a'), $channel));
        $this->assertSame(300, DurationCalculator::forChannel($this->slide('b'), $channel));
    }

    public function testPanelShowsOldListAsRows(): void
    {
        $this->assertSame([
            ['slide' => ['page://slide-a'], 'duration' => ''],
            ['slide' => ['page://slide-b'], 'duration' => ''],
        ], SlideLibrary::rows("- page://slide-a\n- page://slide-b\n"));
        $this->assertSame([], SlideLibrary::rows(''));
    }

    public function testEncodeWritesRows(): void
    {
        $channel = $this->channel(SlideLibrary::encode([
            ['slide' => 'page://slide-a', 'duration' => 12],
            ['slide' => 'page://slide-b', 'duration' => null],
        ]));

        $this->assertSame([
            ['slide' => 'page://slide-a', 'duration' => 12],
            ['slide' => 'page://slide-b', 'duration' => null],
        ], SlideLibrary::entries($channel));
    }
}
