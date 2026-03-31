<?php

/**
 * ICalParser
 *
 * Parses iCal/ICS feeds for calendar slide display.
 * Supports standard iCal format from Google Calendar, Outlook, etc.
 *
 * @package GS\MMH\Signage
 */
class ICalParser
{
    /**
     * Resolve the timezone used for local calendar comparisons and display.
     */
    private static function getLocalTimezone(): DateTimeZone
    {
        try {
            return new DateTimeZone(date_default_timezone_get() ?: 'Europe/Berlin');
        } catch (Exception $e) {
            return new DateTimeZone('Europe/Berlin');
        }
    }

    /**
     * Fetch and parse events from an iCal URL
     *
     * @param string $url iCal feed URL
     * @param string $range Date range filter (today, week, month)
     * @param int $maxEvents Maximum events to return
     * @return array Parsed events
     */
    public static function fetchEvents(string $url, string $range = 'week', int $maxEvents = 5): array
    {
        try {
            // Fetch iCal data
            $icalData = self::fetchIcalData($url);

            if (! $icalData) {
                return [
                    'error' => true,
                    'message' => 'Failed to fetch calendar data',
                    'events' => [],
                ];
            }

            // Parse events
            $events = self::parseIcal($icalData);

            // Filter by date range
            $events = self::filterByRange($events, $range);

            // Sort by start date
            usort($events, function ($a, $b) {
                return $a['start_timestamp'] <=> $b['start_timestamp'];
            });

            // Limit to max events
            $events = array_slice($events, 0, $maxEvents);

            return [
                'error' => false,
                'events' => $events,
                'fetched_at' => date('Y-m-d H:i:s'),
            ];
        } catch (Exception $e) {
            error_log('ICalParser Error: ' . $e->getMessage());

            return [
                'error' => true,
                'message' => $e->getMessage(),
                'events' => [],
            ];
        }
    }

