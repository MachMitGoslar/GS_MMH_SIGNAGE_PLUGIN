<?php

use PHPUnit\Framework\TestCase;

/**
 * The look migration writes exactly what the player showed before.
 */
class LookMigrationTest extends TestCase
{
    public function testTemplateImageMovesIntoTheBackground(): void
    {
        $plan = LookMigration::planSlide([
            'Title' => 'Repair-Café', 'Slide-type' => 'template', 'Template-theme' => 'dark',
            'Template-image' => '- file://t', 'Template-focus' => '10% 20%', 'Bg-type' => 'none',
            'Overlay-enabled' => 'true', 'Overlay-color' => '#000000', 'Overlay-opacity' => '40',
        ]);

        $this->assertSame('image', $plan['changes']['bg_type']);
        $this->assertSame('- file://t', $plan['changes']['bg_image']);
        $this->assertSame('10% 20%', $plan['changes']['bg_focus']);
        $this->assertSame('dark', $plan['changes']['color_scheme']);
        // the fixed template overlay becomes a custom overlay with the same values
        $this->assertSame('custom', $plan['changes']['overlay_preset']);
        $this->assertSame('#10100f', $plan['changes']['overlay_color']);
        $this->assertSame('58', $plan['changes']['overlay_opacity']);
        $this->assertNull($plan['changes']['template_image']);
        $this->assertNull($plan['changes']['overlay_enabled']);
    }

    public function testSlidesOnTheChannelBackgroundFollowTheChannel(): void
    {
        $plan = LookMigration::planSlide(['Slide-type' => 'blocks', 'Bg-type' => 'none']);

        $this->assertSame('', $plan['changes']['color_scheme']);
        $this->assertSame('none', $plan['changes']['overlay_preset']);
    }

    public function testContradictionsAreReported(): void
    {
        $plan = LookMigration::planSlide([
            'Slide-type' => 'template', 'Template-theme' => 'light', 'Bg-type' => 'image', 'Bg-image' => '- file://a',
            'Overlay-enabled' => 'true', 'Overlay-color' => '#000000', 'Overlay-opacity' => '65',
        ]);

        $this->assertSame('light', $plan['changes']['color_scheme']);
        $this->assertStringContainsString('Prüfen', implode(' ', $plan['notes']));
    }

    public function testMigratedSlidesAreLeftAlone(): void
    {
        $this->assertSame([], LookMigration::planSlide(['Overlay-preset' => 'soft'])['changes']);
    }

    public function testChannelsKeepTheirOverlayAndBecomeDark(): void
    {
        $plan = LookMigration::planChannel(['Default-overlay-opacity' => '0']);
        $this->assertSame(['default_color_scheme' => 'dark', 'default_overlay_preset' => 'none'], $plan['changes']);

        $plan = LookMigration::planChannel(['Default-overlay-color' => '#000000', 'Default-overlay-opacity' => '20']);
        $this->assertSame('custom', $plan['changes']['default_overlay_preset']);
    }

    public function testApplyRewritesTheFileAndKeepsOtherFields(): void
    {
        $dir = sys_get_temp_dir() . '/look-migration-' . uniqid();
        mkdir($dir . '/signage/slides/a', 0777, true);
        $file = $dir . '/signage/slides/a/slide.txt';
        file_put_contents($file, "Title: A\n\n----\n\nSlide-type: blocks\n\n----\n\nBg-type: color\n\n----\n\nBg-color: #ff0000\n\n----\n\nOverlay-enabled: true\n\n----\n\nUuid: abc\n");

        $plan = LookMigration::plan($dir);
        LookMigration::apply($plan);
        $fields = LookMigration::parse((string) file_get_contents($file));

        $this->assertSame('A', $fields['Title']);
        $this->assertSame('#ff0000', $fields['Bg-color']);
        $this->assertSame('dark', $fields['Color-scheme']); // red counts as dark (brightness 76)
        $this->assertSame('none', $fields['Overlay-preset']);
        $this->assertArrayNotHasKey('Overlay-enabled', $fields);
        $this->assertSame('Uuid', array_key_last($fields));
        $this->assertSame([], LookMigration::plan($dir), 'second run changes nothing');

        array_map('unlink', [$file]);
        rmdir($dir . '/signage/slides/a');
        rmdir($dir . '/signage/slides');
        rmdir($dir . '/signage');
        rmdir($dir);
    }
}
