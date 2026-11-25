<?php

/**
 * Look of a slide or channel: background, overlay ("Lesbarkeit") and colour
 * scheme – one place for every slide type, templates included.
 *
 * Works on plain content arrays (field => value, keys as Kirby uses them:
 * lower case, underscores), so the player data (AccessController) and the
 * file based content migration (LookMigration) share the same rules.
 *
 * Older content has no `overlay_preset` / `color_scheme` and may keep the
 * template image in `template_image`; those values are read as before until
 * the migration has rewritten them.
 */
class SlideLook
{
    public const SCHEMES = ['light', 'dark'];

    /**
     * Overlay colour of a scheme: a light scheme brightens the image (dark
     * text on top), a dark scheme darkens it.
     */
    public const SCHEME_COLORS = [
        'light' => '#f4dfbd',
        'dark' => '#10100f',
    ];

    /**
     * Presets of the "Lesbarkeit" select: opacity, gradient, strength.
     * The colour comes from the colour scheme.
     *
     * @var array<string, array{opacity:int, gradient:string, strength:int}>
     */
    public const PRESETS = [
        'soft' => ['opacity' => 35, 'gradient' => 'none', 'strength' => 100],
        'strong' => ['opacity' => 65, 'gradient' => 'none', 'strength' => 100],
        'fade_bottom' => ['opacity' => 85, 'gradient' => 'to-top', 'strength' => 70],
        'fade_left' => ['opacity' => 85, 'gradient' => 'to-right', 'strength' => 70],
    ];

    /**
     * Overlay the template image got before the look was unified.
     */
    public const LEGACY_TEMPLATE_OVERLAY = [
        'light' => ['color' => '#f4dfbd', 'opacity' => 66],
        'dark' => ['color' => '#10100f', 'opacity' => 58],
    ];

    /**
     * Background of a slide.
     *
     * @param array<string, mixed> $c slide content
     * @return array<string, string> type "none" = channel background;
     *         image/video are file references, fit is cover|contain
     */
    public static function background(array $c): array
    {
        $type = self::value($c, 'bg_type') ?: 'none';

        if ($type === 'none' && self::legacyTemplateImage($c) !== '') {
            return [
                'type' => 'image',
                'image' => self::legacyTemplateImage($c),
                'focus' => self::value($c, 'template_focus'),
                'fit' => 'cover',
            ];
        }

        return match ($type) {
            'image' => [
                'type' => 'image',
                'image' => self::firstReference($c['bg_image'] ?? ''),
                'focus' => self::value($c, 'bg_focus'),
                'fit' => self::value($c, 'bg_size') === 'contain' ? 'contain' : 'cover',
            ],
            'video' => ['type' => 'video', 'video' => self::firstReference($c['bg_video'] ?? '')],
            // older slides kept the solid colour in overlay_color
            'color' => [
                'type' => 'color',
                'color' => self::value($c, 'bg_color') ?: (self::value($c, 'overlay_color') ?: '#000000'),
            ],
            default => ['type' => 'none'],
        };
    }

    /**
     * Colour scheme of a slide: its own, else the channel's.
     *
     * @param array<string, mixed> $c slide content
     */
    public static function scheme(array $c, string $channelScheme = 'dark'): string
    {
        $own = self::value($c, 'color_scheme');
        if (in_array($own, self::SCHEMES, true)) {
            return $own;
        }

        if (!self::isMigrated($c)) {
            return self::legacyScheme($c, $channelScheme);
        }

        return $channelScheme;
    }

    /**
     * Colour scheme of a channel (dark unless set).
     *
     * @param array<string, mixed> $c channel content
     */
    public static function channelScheme(array $c): string
    {
        $own = self::value($c, 'default_color_scheme');

        return in_array($own, self::SCHEMES, true) ? $own : 'dark';
    }

    /**
     * Overlay of a slide (only on image and video backgrounds).
     *
     * @param array<string, mixed> $c slide content
     * @return array<string, mixed>
     */
    public static function overlay(array $c, string $scheme): array
    {
        $background = self::background($c);
        if (!in_array($background['type'], ['image', 'video'], true)) {
            return ['enabled' => false];
        }

        if (!self::isMigrated($c)) {
            if ((self::value($c, 'bg_type') ?: 'none') === 'none') {
                // template image: fixed overlay per template theme
                $legacy = self::LEGACY_TEMPLATE_OVERLAY[$scheme] ?? self::LEGACY_TEMPLATE_OVERLAY['light'];

                return ['enabled' => true] + $legacy + ['gradient' => 'none', 'strength' => 100];
            }

            // before presets: a toggle plus the custom values
            $preset = self::isOn($c['overlay_enabled'] ?? true) ? 'custom' : 'none';
        } else {
            $preset = self::value($c, 'overlay_preset');
        }

        return self::resolveOverlay($preset, [
            'color' => self::value($c, 'overlay_color'),
            'opacity' => self::value($c, 'overlay_opacity'),
            'gradient' => self::value($c, 'overlay_gradient'),
            'strength' => self::value($c, 'overlay_strength'),
        ], $scheme);
    }

