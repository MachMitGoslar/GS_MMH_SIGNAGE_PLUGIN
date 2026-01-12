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
            $date = DateTime::createFromFormat('Ymd', $value);
            if ($date) {
                $timestamp = $date->getTimestamp();
                $formatted = $date->format('Y-m-d');
            }
        } elseif (strlen($value) >= 15) {
            // Date and time: YYYYMMDDTHHMMSS
            $value = str_replace('T', '', $value);
            $date = DateTime::createFromFormat('YmdHis', substr($value, 0, 14));

            if ($date) {
                // Handle timezone
                if ($isUtc) {
                    $date->setTimezone(new DateTimeZone('UTC'));
                } elseif ($timezone) {
                    try {
                        $date->setTimezone(new DateTimeZone($timezone));
                    } catch (Exception $e) {
                        // Use default timezone if invalid
                    }
                }

                // Convert to local timezone for display
                $date->setTimezone(new DateTimeZone(date_default_timezone_get()));

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
        $now = new DateTime();
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

        return array_filter($events, function ($event) use ($startTimestamp, $endTimestamp) {
            $eventStart = $event['start_timestamp'];

            // Include events that start within the range or are ongoing
            return $eventStart >= $startTimestamp && $eventStart <= $endTimestamp;
        });
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
        ];

        if ($event['all_day']) {
            $displayEvent['date'] = self::formatDate($event['start']);
            $displayEvent['time'] = 'All Day';
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

        $date = new DateTime($dateString);
        $today = new DateTime('today');
        $tomorrow = new DateTime('tomorrow');

        if ($date->format('Y-m-d') === $today->format('Y-m-d')) {
            return 'Today';
        } elseif ($date->format('Y-m-d') === $tomorrow->format('Y-m-d')) {
            return 'Tomorrow';
        } else {
            return $date->format('D, M j'); // e.g., "Mon, Jan 15"
        }
    }

    /**
     * Format time for display
     */
    private static function formatTime(string $dateString): string
    {
        if (empty($dateString) || strlen($dateString) <= 10) {
            return '';
        }

        $date = new DateTime($dateString);

        return $date->format('H:i'); // 24-hour format, e.g., "14:30"
    }
}
