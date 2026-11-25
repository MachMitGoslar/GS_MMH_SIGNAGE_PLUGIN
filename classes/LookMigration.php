<?php

/**
 * Content migration for the unified slide look (file based, no Kirby boot,
 * so the git-content plugin does not commit anything).
 *
 * Slides: the template image moves into the slide background, the overlay
 * toggle becomes a preset ("Lesbarkeit") and the colour scheme is written
 * explicitly – with exactly the values the player used so far, so no slide
 * changes its look. Channels get a preset and the scheme they had (dark).
 *
 * Removed fields: Overlay-enabled, Bg-position, Template-image,
 * Template-focus, Template-theme.
 *
 * Content blocks are gone as a slide type: every block slide was a picture
 * slide, it becomes the template "Bild" (the picture stays the background,
 * the first text becomes the caption, Content-layout is removed).
 */
class LookMigration
{
    private const REMOVED_SLIDE_FIELDS = ['overlay_enabled', 'bg_position', 'template_image', 'template_focus', 'template_theme'];

    /**
     * Changes for all slides and channels below a content root.
     *
     * @return array<string, array{changes: array<string, string|null>, notes: string[]}>
     *         file => field changes (null = remove)
     */
    public static function plan(string $contentRoot): array
    {
        $plan = [];
        $root = rtrim($contentRoot, '/') . '/signage';

        foreach (self::files($root, 'slide.txt') as $file) {
            $result = self::planSlide(self::parse((string) file_get_contents($file)));
            if ($result['changes'] !== []) {
                $plan[$file] = $result;
            }
        }

        foreach (self::files($root, 'channel.txt') as $file) {
            $result = self::planChannel(self::parse((string) file_get_contents($file)));
            if ($result['changes'] !== []) {
                $plan[$file] = $result;
            }
        }

        ksort($plan);

        return $plan;
    }

    /**
     * Writes the plan.
     *
     * @param array<string, array{changes: array<string, string|null>, notes: string[]}> $plan
     */
    public static function apply(array $plan): void
    {
        foreach ($plan as $file => $entry) {
            $fields = self::parse((string) file_get_contents($file));
            file_put_contents($file, self::encode(self::change($fields, $entry['changes'])));
        }
    }

    /**
     * @param array<string, string> $fields key => value (Kirby keys)
     * @return array{changes: array<string, string|null>, notes: string[]}
     */
    public static function planSlide(array $fields): array
    {
        $c = self::normalize($fields);
        $blocks = self::planBlocks($c);

        if (SlideLook::isMigrated($c)) {
            return ['changes' => self::onlyDifferent($c, $blocks['changes']), 'notes' => $blocks['notes']];
        }

        $look = self::planLook($c);

        return [
            'changes' => self::onlyDifferent($c, $blocks['changes'] + $look['changes']),
            'notes' => array_merge($blocks['notes'], $look['notes']),
        ];
    }

    /**
     * Block slide → template "Bild".
     *
     * @param array<string, string> $c normalized content
     * @return array{changes: array<string, string|null>, notes: string[]}
     */
    private static function planBlocks(array $c): array
    {
        if (trim($c['slide_type'] ?? '') !== 'blocks') {
            return ['changes' => [], 'notes' => []];
        }

        $texts = [];
        $dropped = [];
        $layout = json_decode((string) ($c['content_layout'] ?? '[]'), true);

        foreach (is_array($layout) ? $layout : [] as $row) {
            foreach ($row['columns'] ?? [] as $column) {
                foreach ($column['blocks'] ?? [] as $block) {
                    $text = trim(html_entity_decode(strip_tags((string) ($block['content']['text'] ?? '')), ENT_QUOTES | ENT_HTML5));
                    if (in_array($block['type'] ?? '', ['signage-text', 'text', 'heading'], true) && $text !== '' && $texts === []) {
                        $texts[] = $text;
                    } else {
                        $dropped[] = $block['type'] ?? '?';
                    }
                }
            }
        }

        $caption = $texts[0] ?? '';
        $changes = [
            'slide_type' => 'template',
            'template_preset' => 'image',
            'content_layout' => null,
        ];
        if (trim($c['template_title'] ?? '') === '') {
            $changes['template_title'] = $caption;
        }

        $notes = ['Inhaltsblöcke → Template „Bild“' . ($caption !== '' ? ', Bildunterschrift „' . $caption . '“' : '')];
        if ($dropped !== []) {
            $notes[] = '⚠ Nicht übernommen: ' . implode(', ', $dropped) . ' (Bild bleibt der Hintergrund)';
        }

        return ['changes' => $changes, 'notes' => $notes];
    }

