<?php

use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Data\Yaml;

/**
 * Slide library
 *
 * Slides live in a central pool (signage/slides) and are referenced by
 * channels through the `slides` structure field: one row per slide with the
 * display duration this channel gives it (empty = calculated automatically).
 * Older channels store a plain list of page references; both shapes are
 * read, writes always use rows. This class resolves those
 * references, tells where a slide is used and migrates legacy slides
 * (stored as children of a channel) into the pool.
 */
class SlideLibrary
{
    public const POOL_ID = 'signage/slides';
    public const CHANNELS_ID = 'signage/channels';

    /** @var array<string,string[]> slide id => channel ids, filled before a slide is deleted */
    private static array $pendingDetach = [];

    public static function pool(): ?Page
    {
        return kirby()->page(self::POOL_ID);
    }

    /**
     * All channels (including drafts)
     */
    public static function channels(): Pages
    {
        $root = kirby()->page(self::CHANNELS_ID);

        return $root ? $root->childrenAndDrafts() : new Pages([]);
    }

    /**
     * Slides referenced by a channel, in channel order (drafts included).
     */
    public static function slidesOf(Page $channel): Pages
    {
        $slides = [];

        foreach (self::references($channel) as $reference) {
            $slide = kirby()->page($reference);
            if ($slide && $slide->intendedTemplate()->name() === 'slide') {
                $slides[$slide->id()] = $slide;
            }
        }

        return new Pages(array_values($slides));
    }

    /**
     * Slides that are actually shown by the player (active only).
     */
    public static function activeSlidesOf(Page $channel): Pages
    {
        return self::slidesOf($channel)->filter(
            fn (Page $slide) => $slide->status() === 'unlisted',
        );
    }

    /**
     * Channels that reference the given slide.
     */
    public static function channelsUsing(Page $slide): Pages
    {
        return self::channels()->filter(
            fn (Page $channel) => self::slidesOf($channel)->has($slide->id()),
        );
    }

    /**
     * Display duration the channel sets for the slide, null = automatic.
     */
    public static function durationIn(Page $channel, Page $slide): ?int
    {
        foreach (self::entries($channel) as $entry) {
            $page = kirby()->page($entry['slide']);
            if ($page && $page->id() === $slide->id()) {
                return $entry['duration'];
            }
        }

        return null;
    }

    /**
     * Rows of the channel's slides field: reference + duration (null = auto).
     * Reads the structure rows and the older plain list of references.
     *
     * @return array<int, array{slide:string, duration:?int}>
     */
    public static function entries(Page $channel): array
    {
        $rows = self::decode($channel->content()->get('slides')->value());
        $entries = [];

        foreach ($rows as $row) {
            $reference = null;
            $duration = null;

            if (is_string($row)) {
                $reference = $row;
            } elseif (is_array($row)) {
                $slide = $row['slide'] ?? null;
                $reference = is_array($slide) ? ($slide[0] ?? null) : $slide;
                // a pages field stores "- page://x"; tolerate "page://x" too
                if (is_string($reference)) {
                    $reference = ltrim(trim($reference), '- ');
                }
                $seconds = trim((string) ($row['duration'] ?? ''));
                $duration = is_numeric($seconds) && (int) $seconds > 0 ? (int) $seconds : null;
            }

            if (is_string($reference) && trim($reference) !== '') {
                $entries[] = ['slide' => trim($reference), 'duration' => $duration];
            }
        }

        return $entries;
    }

    /**
     * Rows for the panel field (`signage_slides`): the stored rows, or the
     * older plain reference list turned into rows without a duration.
     *
     * @param mixed $value
     * @return array<int, array{slide:string[], duration:int|string}>
     */
    public static function rows($value): array
    {
        $entries = [];
        foreach (self::decode($value) as $row) {
            if (is_string($row) && trim($row) !== '') {
                $entries[] = ['slide' => [trim($row)], 'duration' => ''];
            } elseif (is_array($row)) {
                $entries[] = $row;
            }
        }

        return $entries;
    }

    /**
     * @param mixed $value
     * @return array<int, mixed>
     */
    private static function decode($value): array
    {
        if (is_string($value)) {
            $value = trim($value) === '' ? [] : Yaml::decode($value);
        }

        return array_values((array) $value);
    }

