<?php

use Kirby\Cms\File;
use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;

/**
 * Background position of slide images: the focus of this usage wins over
 * the focus stored on the file (Mediathek), explicit positions win over both.
 */
class BackgroundPositionTest extends TestCase
{
    private function image(?string $focus = null): File
    {
        $page = new Page(['slug' => 'album']);

        return new File([
            'filename' => 'bild.jpg',
            'parent' => $page,
            'content' => $focus ? ['focus' => $focus] : [],
        ]);
    }

    public function testFileFocusIsTheDefault(): void
    {
        $this->assertSame('30% 70%', AccessController::backgroundPosition($this->image('30% 70%'), 'focus'));
        $this->assertSame('30% 70%', AccessController::backgroundPosition($this->image('30% 70%')));
    }

    public function testUsageFocusWinsOverFileFocus(): void
    {
        $this->assertSame('10% 20%', AccessController::backgroundPosition($this->image('30% 70%'), 'focus', '10% 20%'));
        $this->assertSame('10% 20%', AccessController::backgroundPosition($this->image(), null, '10% 20%'));
    }

    public function testInvalidUsageFocusFallsBackToFileFocus(): void
    {
        $this->assertSame('30% 70%', AccessController::backgroundPosition($this->image('30% 70%'), 'focus', 'oben'));
    }

    public function testExplicitPositionWins(): void
    {
        $this->assertSame('top', AccessController::backgroundPosition($this->image('30% 70%'), 'top', '10% 20%'));
    }

    public function testCenterWithoutAnyFocus(): void
    {
        $this->assertSame('center', AccessController::backgroundPosition($this->image(), 'center'));
        $this->assertSame('50% 50%', AccessController::backgroundPosition($this->image()));
    }
}
