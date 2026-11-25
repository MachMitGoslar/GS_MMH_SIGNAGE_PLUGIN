<?php

/**
 * Duration Calculator
 *
 * Calculates optimal display duration for different slide types based on content.
 * The channel decides how long a slide runs (a slide can be used in several
 * channels): a duration set in the channel's slide row wins, otherwise the
 * duration is calculated from the content.
 *
 * @package GS\MMH\Signage
 */
class DurationCalculator
{
    /**
     * Character reading speed (characters per second)
     * Based on average reading speed of ~250 words/min ≈ 15 chars/sec (slow, comfortable pace)
     */
    private const CHARS_PER_SECOND = 15;

    /**
     * Base durations for different content types (seconds)
     */
    private const BASE_DURATION_IMAGE = 8;
    private const BASE_DURATION_HEADING = 3;
    private const BASE_DURATION_CALENDAR = 30;

    /**
     * Duration limits (seconds)
     */
    private const MIN_DURATION = 5;
    private const MAX_DURATION = 120;

    /**
     * Limits for a duration set by hand in a channel (seconds)
     */
    public const MIN_CHANNEL_DURATION = 3;
    public const MAX_CHANNEL_DURATION = 300;

    /**
     * Duration of a slide within a channel: the channel's value, else the
     * calculated one.
     *
     * @param \Kirby\Cms\Page $slide
     * @param \Kirby\Cms\Page|null $channel
     */
    public static function forChannel($slide, $channel = null): int
    {
        $set = $channel ? SlideLibrary::durationIn($channel, $slide) : null;
        $set ??= self::legacyDuration($slide);

        if ($set === null) {
            return self::calculate($slide);
        }

        return max(self::MIN_CHANNEL_DURATION, min($set, self::MAX_CHANNEL_DURATION));
    }

    /**
     * Duration set by hand on the slide itself (before channels decided).
     * Still honoured while a channel sets none, so existing slides keep
     * their timing; the slide panel no longer offers the field.
     *
     * @param \Kirby\Cms\Page $slide
     */
    public static function legacyDuration($slide): ?int
    {
        if ($slide->duration_mode()->value() !== 'manual') {
            return null;
        }

        $seconds = (int) $slide->duration_override()->value();

        return $seconds > 0 ? $seconds : null;
    }

    /**
     * Calculate total duration for a slide
     *
     * @param \Kirby\Cms\Page $slide The slide page object
     * @return int Duration in seconds
     */
    public static function calculate($slide): int
    {
        $slideType = $slide->slide_type()->value();

        $duration = match ($slideType) {
            'blocks' => self::calculateBlocksDuration($slide),
            'template' => self::calculateTemplateDuration($slide),
            'video' => self::calculateVideoDuration($slide),
            'calendar' => self::calculateCalendarDuration($slide),
            'christmas_tree' => self::calculateChristmasTreeDuration($slide),
            default => 10
        };

        // Apply min/max constraints
        return max(self::MIN_DURATION, min($duration, self::MAX_DURATION));
    }

    /**
     * Get human-readable calculation details
     *
     * @param \Kirby\Cms\Page $slide The slide page object
     * @return string Description of how duration was calculated
     */
    public static function getCalculationDetails($slide): string
    {
        $slideType = $slide->slide_type()->value();

        return match ($slideType) {
            'blocks' => self::getBlocksCalculationDetails($slide),
            'template' => self::getTemplateCalculationDetails($slide),
            'video' => self::getVideoCalculationDetails($slide),
            'calendar' => self::getCalendarCalculationDetails($slide),
            'christmas_tree' => self::getChristmasTreeCalculationDetails($slide),
            default => 'Default: 10 seconds'
        };
    }

    /**
     * Calculate duration for block-based slides
     *
     * @param \Kirby\Cms\Page $slide
     * @return int Duration in seconds
     */
    public static function calculateBlocksDuration($slide): int
    {
        $layout = $slide->content_layout()->toLayouts();
        $totalDuration = 0;

        if ($layout->count() === 0) {
            return self::MIN_DURATION;
        }

        // Iterate through layout rows and columns
        foreach ($layout as $layoutRow) {
            foreach ($layoutRow->columns() as $column) {
                foreach ($column->blocks() as $block) {
                    $blockDuration = match ($block->type()) {
                        'heading' => self::BASE_DURATION_HEADING,
                        'text' => self::calculateTextDuration($block->text()->value()),
                        'image' => self::calculateImageBlockDuration($block),
                        'list' => self::calculateListDuration($block),
                        'quote' => self::calculateTextDuration($block->text()->value()) + 2,
                        'line' => 1, // Line separator - minimal duration
                        default => 5
                    };

                    $totalDuration += $blockDuration;
                }
            }
        }

        return $totalDuration > 0 ? $totalDuration : self::MIN_DURATION;
    }