    /**
     * Overlay of a channel's default background.
     *
     * @param array<string, mixed> $c channel content
     * @return array<string, mixed>
     */
    public static function channelOverlay(array $c): array
    {
        $preset = self::value($c, 'default_overlay_preset');
        $custom = [
            'color' => self::value($c, 'default_overlay_color'),
            'opacity' => self::value($c, 'default_overlay_opacity'),
            'gradient' => self::value($c, 'default_overlay_gradient'),
            'strength' => self::value($c, 'default_overlay_strength'),
        ];

        if ($preset === '') {
            // before presets: opacity 0 meant "off"
            $preset = self::number($custom['opacity'], 40) > 0 ? 'custom' : 'none';
        }

        return self::resolveOverlay($preset, $custom, self::channelScheme($c));
    }

    /**
     * @param array<string, string> $custom color, opacity, gradient, strength
     * @return array<string, mixed>
     */
    public static function resolveOverlay(string $preset, array $custom, string $scheme): array
    {
        if ($preset === 'custom') {
            return [
                'enabled' => true,
                'color' => ($custom['color'] ?? '') ?: '#000000',
                'opacity' => max(0, min(100, self::number($custom['opacity'] ?? '', 40))),
                'gradient' => ($custom['gradient'] ?? '') ?: 'none',
                'strength' => max(10, min(100, self::number($custom['strength'] ?? '', 60))),
            ];
        }

        if (!isset(self::PRESETS[$preset])) {
            return ['enabled' => false];
        }

        return ['enabled' => true, 'color' => self::SCHEME_COLORS[$scheme] ?? self::SCHEME_COLORS['dark']]
            + self::PRESETS[$preset];
    }

    /**
     * The preset that produces exactly this overlay, else "custom".
     *
     * @param array<string, mixed> $overlay result of overlay()/resolveOverlay()
     */
    public static function presetFor(array $overlay, string $scheme): string
    {
        if (empty($overlay['enabled']) || (int) ($overlay['opacity'] ?? 0) === 0) {
            return 'none';
        }

        foreach (self::PRESETS as $name => $preset) {
            $gradient = $overlay['gradient'] ?: 'none';
            if (
                strtolower((string) $overlay['color']) === self::SCHEME_COLORS[$scheme]
                && $preset['opacity'] === (int) $overlay['opacity']
                && $preset['gradient'] === $gradient
                && ($gradient === 'none' || $preset['strength'] === (int) $overlay['strength'])
            ) {
                return $name;
            }
        }

        return 'custom';
    }

    /**
     * Content written after the look was unified has `overlay_preset`.
     *
     * @param array<string, mixed> $c
     */
    public static function isMigrated(array $c): bool
    {
        return self::value($c, 'overlay_preset') !== '' || self::value($c, 'color_scheme') !== '';
    }

    /**
     * Scheme the player derived before it could be set: templates had
     * Hell/Dunkel, everything else followed the brightness of the overlay or
     * solid colour; channel backgrounds were always treated as dark.
     *
     * @param array<string, mixed> $c
     */
    public static function legacyScheme(array $c, string $channelScheme = 'dark'): string
    {
        $type = self::value($c, 'slide_type');

        if ($type === 'template') {
            return self::value($c, 'template_theme') === 'dark' ? 'dark' : 'light';
        }

        if ($type === 'christmas_tree') {
            return 'dark';
        }

        $background = self::background($c);
        $color = match ($background['type']) {
            'color' => $background['color'],
            'image', 'video' => self::isOn($c['overlay_enabled'] ?? true)
                ? (self::value($c, 'overlay_color') ?: '#000000')
                : '',
            default => null,
        };

        if ($color === null) {
            return $channelScheme;
        }

        return $color !== '' && self::brightness($color) < 155 ? 'dark' : 'light';
    }

    private static function legacyTemplateImage(array $c): string
    {
        if (self::isMigrated($c) || self::value($c, 'slide_type') !== 'template') {
            return '';
        }

        return self::firstReference($c['template_image'] ?? '');
    }

    public static function brightness(string $hex): float
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return 0;
        }

        [$r, $g, $b] = array_map('hexdec', str_split($hex, 2));

        return ($r * 299 + $g * 587 + $b * 114) / 1000;
    }

    /**
     * First reference of a files field value ("- file://abc" or a list).
     *
     * @param mixed $value
     */
    public static function firstReference($value): string
    {
        if (is_array($value)) {
            $value = $value[0] ?? '';
        }

        foreach (preg_split('/\R/', (string) $value) ?: [] as $line) {
            $line = trim(ltrim(trim($line), '-'));
            if ($line !== '') {
                return $line;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $c
     */
    private static function value(array $c, string $key): string
    {
        $value = $c[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param mixed $value
     */
    private static function number($value, int $fallback): int
    {
        return is_numeric($value) ? (int) $value : $fallback;
    }

    /**
     * @param mixed $value
     */
    private static function isOn($value): bool
    {
        return !in_array(strtolower(trim((string) $value)), ['false', '0', 'off', 'no'], true);
    }
}
