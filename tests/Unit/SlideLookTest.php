<?php

use PHPUnit\Framework\TestCase;

/**
 * One look for every slide: background, overlay preset and colour scheme,
 * plus the readings of older content (before the migration).
 */
class SlideLookTest extends TestCase
{
    public function testPresetTakesTheSchemeColour(): void
    {
        $slide = ['bg_type' => 'image', 'bg_image' => '- file://a', 'overlay_preset' => 'strong', 'color_scheme' => 'light'];

        $this->assertSame(
            ['enabled' => true, 'color' => '#f4dfbd', 'opacity' => 65, 'gradient' => 'none', 'strength' => 100],
            SlideLook::overlay($slide, SlideLook::scheme($slide)),
        );
        $this->assertSame('#10100f', SlideLook::overlay($slide, 'dark')['color']);
    }

    public function testCustomOverlayUsesItsOwnValues(): void
    {
        $slide = [
            'bg_type' => 'video', 'bg_video' => '- file://v', 'overlay_preset' => 'custom',
            'overlay_color' => '#41bc00', 'overlay_opacity' => '85', 'overlay_gradient' => 'radial', 'overlay_strength' => '40',
        ];

        $this->assertSame(
            ['enabled' => true, 'color' => '#41bc00', 'opacity' => 85, 'gradient' => 'radial', 'strength' => 40],
            SlideLook::overlay($slide, 'dark'),
        );
    }

    public function testOverlayOnlyOnImageAndVideo(): void
    {
        $this->assertFalse(SlideLook::overlay(['bg_type' => 'color', 'overlay_preset' => 'strong'], 'dark')['enabled']);
        $this->assertFalse(SlideLook::overlay(['bg_type' => 'none', 'overlay_preset' => 'strong'], 'dark')['enabled']);
    }

    public function testSchemeFollowsTheChannelUnlessSet(): void
    {
        $this->assertSame('light', SlideLook::scheme(['overlay_preset' => 'none'], 'light'));
        $this->assertSame('dark', SlideLook::scheme(['overlay_preset' => 'none', 'color_scheme' => 'dark'], 'light'));
        $this->assertSame('dark', SlideLook::channelScheme([]));
    }

    public function testTemplateImageOfOlderContentIsTheBackground(): void
    {
        $slide = [
            'slide_type' => 'template', 'template_theme' => 'dark', 'bg_type' => 'none',
            'template_image' => '- file://t', 'template_focus' => '20% 30%',
        ];

        $this->assertSame(
            ['type' => 'image', 'image' => 'file://t', 'focus' => '20% 30%', 'fit' => 'cover'],
            SlideLook::background($slide),
        );
        $this->assertSame('dark', SlideLook::scheme($slide));
        $this->assertSame(
            ['enabled' => true, 'color' => '#10100f', 'opacity' => 58, 'gradient' => 'none', 'strength' => 100],
            SlideLook::overlay($slide, 'dark'),
        );
    }

    public function testOlderContentKeepsItsAutomaticScheme(): void
    {
        $whiteOverlay = ['slide_type' => 'blocks', 'bg_type' => 'image', 'bg_image' => '- file://a', 'overlay_enabled' => 'true', 'overlay_color' => '#ffffff'];
        $blackOverlay = ['overlay_color' => '#000000'] + $whiteOverlay;
        $noOverlay = ['overlay_enabled' => 'false'] + $whiteOverlay;

        $this->assertSame('light', SlideLook::scheme($whiteOverlay));
        $this->assertSame('dark', SlideLook::scheme($blackOverlay));
        $this->assertSame('light', SlideLook::scheme($noOverlay));
        $this->assertSame('light', SlideLook::scheme(['slide_type' => 'template']));
        $this->assertSame('light', SlideLook::scheme(['slide_type' => 'blocks', 'bg_type' => 'none'], 'light'));
    }

    public function testPresetForFindsExactPresetsOnly(): void
    {
        $this->assertSame('strong', SlideLook::presetFor(SlideLook::resolveOverlay('strong', [], 'dark'), 'dark'));
        $this->assertSame('fade_bottom', SlideLook::presetFor(SlideLook::resolveOverlay('fade_bottom', [], 'light'), 'light'));
        $this->assertSame('custom', SlideLook::presetFor(['enabled' => true, 'color' => '#000000', 'opacity' => 65, 'gradient' => 'none', 'strength' => 100], 'dark'));
        $this->assertSame('none', SlideLook::presetFor(['enabled' => false], 'dark'));
    }

    public function testChannelOverlay(): void
    {
        $this->assertSame('#f4dfbd', SlideLook::channelOverlay(['default_overlay_preset' => 'soft', 'default_color_scheme' => 'light'])['color']);
        // before presets: opacity 0 = off
        $this->assertFalse(SlideLook::channelOverlay(['default_overlay_opacity' => '0'])['enabled']);
        $this->assertSame(20, SlideLook::channelOverlay(['default_overlay_opacity' => '20'])['opacity']);
    }
}
