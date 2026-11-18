<?php

/**
 * Christmas Tree
 *
 * Helpers for the "christmas_tree" slide type: approved greetings hang as
 * baubles on a digital tree. Greetings are child pages (template
 * "signage-greeting") of the tree slide; draft = waiting for moderation,
 * listed = approved and shown on the screen.
 *
 * @package GS\MMH\Signage
 */
class ChristmasTree
{
    public const SLIDE_TYPE = 'christmas_tree';
    public const GREETING_TEMPLATE = 'signage-greeting';

    public const MAX_NAME_LENGTH = 40;
    public const MAX_TEXT_LENGTH = 140;
    public const MAX_TEXT_LINES = 3;

    public const DEFAULT_VISIBLE_BALLS = 6;
    public const DEFAULT_SECONDS_PER_GREETING = 6;

    /**
     * Seconds for the intro (baubles swing in) and the re-hanging at the end of a round
     */
    public const INTRO_SECONDS = 4;

    public static function isTree($page): bool
    {
        return $page !== null
            && $page->intendedTemplate()->name() === 'slide'
            && $page->slide_type()->value() === self::SLIDE_TYPE;
    }

    /**
     * Approved greetings of a tree slide, newest first
     *
     * @return \Kirby\Cms\Pages
     */
    public static function approvedGreetings($slide)
    {
        return $slide->children()
            ->listed()
            ->filter(fn ($page) => $page->intendedTemplate()->name() === self::GREETING_TEMPLATE)
            ->sortBy('submitted_at', 'desc', 'title', 'asc');
    }

    public static function visibleBalls($slide): int
    {
        $value = $slide->tree_visible_balls()->toInt();

        return $value > 0 ? min($value, 12) : self::DEFAULT_VISIBLE_BALLS;
    }

    public static function secondsPerGreeting($slide): int
    {
        $value = $slide->tree_seconds_per_greeting()->toInt();

        return $value > 0 ? max(3, min($value, 20)) : self::DEFAULT_SECONDS_PER_GREETING;
    }

    /**
     * Number of greetings shown in one pass of the slide
     */
    public static function greetingsPerRound($slide): int
    {
        return min(self::visibleBalls($slide), self::approvedGreetings($slide)->count());
    }

    /**
     * Data for the player (JSON). Texts are raw; the player escapes them.
     */
    public static function data($slide): array
    {
        $greetings = [];

        foreach (self::approvedGreetings($slide) as $greeting) {
            $greetings[] = [
                'id' => $greeting->id(),
                'name' => $greeting->greeting_name()->value(),
                'text' => $greeting->greeting_text()->value(),
            ];
        }

        return [
            'title' => $slide->tree_title()->or('Frohe Weihnachten!')->value(),
            'subtitle' => $slide->tree_subtitle()->value(),
            'visible_balls' => self::visibleBalls($slide),
            'seconds_per_greeting' => self::secondsPerGreeting($slide),
            'greetings' => $greetings,
        ];
    }

    /**
     * Revision part so screens reload when a greeting is approved, edited or withdrawn.
     * Approving changes the status (folder move), not the slide itself.
     */
    public static function revision($slide): string
    {
        if (!self::isTree($slide)) {
            return '';
        }

        $parts = [];
        foreach (self::approvedGreetings($slide) as $greeting) {
            $parts[] = $greeting->id() . ':' . $greeting->modified('U');
        }

        return sha1(implode('|', $parts));
    }

    /**
     * Normalises a submitted greeting and checks the limits.
     *
     * @return array{name: string, text: string}|string Cleaned values, or an error message
     */
    public static function sanitize(?string $name, ?string $text): array|string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');

        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        $lines = array_map(fn ($line) => trim(preg_replace('/[ \t]+/u', ' ', $line) ?? ''), explode("\n", $text));
        $lines = array_values(array_filter($lines, fn ($line) => $line !== ''));
        $text = implode("\n", $lines);

        if ($name === '') {
            return 'Bitte gib deinen Namen an.';
        }
        if ($text === '') {
            return 'Bitte schreib einen Gruß.';
        }
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            return 'Der Name darf höchstens ' . self::MAX_NAME_LENGTH . ' Zeichen lang sein.';
        }
        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            return 'Der Gruß darf höchstens ' . self::MAX_TEXT_LENGTH . ' Zeichen lang sein.';
        }
        if (count($lines) > self::MAX_TEXT_LINES) {
            return 'Der Gruß darf höchstens ' . self::MAX_TEXT_LINES . ' Zeilen haben.';
        }

        return ['name' => $name, 'text' => $text];
    }
}