    /**
     * @param array<string, string> $c normalized content
     * @return array{changes: array<string, string|null>, notes: string[]}
     */
    private static function planLook(array $c): array
    {
        $changes = [];
        $notes = [];
        $type = trim($c['slide_type'] ?? '');
        $scheme = SlideLook::legacyScheme($c, 'dark');
        $background = SlideLook::background($c);
        $overlay = SlideLook::overlay($c, $scheme);

        if ((trim($c['bg_type'] ?? '') ?: 'none') === 'none' && $background['type'] === 'image') {
            $changes['bg_type'] = 'image';
            $changes['bg_image'] = '- ' . $background['image'];
            $changes['bg_focus'] = $background['focus'] ?? '';
            $changes['bg_size'] = 'cover';
            $notes[] = 'Template-Bild → Hintergrundbild';
        }

        // slides on the channel background follow the channel's scheme
        $ownScheme = ($background['type'] === 'none' && $type !== 'template') || $type === 'christmas_tree'
            ? ''
            : $scheme;
        $changes['color_scheme'] = $ownScheme;

        $preset = SlideLook::presetFor($overlay, $scheme);
        $changes['overlay_preset'] = $preset;
        if ($preset === 'custom') {
            $changes['overlay_color'] = (string) $overlay['color'];
            $changes['overlay_opacity'] = (string) $overlay['opacity'];
            $changes['overlay_gradient'] = (string) $overlay['gradient'];
            $changes['overlay_strength'] = (string) $overlay['strength'];
        }
        $notes[] = 'Farbschema ' . ($ownScheme ?: 'wie Kanal') . ', Overlay ' . $preset;

        if (!empty($overlay['enabled']) && $ownScheme !== '') {
            $darkOverlay = SlideLook::brightness((string) $overlay['color']) < 155;
            if ($darkOverlay === ($ownScheme === 'light') && (int) $overlay['opacity'] >= 30) {
                $notes[] = '⚠ Prüfen: Farbschema ' . $ownScheme . ', aber ' . ($darkOverlay ? 'dunkles' : 'helles')
                    . ' Overlay (' . $overlay['color'] . ', ' . $overlay['opacity'] . ' %) – Schrift evtl. schlecht lesbar';
            }
        }

        foreach (self::REMOVED_SLIDE_FIELDS as $key) {
            if (array_key_exists($key, $c)) {
                $changes[$key] = null;
            }
        }

        return ['changes' => $changes, 'notes' => $notes];
    }

    /**
     * @param array<string, string> $fields
     * @return array{changes: array<string, string|null>, notes: string[]}
     */
    public static function planChannel(array $fields): array
    {
        $c = self::normalize($fields);
        if (trim($c['default_overlay_preset'] ?? '') !== '') {
            return ['changes' => [], 'notes' => []];
        }

        // channel backgrounds were always treated as dark
        $overlay = SlideLook::channelOverlay($c + ['default_color_scheme' => 'dark']);
        $preset = SlideLook::presetFor($overlay, 'dark');
        $changes = ['default_color_scheme' => 'dark', 'default_overlay_preset' => $preset];

        if ($preset === 'custom') {
            $changes['default_overlay_color'] = (string) $overlay['color'];
            $changes['default_overlay_opacity'] = (string) $overlay['opacity'];
            $changes['default_overlay_gradient'] = (string) $overlay['gradient'];
            $changes['default_overlay_strength'] = (string) $overlay['strength'];
        }

        return [
            'changes' => self::onlyDifferent($c, $changes),
            'notes' => ['Farbschema dark, Overlay ' . $preset],
        ];
    }