    /**
     * Raw references stored in the channel's slides field.
     *
     * @return string[]
     */
    private static function references(Page $channel): array
    {
        return array_column(self::entries($channel), 'slide');
    }

    /**
     * Encodes rows for the slides structure field.
     *
     * @param array<int, array{slide:string, duration:?int}> $entries
     */
    public static function encode(array $entries): string
    {
        return Yaml::encode(array_map(fn (array $entry) => [
            'slide' => [$entry['slide']],
            'duration' => $entry['duration'] ?? '',
        ], array_values($entries)));
    }

    /**
     * Removes a slide from a channel's slides field.
     */
    public static function detach(Page $channel, Page $slide): void
    {
        $remaining = array_filter(self::entries($channel), function (array $entry) use ($slide) {
            $page = kirby()->page($entry['slide']);

            return !($page && $page->id() === $slide->id());
        });

        $channel->update(['slides' => self::encode($remaining)]);
    }

    /**
     * Number of legacy slides still stored below channels.
     */
    public static function pendingMigrationCount(): int
    {
        $count = 0;

        foreach (self::channels() as $channel) {
            foreach ($channel->childrenAndDrafts() as $child) {
                if ($child->intendedTemplate()->name() === 'slide') {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Moves legacy slides from channels into the pool and links them back
     * to their channel. Idempotent; slugs are made unique on collision.
     *
     * @return array{moved:int,channels:int,renamed:string[]}
     */
    public static function migrate(): array
    {
        $pool = self::pool();
        if (!$pool) {
            throw new RuntimeException('Folien-Bibliothek (signage/slides) existiert nicht.');
        }

        $result = ['moved' => 0, 'channels' => 0, 'renamed' => []];

        foreach (self::channels() as $channel) {
            $legacy = $channel->childrenAndDrafts()->filter(
                fn (Page $child) => $child->intendedTemplate()->name() === 'slide',
            );
            if ($legacy->count() === 0) {
                continue;
            }

            // keep the order the player used so far: active slides first
            $ordered = array_merge(
                $legacy->filterBy('isDraft', false)->values(),
                $legacy->filterBy('isDraft', true)->values(),
            );

            $entries = self::entries($channel);
            $known = array_column($entries, 'slide');

            foreach ($ordered as $slide) {
                $slug = $slide->slug();
                $unique = $slug;
                $i = 2;
                while ($pool->childrenAndDrafts()->find($unique)) {
                    $unique = $slug . '-' . $i++;
                }
                if ($unique !== $slug) {
                    $slide = $slide->changeSlug($unique);
                    $result['renamed'][] = $slug . ' → ' . $unique;
                }

                $moved = $slide->move($pool);
                $moved->uuid()?->populate();
                $reference = $moved->uuid()?->toString() ?? $moved->id();
                if (!in_array($reference, $known, true)) {
                    $entries[] = ['slide' => $reference, 'duration' => null];
                    $known[] = $reference;
                }
                $result['moved']++;
            }

            $channel->update(['slides' => self::encode($entries)]);
            $result['channels']++;
        }

        return $result;
    }

    /**
     * Guard for slide deletion: only allowed while the slide belongs
     * to at most one channel.
     */
    public static function guardDelete(Page $slide): void
    {
        $channels = self::channelsUsing($slide);

        if ($channels->count() > 1) {
            throw new Exception(
                'Die Folie wird noch in ' . $channels->count() . ' Kanälen verwendet ('
                . implode(', ', array_map(fn ($c) => $c->title()->value(), $channels->values())) . '). '
                . 'Bitte zuerst aus den Kanälen entfernen, bis nur noch einer übrig ist.',
            );
        }

        self::$pendingDetach[$slide->id()] = array_map(fn ($c) => $c->id(), $channels->values());
    }

    /**
     * Cleans up channel references after a slide has been deleted.
     */
    public static function afterDelete(Page $slide): void
    {
        $channelIds = self::$pendingDetach[$slide->id()] ?? [];
        unset(self::$pendingDetach[$slide->id()]);

        foreach ($channelIds as $channelId) {
            $channel = kirby()->page($channelId);
            if ($channel) {
                self::detach($channel, $slide);
            }
        }
    }
}