    /**
     * Fetch iCal data from URL
     */
    private static function fetchIcalData(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'GS-MMH-Signage/1.0',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $data = @file_get_contents($url, false, $context);

        if ($data === false) {
            // Try with cURL as fallback
            if (function_exists('curl_init')) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_USERAGENT => 'GS-MMH-Signage/1.0',
                ]);
                $data = curl_exec($ch);
                curl_close($ch);
            }
        }

        return $data ?: null;
    }

    /**
     * Parse iCal data into events array
     */
    private static function parseIcal(string $icalData): array
    {
        $events = [];

        // Normalize line endings
        $icalData = str_replace(["\r\n", "\r"], "\n", $icalData);

        // Unfold long lines (iCal spec allows line folding with space/tab)
        $icalData = preg_replace("/\n[ \t]/", "", $icalData);

        // Split into lines
        $lines = explode("\n", $icalData);

        $inEvent = false;
        $currentEvent = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === 'BEGIN:VEVENT') {
                $inEvent = true;
                $currentEvent = [];

                continue;
            }

            if ($line === 'END:VEVENT') {
                $inEvent = false;
                if (! empty($currentEvent)) {
                    $events[] = self::processEvent($currentEvent);
                }

                continue;
            }

            if ($inEvent && strpos($line, ':') !== false) {
                // Parse property
                $colonPos = strpos($line, ':');
                $property = substr($line, 0, $colonPos);
                $value = substr($line, $colonPos + 1);

                // Handle properties with parameters (e.g., DTSTART;TZID=Europe/Berlin:20240115T100000)
                $propertyName = $property;
                $propertyParams = [];

                if (strpos($property, ';') !== false) {
                    $parts = explode(';', $property);
                    $propertyName = $parts[0];
                    for ($i = 1; $i < count($parts); $i++) {
                        if (strpos($parts[$i], '=') !== false) {
                            list($paramName, $paramValue) = explode('=', $parts[$i], 2);
                            $propertyParams[$paramName] = $paramValue;
                        }
                    }
                }

                $currentEvent[$propertyName] = [
                    'value' => $value,
                    'params' => $propertyParams,
                ];
            }
        }

        return $events;
    }

    /**
     * Process a single event into standardized format
     */
    private static function processEvent(array $eventData): array
    {
        $event = [
            'title' => '',
            'description' => '',
            'location' => '',
            'start' => null,
            'end' => null,
            'start_timestamp' => 0,
            'end_timestamp' => 0,
            'all_day' => false,
            'uid' => '',
            'rrule' => null,
        ];

        // Title/Summary
        if (isset($eventData['SUMMARY'])) {
            $event['title'] = self::decodeIcalText($eventData['SUMMARY']['value']);
        }

        // Description
        if (isset($eventData['DESCRIPTION'])) {
            $event['description'] = self::decodeIcalText($eventData['DESCRIPTION']['value']);
        }

        // Location
        if (isset($eventData['LOCATION'])) {
            $event['location'] = self::decodeIcalText($eventData['LOCATION']['value']);
        }

        // UID
        if (isset($eventData['UID'])) {
            $event['uid'] = $eventData['UID']['value'];
        }

        if (isset($eventData['RRULE'])) {
            $event['rrule'] = $eventData['RRULE']['value'];
        }

        // Start date/time
        if (isset($eventData['DTSTART'])) {
            $dtStart = self::parseIcalDate($eventData['DTSTART']);
            $event['start'] = $dtStart['formatted'];
            $event['start_timestamp'] = $dtStart['timestamp'];
            $event['all_day'] = $dtStart['all_day'];
        }

        // End date/time
        if (isset($eventData['DTEND'])) {
            $dtEnd = self::parseIcalDate($eventData['DTEND']);
            $event['end'] = $dtEnd['formatted'];
            $event['end_timestamp'] = $dtEnd['timestamp'];
        }

        return $event;
    }

    /**
     * Parse iCal date/time value
     */
    private static function parseIcalDate(array $dateData): array
    {
        $value = $dateData['value'];
        $params = $dateData['params'] ?? [];
        $timezone = $params['TZID'] ?? null;
        $localTimezone = self::getLocalTimezone();

        $allDay = false;
        $timestamp = 0;
        $formatted = '';

        // Check if it's a date-only value (all-day event)
        if (isset($params['VALUE']) && $params['VALUE'] === 'DATE') {
            $allDay = true;
        }

        // Remove any 'Z' suffix (UTC indicator)
        $isUtc = (substr($value, -1) === 'Z');
        $value = rtrim($value, 'Z');

        if (strlen($value) === 8) {
            // Date only: YYYYMMDD
            $allDay = true;
            $date = DateTime::createFromFormat('Ymd', $value, $localTimezone);
            if ($date) {
                $date->setTimezone($localTimezone);
                $timestamp = $date->getTimestamp();
                $formatted = $date->format('Y-m-d');
            }
        } elseif (strlen($value) >= 15) {
            // Date and time: YYYYMMDDTHHMMSS
            $dateValue = str_replace('T', '', $value);
            $parseTimezone = $localTimezone;

            if ($isUtc) {
                $parseTimezone = new DateTimeZone('UTC');
            } elseif ($timezone) {
                try {
                    $parseTimezone = new DateTimeZone($timezone);
                } catch (Exception $e) {
                    $parseTimezone = $localTimezone;
                }
            }

            $date = DateTime::createFromFormat('YmdHis', substr($dateValue, 0, 14), $parseTimezone);

            if ($date) {
                // Convert to local timezone for display
                $date->setTimezone($localTimezone);

                $timestamp = $date->getTimestamp();
                $formatted = $date->format('Y-m-d H:i');
            }
        }

        return [
            'timestamp' => $timestamp,
            'formatted' => $formatted,
            'all_day' => $allDay,
        ];
    }

    /**
     * Decode iCal text (handle escaped characters)
     */
    private static function decodeIcalText(string $text): string
    {
        // Decode escaped characters
        $text = str_replace(
            ['\\n', '\\N', '\\,', '\\;', '\\\\'],
            ["\n", "\n", ',', ';', '\\'],
            $text
        );

        return trim($text);
    }

    /**
     * Filter events by date range
     */
    private static function filterByRange(array $events, string $range): array
    {
        $now = new DateTime('now', self::getLocalTimezone());
        $now->setTime(0, 0, 0);

        $startTimestamp = $now->getTimestamp();

        switch ($range) {
            case 'today':
                $end = clone $now;
                $end->setTime(23, 59, 59);
                $endTimestamp = $end->getTimestamp();

                break;

            case 'week':
                $end = clone $now;
                $end->modify('+7 days');
                $end->setTime(23, 59, 59);
                $endTimestamp = $end->getTimestamp();

                break;

            case 'month':
                $end = clone $now;
                $end->modify('+30 days');
                $end->setTime(23, 59, 59);
                $endTimestamp = $end->getTimestamp();

                break;

            default:
                // Default to week
                $end = clone $now;
                $end->modify('+7 days');
                $endTimestamp = $end->getTimestamp();
        }

        return self::expandEventsForRange($events, $startTimestamp, $endTimestamp);
    }

    /**
     * Expand recurring events into individual occurrences within the active range.
     */
    private static function expandEventsForRange(array $events, int $startTimestamp, int $endTimestamp): array
    {
        $expandedEvents = [];

        foreach ($events as $event) {
            if (empty($event['start_timestamp'])) {
                continue;
            }

            if (empty($event['rrule'])) {
                if ($event['start_timestamp'] >= $startTimestamp && $event['start_timestamp'] <= $endTimestamp) {
                    $expandedEvents[] = $event;
                }

                continue;
            }

            foreach (self::expandRecurringEvent($event, $startTimestamp, $endTimestamp) as $occurrence) {
                $expandedEvents[] = $occurrence;
            }
        }

        return $expandedEvents;
    }

    /**
     * Expand a single recurring event into concrete occurrences.
     */
    private static function expandRecurringEvent(array $event, int $rangeStartTimestamp, int $rangeEndTimestamp): array
    {
        $rule = self::parseRRule($event['rrule'] ?? '');

        if (empty($rule['FREQ'])) {
            return [];
        }

        $timezone = self::getLocalTimezone();
        $baseStart = self::createLocalDateTimeFromTimestamp($event['start_timestamp']);
        $baseEnd = ! empty($event['end_timestamp'])
            ? self::createLocalDateTimeFromTimestamp($event['end_timestamp'])
            : null;
        $rangeStart = self::createLocalDateTimeFromTimestamp($rangeStartTimestamp)->setTime(0, 0, 0);
        $rangeEnd = self::createLocalDateTimeFromTimestamp($rangeEndTimestamp);
        $duration = $baseEnd ? $baseEnd->getTimestamp() - $baseStart->getTimestamp() : 0;
        $countLimit = isset($rule['COUNT']) ? (int) $rule['COUNT'] : null;
        $until = self::parseRRuleUntil($rule['UNTIL'] ?? null);
        $occurrences = [];
        $occurrenceCount = 0;
        $scanDate = $baseStart->setTime(0, 0, 0);
        $scanEnd = $rangeEnd->setTime(23, 59, 59);

        while ($scanDate <= $scanEnd) {
            $occurrenceStart = self::buildOccurrenceStart($scanDate, $baseStart, $event['all_day']);

            if (
                $occurrenceStart >= $baseStart &&
                self::matchesRecurrenceRule($occurrenceStart, $baseStart, $rule)
            ) {
                $occurrenceCount++;

                if ($countLimit !== null && $occurrenceCount > $countLimit) {
                    break;
                }

                if ($until && $occurrenceStart > $until) {
                    break;
                }

                if ($occurrenceStart->getTimestamp() >= $rangeStartTimestamp && $occurrenceStart->getTimestamp() <= $rangeEndTimestamp) {
                    $occurrence = $event;
                    $occurrence['start_timestamp'] = $occurrenceStart->getTimestamp();
                    $occurrence['start'] = $event['all_day']
                        ? $occurrenceStart->format('Y-m-d')
                        : $occurrenceStart->format('Y-m-d H:i');

                    if ($duration > 0) {
                        $occurrenceEnd = $occurrenceStart->modify('+' . $duration . ' seconds');
                        $occurrence['end_timestamp'] = $occurrenceEnd->getTimestamp();
                        $occurrence['end'] = $event['all_day']
                            ? $occurrenceEnd->format('Y-m-d')
                            : $occurrenceEnd->format('Y-m-d H:i');
                    }

                    $occurrences[] = $occurrence;
                }
            }

            $scanDate = $scanDate->modify('+1 day');
        }

        return $occurrences;
    }

    private static function parseRRule(string $rrule): array
    {
        $parsed = [];

        foreach (explode(';', $rrule) as $part) {
            if (strpos($part, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $part, 2);
            $parsed[strtoupper(trim($key))] = trim($value);
        }

        return $parsed;
    }

    private static function parseRRuleUntil(?string $until): ?DateTimeImmutable
    {
        if (empty($until)) {
            return null;
        }

        $parsed = self::parseIcalDate([
            'value' => $until,
            'params' => [],
        ]);

        if (empty($parsed['timestamp'])) {
            return null;
        }

        return self::createLocalDateTimeFromTimestamp($parsed['timestamp']);
    }

    private static function createLocalDateTimeFromTimestamp(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(self::getLocalTimezone());
    }

    private static function buildOccurrenceStart(
        DateTimeImmutable $scanDate,
        DateTimeImmutable $baseStart,
        bool $allDay
    ): DateTimeImmutable {
        if ($allDay) {
            return $scanDate->setTime(0, 0, 0);
        }

        return $scanDate->setTime(
            (int) $baseStart->format('H'),
            (int) $baseStart->format('i'),
            (int) $baseStart->format('s')
        );
    }

    private static function matchesRecurrenceRule(
        DateTimeImmutable $occurrenceStart,
        DateTimeImmutable $baseStart,
        array $rule
    ): bool {
        $freq = strtoupper($rule['FREQ'] ?? '');
        $interval = max(1, (int) ($rule['INTERVAL'] ?? 1));
        $byDay = ! empty($rule['BYDAY']) ? array_map('trim', explode(',', strtoupper($rule['BYDAY']))) : [];
        $byMonthDay = ! empty($rule['BYMONTHDAY']) ? array_map('intval', explode(',', $rule['BYMONTHDAY'])) : [];
        $weekday = strtoupper(substr($occurrenceStart->format('D'), 0, 2));
        $baseWeekday = strtoupper(substr($baseStart->format('D'), 0, 2));

        switch ($freq) {
            case 'DAILY':
                $dayDiff = (int) $baseStart->diff($occurrenceStart)->format('%a');

                if ($dayDiff % $interval !== 0) {
                    return false;
                }

                return empty($byDay) || in_array($weekday, $byDay, true);

            case 'WEEKLY':
                $baseWeekStart = $baseStart->modify('monday this week')->setTime(0, 0, 0);
                $occurrenceWeekStart = $occurrenceStart->modify('monday this week')->setTime(0, 0, 0);
                $weekDiff = (int) floor(($occurrenceWeekStart->getTimestamp() - $baseWeekStart->getTimestamp()) / 604800);

                if ($weekDiff < 0 || $weekDiff % $interval !== 0) {
                    return false;
                }

                $allowedDays = $byDay ?: [$baseWeekday];

                return in_array($weekday, $allowedDays, true);

            case 'MONTHLY':
                $monthDiff = ((int) $occurrenceStart->format('Y') - (int) $baseStart->format('Y')) * 12;
                $monthDiff += (int) $occurrenceStart->format('n') - (int) $baseStart->format('n');

                if ($monthDiff < 0 || $monthDiff % $interval !== 0) {
                    return false;
                }

                if (! empty($byMonthDay)) {
                    return in_array((int) $occurrenceStart->format('j'), $byMonthDay, true);
                }

                return (int) $occurrenceStart->format('j') === (int) $baseStart->format('j');

            case 'YEARLY':
                $yearDiff = (int) $occurrenceStart->format('Y') - (int) $baseStart->format('Y');

                if ($yearDiff < 0 || $yearDiff % $interval !== 0) {
                    return false;
                }

                return $occurrenceStart->format('m-d') === $baseStart->format('m-d');
        }

        return false;
    }

    /**
     * Format event for display
     */
    public static function formatEventForDisplay(array $event): array
    {
        $displayEvent = [
            'title' => $event['title'],
            'location' => $event['location'],
            'description' => $event['description'],
            'start_timestamp' => $event['start_timestamp'] ?? null,
            'end_timestamp' => $event['end_timestamp'] ?? null,
            'all_day' => $event['all_day'] ?? false,
        ];

        if ($event['all_day']) {
            $displayEvent['date'] = self::formatDate($event['start']);
            $displayEvent['time'] = 'Ganztägig';
        } else {
            $displayEvent['date'] = self::formatDate($event['start']);
            $displayEvent['time'] = self::formatTime($event['start']);

            if ($event['end']) {
                $displayEvent['end_time'] = self::formatTime($event['end']);
            }
        }

        return $displayEvent;
    }

    /**
     * Format date for display
     */
    private static function formatDate(string $dateString): string
    {
        if (empty($dateString)) {
            return '';
        }

        $date = new DateTime($dateString, self::getLocalTimezone());

        return $date->format('Y-m-d');
    }

    /**
     * Format time for display
     */
    private static function formatTime(string $dateString): string
    {
        if (empty($dateString) || strlen($dateString) <= 10) {
            return '';
        }

        $date = new DateTime($dateString, self::getLocalTimezone());

        return $date->format('H:i'); // 24-hour format, e.g., "14:30"
    }
}