    /**
     * Markdown report of a plan.
     *
     * @param array<string, array{changes: array<string, string|null>, notes: string[]}> $plan
     */
    public static function report(array $plan, string $contentRoot): string
    {
        $lines = ['# Migration Folien: Hintergrund & Lesbarkeit, Inhaltsblöcke', '', count($plan) . ' Dateien', ''];

        foreach ($plan as $file => $entry) {
            $lines[] = '## ' . ltrim(substr($file, strlen(rtrim($contentRoot, '/'))), '/');
            foreach ($entry['notes'] as $note) {
                $lines[] = '- ' . $note;
            }
            foreach ($entry['changes'] as $key => $value) {
                $lines[] = '  - `' . self::fileKey($key) . '`: ' . ($value === null ? '(entfernt)' : '`' . str_replace("\n", ' ', $value) . '`');
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Kirby content file → ordered key => value (keys as in the file).
     *
     * @return array<string, string>
     */
    public static function parse(string $text): array
    {
        $fields = [];
        foreach (preg_split('/\R----\s*\R/', $text) ?: [] as $part) {
            if (preg_match('/^\s*([^:\n]+):[ \t]*(.*)$/s', $part, $m)) {
                // Kirby trims values; multi-line values start on the next line
                $fields[trim($m[1])] = trim($m[2]);
            }
        }

        return $fields;
    }

    /**
     * @param array<string, string> $fields
     */
    public static function encode(array $fields): string
    {
        $parts = [];
        foreach ($fields as $key => $value) {
            $value = (string) $value;
            $parts[] = $key . ':' . (str_contains($value, "\n") ? "\n\n" : ' ') . $value;
        }

        return implode("\n\n----\n\n", $parts) . "\n";
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string|null> $changes normalized keys
     * @return array<string, string>
     */
    private static function change(array $fields, array $changes): array
    {
        $byNormalized = [];
        foreach (array_keys($fields) as $key) {
            $byNormalized[self::normalizeKey($key)] = $key;
        }

        // new fields go before Uuid, so the file stays readable
        $uuid = null;
        if (isset($byNormalized['uuid'])) {
            $uuid = [$byNormalized['uuid'] => $fields[$byNormalized['uuid']]];
            unset($fields[$byNormalized['uuid']]);
        }

        foreach ($changes as $key => $value) {
            $existing = $byNormalized[$key] ?? null;
            if ($value === null) {
                if ($existing !== null) {
                    unset($fields[$existing]);
                }

                continue;
            }
            $fields[$existing ?? self::fileKey($key)] = $value;
        }

        return $uuid ? $fields + $uuid : $fields;
    }

    /**
     * @param array<string, string> $c normalized content
     * @param array<string, string|null> $changes
     * @return array<string, string|null>
     */
    private static function onlyDifferent(array $c, array $changes): array
    {
        return array_filter(
            $changes,
            fn ($value, $key) => $value === null ? array_key_exists($key, $c) : (string) ($c[$key] ?? '') !== $value || !array_key_exists($key, $c),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, string>
     */
    private static function normalize(array $fields): array
    {
        $normalized = [];
        foreach ($fields as $key => $value) {
            $normalized[self::normalizeKey($key)] = $value;
        }

        return $normalized;
    }

    private static function normalizeKey(string $key): string
    {
        return str_replace(['-', ' '], '_', strtolower(trim($key)));
    }

    private static function fileKey(string $key): string
    {
        return ucfirst(str_replace('_', '-', $key));
    }

    /**
     * @return string[]
     */
    private static function files(string $root, string $name): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getFilename() === $name) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