    /**
     * Get calculation details for block slides
     */
    private static function getBlocksCalculationDetails($slide): string
    {
        $layout = $slide->content_layout()->toLayouts();

        if ($layout->count() === 0) {
            return 'No content: minimum ' . self::MIN_DURATION . ' seconds';
        }

        $details = [];
        foreach ($layout as $layoutRow) {
            foreach ($layoutRow->columns() as $column) {
                foreach ($column->blocks() as $block) {
                    $type = ucfirst($block->type());
                    $duration = match ($block->type()) {
                        'heading' => self::BASE_DURATION_HEADING,
                        'text' => self::calculateTextDuration($block->text()->value()),
                        'image' => self::calculateImageBlockDuration($block),
                        'list' => self::calculateListDuration($block),
                        'quote' => self::calculateTextDuration($block->text()->value()) + 2,
                        'line' => 1,
                        default => 5
                    };
                    $details[] = "{$type}: {$duration}s";
                }
            }
        }

        return implode(' + ', $details);
    }

    /**
     * Calculate duration for text content
     *
     * @param string $text Text content (may contain HTML)
     * @return int Duration in seconds
     */
    private static function calculateTextDuration(string $text): int
    {
        // Strip HTML tags and count characters
        $cleanText = strip_tags($text);
        $charCount = mb_strlen($cleanText);

        // Calculate reading time
        $duration = (int) ceil($charCount / self::CHARS_PER_SECOND);

        return max(self::MIN_DURATION, $duration);
    }

    /**
     * Calculate duration for image blocks
     *
     * @param \Kirby\Cms\Block $block
     * @return int Duration in seconds
     */
    private static function calculateImageBlockDuration($block): int
    {
        $baseDuration = self::BASE_DURATION_IMAGE;

        // Add time for caption if present
        $caption = $block->caption()->value();
        if (!empty($caption)) {
            $baseDuration += self::calculateTextDuration($caption);
        }

        // Add time for alt text if present (fallback)
        $alt = $block->alt()->value();
        if (empty($caption) && !empty($alt)) {
            $baseDuration += self::calculateTextDuration($alt);
        }

        return min($baseDuration, 60); // Cap image duration at 60s
    }

    /**
     * Calculate duration for list blocks
     *
     * @param \Kirby\Cms\Block $block
     * @return int Duration in seconds
     */
    private static function calculateListDuration($block): int
    {
        $text = $block->text()->value();
        $items = explode("\n", strip_tags($text));
        $itemCount = count(array_filter($items)); // Filter empty lines

        // Base duration + per-item duration
        return 3 + ($itemCount * 2); // 3s base + 2s per item
    }

    /**
     * Calculate duration for template slides
     *
     * @param \Kirby\Cms\Page $slide
     * @return int Duration in seconds
     */
    private static function calculateTemplateDuration($slide): int
    {
        if ($slide->template_preset()->value() === 'list') {
            return self::calculateListTemplateDuration($slide);
        }

        $textParts = [
            $slide->template_title()->value(),
            $slide->template_subtitle()->value(),
            $slide->template_label()->value(),
            $slide->template_message()->value(),
            $slide->template_highlight()->value(),
            $slide->template_footer()->value(),
            $slide->template_date()->value(),
            $slide->template_time()->value(),
            $slide->template_end_time()->value(),
            $slide->template_location()->value(),
        ];

        $textDuration = self::calculateTextDuration(implode(' ', array_filter($textParts)));

        return max(10, $textDuration + 4);
    }

    /**
     * List template: heading, then every card gets its reading time (at
     * least 4 s); the cards are revealed one after another.
     */
    private static function calculateListTemplateDuration($slide): int
    {
        $head = implode(' ', array_filter([
            $slide->template_label()->value(),
            $slide->template_title()->value(),
            $slide->template_message()->value(),
            $slide->template_footer()->value(),
        ]));
        $duration = (int) ceil(mb_strlen(strip_tags($head)) / self::CHARS_PER_SECOND) + 2;

        foreach (AccessController::templateItems($slide) as $item) {
            $chars = mb_strlen($item['meta'] . ' ' . $item['title'] . ' ' . $item['text']);
            $duration += max(4, (int) ceil($chars / self::CHARS_PER_SECOND) + 1);
        }

        return max(10, $duration);
    }

    /**
     * Get calculation details for template slides
     */
    private static function getTemplateCalculationDetails($slide): string
    {
        $duration = self::calculateTemplateDuration($slide);

        if ($slide->template_preset()->value() === 'list') {
            $count = count(AccessController::templateItems($slide));

            return "Liste mit {$count} Karten: {$duration} seconds";
        }

        return "Template fields: {$duration} seconds";
    }

