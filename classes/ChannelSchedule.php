<?php

use Kirby\Cms\Page;

/**
 * Resolves which channels a screen plays right now.
 *
 * Every entry of the screen's `channel_schedule` can be limited by weekday,
 * date range and time of day. Entries are checked in their panel order:
 *
 * - mode "replace" (default): the first matching entry replaces the primary
 *   channel (`assigned_channel`).
 * - mode "add": every matching entry appends its channel's slides to the
 *   base channel.
 *
 * The matching itself is pure (no Kirby calls), so it can be unit-tested
 * with a fixed point in time.
 */
class ChannelSchedule
{
    public const CHANNEL_ROOT = 'signage/channels/';

    public const MODE_REPLACE = 'replace';
    public const MODE_ADD = 'add';

    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * Current time in the site's timezone.
     */
    public static function now(): DateTimeImmutable
    {
        $timezone = new DateTimeZone(kirby()->option('date.timezone', 'Europe/Berlin'));

        return new DateTimeImmutable('now', $timezone);
    }

    /**
     * Does a schedule entry apply at the given moment?
     *
     * Empty restrictions are ignored: no days = every day, no dates = always,
     * no times = the whole day. An end time before the start time spans
     * midnight (e.g. 22:00–02:00).
     */
    public static function matches(array $entry, DateTimeInterface $now): bool
    {
        $days = self::days($entry['days'] ?? null);
        if ($days !== [] && !in_array(strtolower($now->format('D')), $days, true)) {
            return false;
        }

        $today = $now->format('Y-m-d');
        $dateStart = self::date($entry['date_start'] ?? null);
        $dateEnd = self::date($entry['date_end'] ?? null);

        if ($dateStart !== null && $today < $dateStart) {
            return false;
        }
        if ($dateEnd !== null && $today > $dateEnd) {
            return false;
        }

        $start = self::seconds($entry['time_start'] ?? null);
        $end = self::seconds($entry['time_end'] ?? null);

        if ($start === null && $end === null) {
            return true;
        }

        $current = ((int) $now->format('G')) * 3600 + ((int) $now->format('i')) * 60 + (int) $now->format('s');
        $start ??= 0;
        $end ??= 86400;

        if ($end <= $start) {
            // Spans midnight.
            return $current >= $start || $current < $end;
        }

        return $current >= $start && $current < $end;
    }

    /**
     * Is the screen inside one of its `active_times` ranges?
     *
     * Ranges use `start`/`end`/`days`; the same rules as schedule entries
     * apply. No ranges at all = never active (the screen stays in standby).
     *
     * @param mixed $ranges
     */
    public static function isWithinActiveTimes($ranges, DateTimeInterface $now): bool
    {
        if (!is_array($ranges)) {
            return false;
        }

        foreach ($ranges as $range) {
            if (!is_array($range)) {
                continue;
            }

            $entry = [
                'days' => $range['days'] ?? null,
                'time_start' => $range['start'] ?? null,
                'time_end' => $range['end'] ?? null,
            ];

            // A range without any time is incomplete and never matches
            // (keeps the previous behaviour of skipping unparsable ranges).
            if (self::seconds($entry['time_start']) === null && self::seconds($entry['time_end']) === null) {
                continue;
            }

            if (self::matches($entry, $now)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Channel ids (page ids) active at the given moment, base channel first.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return string[]
     */
    public static function resolveIds(string $assignedChannel, array $entries, DateTimeInterface $now): array
    {
        $base = self::normalizeChannelId($assignedChannel);
        $added = [];
        $replaced = false;

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $channel = self::normalizeChannelId((string) ($entry['channel'] ?? ''));
            if ($channel === '' || !self::matches($entry, $now)) {
                continue;
            }

            if (self::mode($entry) === self::MODE_ADD) {
                $added[] = $channel;
            } elseif (!$replaced) {
                $base = $channel;
                $replaced = true;
            }
        }

        return array_values(array_unique(array_filter([$base, ...$added])));
    }

    /**
     * Channel pages a screen plays right now, base channel first.
     *
     * @return Page[]
     */
    public static function resolve(Page $screen, ?DateTimeInterface $now = null): array
    {
        $ids = self::resolveIds(
            (string) $screen->assigned_channel()->value(),
            self::entries($screen),
            $now ?? self::now(),
        );

        $channels = [];
        foreach ($ids as $id) {
            $channel = kirby()->page($id);
            if ($channel) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }

    /**
     * Every channel id the schedule can ever switch to (for change detection).
     *
     * @return string[]
     */
    public static function referencedIds(Page $screen): array
    {
        $ids = [self::normalizeChannelId((string) $screen->assigned_channel()->value())];

        foreach (self::entries($screen) as $entry) {
            if (is_array($entry)) {
                $ids[] = self::normalizeChannelId((string) ($entry['channel'] ?? ''));
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Schedule entries as plain arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function entries(Page $screen): array
    {
        $entries = $screen->channel_schedule()->yaml();

        return is_array($entries) ? $entries : [];
    }

    /**
     * Accepts both the full page id ("signage/channels/foo") and the bare
     * slug ("foo") that older schedule entries may contain.
     */
    public static function normalizeChannelId(string $value): string
    {
        $value = trim(preg_replace('/^page:\/\//', '', trim($value)), '/');

        if ($value === '') {
            return '';
        }

        return str_contains($value, '/') ? $value : self::CHANNEL_ROOT . $value;
    }

    private static function mode(array $entry): string
    {
        return ($entry['mode'] ?? '') === self::MODE_ADD ? self::MODE_ADD : self::MODE_REPLACE;
    }

    /**
     * @return string[]
     */
    private static function days($value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }

        $days = array_map(fn ($day) => strtolower(trim((string) $day)), $value);

        return array_values(array_intersect($days, self::DAYS));
    }

    private static function date($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    private static function seconds($value): ?int
    {
        $value = trim((string) $value);
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?/', $value, $match)) {
            return null;
        }

        return ((int) $match[1]) * 3600 + ((int) $match[2]) * 60 + (int) ($match[3] ?? 0);
    }
}