    /**
     * Calculate duration for video slides
     *
     * @param \Kirby\Cms\Page $slide
     * @return int Duration in seconds
     */
    private static function calculateVideoDuration($slide): int
    {
        $videoSource = $slide->video_source()->value();

        if ($videoSource === 'upload') {
            $videoFile = $slide->video_file()->toFile();
            if ($videoFile) {
                // Try to get duration from file metadata
                $duration = self::getVideoFileDuration($videoFile);
                if ($duration) {
                    return $duration;
                }
            }
        }

        // For embeds or if duration detection fails
        // Default to 30 seconds (can be enhanced with API calls to YouTube/Vimeo)
        return 30;
    }

    /**
     * Get calculation details for video slides
     */
    private static function getVideoCalculationDetails($slide): string
    {
        $videoSource = $slide->video_source()->value();

        if ($videoSource === 'upload') {
            $videoFile = $slide->video_file()->toFile();
            if ($videoFile) {
                $duration = self::getVideoFileDuration($videoFile);
                if ($duration) {
                    return "Video file duration: {$duration} seconds";
                }

                return 'Video file: duration detection unavailable (default 30s)';
            }

            return 'No video file uploaded (default 30s)';
        }

        return 'External video embed (default 30s)';
    }

    /**
     * Extract video duration from file metadata
     *
     * @param \Kirby\Cms\File $file
     * @return int|null Duration in seconds, or null if unavailable
     */
    private static function getVideoFileDuration($file): ?int
    {
        // Attempt to read duration from file metadata
        // This requires either getID3 library or FFmpeg/FFprobe

        // Check if getID3 is available
        if (class_exists('getID3')) {
            try {
                $getID3 = new getID3();
                $fileInfo = $getID3->analyze($file->root());

                if (isset($fileInfo['playtime_seconds'])) {
                    return (int) round($fileInfo['playtime_seconds']);
                }
            } catch (Exception $e) {
                // Silently fail, return null
            }
        }

        // Fallback: Try FFprobe if available
        if (function_exists('exec')) {
            $ffprobePath = trim(shell_exec('which ffprobe 2>/dev/null') ?? '');
            if (!empty($ffprobePath)) {
                $command = escapeshellcmd($ffprobePath) . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($file->root());
                $duration = trim(shell_exec($command) ?? '');

                if (is_numeric($duration)) {
                    return (int) round((float) $duration);
                }
            }
        }

        return null;
    }

    /**
     * Calculate duration for calendar slides
     *
     * @param \Kirby\Cms\Page $slide
     * @return int Duration in seconds
     */
    private static function calculateCalendarDuration($slide): int
    {
        $baseDuration = self::BASE_DURATION_CALENDAR;

        // If using Kirby pages, count events
        $sourceType = $slide->calendar_source_type()->value();
        if ($sourceType === 'pages') {
            $pages = $slide->calendar_pages()->toPages();
            if ($pages && $pages->count() > 0) {
                $eventCount = $pages->count();
                // Add 3 seconds per event (max 10 events counted)
                $baseDuration += min($eventCount, 10) * 3;
            }
        }

        return min($baseDuration, 120); // Cap calendar at 2 minutes
    }

    /**
     * Get calculation details for calendar slides
     */
    private static function getCalendarCalculationDetails($slide): string
    {
        $sourceType = $slide->calendar_source_type()->value();

        if ($sourceType === 'pages') {
            $pages = $slide->calendar_pages()->toPages();
            $count = $pages ? $pages->count() : 0;

            return "Calendar base (30s) + {$count} events (×3s)";
        }

        return 'Calendar base duration: ' . self::BASE_DURATION_CALENDAR . ' seconds';
    }

    /**
     * Christmas tree: one round shows up to "visible balls" greetings, each for
     * "seconds per greeting", plus a short intro/re-hanging phase.
     * Without approved greetings the empty tree is shown briefly.
     *
     * @param \Kirby\Cms\Page $slide
     * @return int Duration in seconds
     */
    public static function calculateChristmasTreeDuration($slide): int
    {
        $count = ChristmasTree::greetingsPerRound($slide);

        if ($count === 0) {
            return 8;
        }

        return $count * ChristmasTree::secondsPerGreeting($slide) + ChristmasTree::INTRO_SECONDS;
    }

    private static function getChristmasTreeCalculationDetails($slide): string
    {
        $count = ChristmasTree::greetingsPerRound($slide);
        $total = ChristmasTree::approvedGreetings($slide)->count();
        $duration = max(
            self::MIN_DURATION,
            min(self::calculateChristmasTreeDuration($slide), self::MAX_DURATION),
        );

        if ($count === 0) {
            return "Weihnachtsbaum ohne freigegebene Grüße: {$duration} seconds";
        }

        return "Weihnachtsbaum: {$count} von {$total} Grüßen pro Durchgang × "
            . ChristmasTree::secondsPerGreeting($slide) . ' s + '
            . ChristmasTree::INTRO_SECONDS . " s = {$duration} seconds";
    }
}
