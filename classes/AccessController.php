<?php

/**
 * Access Controller
 *
 * Manages whitelist-based access control for signage screens.
 * Handles device approval workflow and content delivery.
 *
 * @package GS\MMH\Signage
 */
class AccessController
{
    private const DEFAULT_LABEL_PREFIX = 'Device ';

    /**
     * Check if a device has access to a screen
     *
     * @param string $screenSlug Screen identifier
     * @param string $uuid Device UUID
     * @param string $ip Client IP address
     * @return array Access response
     */
    public static function checkAccess(string $screenSlug, string $uuid, string $ip): array
    {
        $screen = kirby()->page('signage/screens/' . $screenSlug);
        if (! $screen) {
            return [
                'status' => 'error',
                'message' => 'Screen not found',
                'access' => 'denied',
            ];
        }

        // Check if screen is active
        if ($screen->status() != 'listed') {
            return [
                'status' => 'error',
                'message' => 'Screen is inactive',
                'access' => 'denied',
            ];
        }

        // If whitelist is disabled, grant access immediately
        if (! $screen->whitelist_enabled()->toBool()) {
            return [
                'status' => 'success',
                'access' => 'granted',
                'message' => 'Public access enabled',
            ];
        }

        // Check whitelist for this UUID
        $whitelist = $screen->whitelist()->toStructure();
        foreach ($whitelist as $entry) {
            if ($entry->uuid()->value() === $uuid) {
                return [
                    'status' => 'success',
                    'access' => 'granted',
                    'message' => 'Device approved',
                ];
            }
        }

        if (self::isDenied($screen, $uuid)) {
            return [
                'status' => 'denied',
                'access' => 'denied',
                'message' => 'Access denied. Contact your administrator.',
            ];
        }

        // Not in whitelist - record pending request
        self::recordPendingRequest($screen, $uuid, $ip);

        return [
            'status' => 'pending',
            'access' => 'pending',
            'message' => 'Access request recorded. Waiting for approval.',
        ];
    }

    /**
     * Record a pending access request
     *
     * @param \Kirby\Cms\Page $screen
     * @param string $uuid
     * @param string $ip
     * @return void
     */
    private static function recordPendingRequest($screen, string $uuid, string $ip): void
    {
        $pending = $screen->pending_requests()->toStructure();
        $pendingArray = self::pendingRequestsToArray($pending);

        // Check if UUID already has pending request
        $alreadyPending = false;
        foreach ($pendingArray as $request) {
            if ($request['uuid'] === $uuid) {
                $alreadyPending = true;

                break;
            }
        }

        // Add new request if not already pending
        if (! $alreadyPending) {
            $pendingArray[] = [
                'uuid' => $uuid,
                'ip' => $ip,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'requested_at' => date('Y-m-d H:i:s'),
            ];

            try {
                // Update using changeStatus to avoid hooks
                $screen->update([
                    'pending_requests' => \Kirby\Data\Yaml::encode($pendingArray),
                ], 'en', true);

                error_log('Signage: Recorded pending request for UUID: ' . $uuid . ' on screen: ' . $screen->slug());
            } catch (Exception $e) {
                // Log error but don't block display
                error_log('Signage: Failed to record pending request - ' . $e->getMessage());
                error_log('Signage: File: ' . $screen->root() . '/screen.txt');
            }
        } else {
            error_log('Signage: Request already pending for UUID: ' . $uuid);
        }
    }

    /**
     * Approve a pending request and add to whitelist
     *
     * @param string $screenSlug
     * @param string $uuid
     * @param string $label Device label
     * @return array Response
     */
    public static function approveRequest(string $screenSlug, string $uuid, string $label = 'Unknown Device'): array
    {
        $screen = kirby()->page('signage/screens/' . $screenSlug);

        if (! $screen) {
            return [
                'status' => 'error',
                'message' => 'Screen not found',
            ];
        }

        return self::approveRequestForScreenModel($screen, $uuid, $label);
    }

    public static function denyRequest(string $screenSlug, string $uuid): array
    {
        $screen = kirby()->page('signage/screens/' . $screenSlug);

        if (! $screen) {
            return [
                'status' => 'error',
                'message' => 'Screen not found',
            ];
        }

        return self::denyRequestForScreenModel($screen, $uuid);
    }

    public static function approveRequestForScreen($screen, string $uuid, string $label = 'Unknown Device'): array
    {
        return self::approveRequestForScreenModel($screen, $uuid, $label);
    }

    public static function denyRequestForScreen($screen, string $uuid): array
    {
        return self::denyRequestForScreenModel($screen, $uuid);
    }

    public static function getPendingRequestsForScreen($screen): array
    {
        $pending = $screen->pending_requests()->toStructure();

        return self::pendingRequestsToArray($pending);
    }

    public static function getDeniedRequestsForScreen($screen): array
    {
        $denied = $screen->denied_requests()->toStructure();

        return self::deniedRequestsToArray($denied);
    }

    private static function approveRequestForScreenModel($screen, string $uuid, ?string $label = null): array
    {
        $whitelist = $screen->whitelist()->toStructure();
        $whitelistArray = self::whitelistToArray($whitelist);

        $pending = $screen->pending_requests()->toStructure();
        $pendingArray = [];
        $approvedRequest = null;

        foreach ($pending as $request) {
            $requestData = [
                'uuid' => $request->uuid()->value(),
                'ip' => $request->ip()->value(),
                'user_agent' => $request->user_agent()->value(),
                'requested_at' => $request->requested_at()->value(),
            ];

            if ($requestData['uuid'] === $uuid) {
                $approvedRequest = $requestData;
            } else {
                $pendingArray[] = $requestData;
            }
        }

        $denied = $screen->denied_requests()->toStructure();
        $deniedArray = [];
        if (! $approvedRequest) {
            foreach ($denied as $entry) {
                $entryData = [
                    'uuid' => $entry->uuid()->value(),
                    'ip' => $entry->ip()->value(),
                    'user_agent' => $entry->user_agent()->value(),
                    'denied_at' => $entry->denied_at()->value(),
                ];

                if ($entryData['uuid'] === $uuid) {
                    $approvedRequest = [
                        'uuid' => $entryData['uuid'],
                        'ip' => $entryData['ip'],
                        'user_agent' => $entryData['user_agent'],
                        'requested_at' => $entryData['denied_at'],
                    ];
                } else {
                    $deniedArray[] = $entryData;
                }
            }
        } else {
            $deniedArray = self::deniedRequestsToArray($denied);
        }

        if (! $approvedRequest) {
            return [
                'status' => 'error',
                'message' => 'Request not found in pending or denied list',
            ];
        }

        if (! $label || trim($label) === '') {
            $label = self::DEFAULT_LABEL_PREFIX . substr($uuid, 0, 8);
        }

        // Add to whitelist
        $whitelistArray[] = [
            'uuid' => $uuid,
            'ip' => $approvedRequest['ip'],
            'label' => $label,
            'approved_at' => date('Y-m-d H:i:s'),
            'approved_by' => kirby()->user() ? kirby()->user()->email() : 'system',
        ];

        try {
            $screen->update([
                'whitelist' => \Kirby\Data\Yaml::encode($whitelistArray),
                'pending_requests' => \Kirby\Data\Yaml::encode($pendingArray),
                'denied_requests' => \Kirby\Data\Yaml::encode($deniedArray),
            ]);

            return [
                'status' => 'success',
                'message' => 'Device approved and added to whitelist',
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Failed to update screen: ' . $e->getMessage(),
            ];
        }
    }

    private static function denyRequestForScreenModel($screen, string $uuid): array
    {
        $pending = $screen->pending_requests()->toStructure();
        $pendingArray = [];
        $deniedRequest = null;

        foreach ($pending as $request) {
            $requestData = [
                'uuid' => $request->uuid()->value(),
                'ip' => $request->ip()->value(),
                'user_agent' => $request->user_agent()->value(),
                'requested_at' => $request->requested_at()->value(),
            ];

            if ($requestData['uuid'] !== $uuid) {
                $pendingArray[] = $requestData;
                continue;
            }

            $deniedRequest = $requestData;
        }

        $deniedArray = self::deniedRequestsToArray($screen->denied_requests()->toStructure());
        $deniedArray = array_values(array_filter($deniedArray, function ($entry) use ($uuid) {
            return ($entry['uuid'] ?? '') !== $uuid;
        }));
        $deniedArray[] = [
            'uuid' => $uuid,
            'ip' => $deniedRequest['ip'] ?? 'Unknown',
            'user_agent' => $deniedRequest['user_agent'] ?? 'Unknown',
            'denied_at' => date('Y-m-d H:i:s'),
        ];

        try {
            $screen->update([
                'pending_requests' => \Kirby\Data\Yaml::encode($pendingArray),
                'denied_requests' => \Kirby\Data\Yaml::encode($deniedArray),
            ]);

            return [
                'status' => 'success',
                'message' => 'Request denied',
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Failed to update screen: ' . $e->getMessage(),
            ];
        }
    }

    private static function pendingRequestsToArray($pending): array
    {
        $pendingArray = [];

        foreach ($pending as $request) {
            $pendingArray[] = [
                'uuid' => $request->uuid()->value(),
                'ip' => $request->ip()->value(),
                'user_agent' => $request->user_agent()->value(),
                'requested_at' => $request->requested_at()->value(),
            ];
        }

        return $pendingArray;
    }

    private static function deniedRequestsToArray($denied): array
    {
        $deniedArray = [];

        foreach ($denied as $entry) {
            $deniedArray[] = [
                'uuid' => $entry->uuid()->value(),
                'ip' => $entry->ip()->value(),
                'user_agent' => $entry->user_agent()->value(),
                'denied_at' => $entry->denied_at()->value(),
            ];
        }

        return $deniedArray;
    }

    private static function isDenied($screen, string $uuid): bool
    {
        $deniedArray = self::deniedRequestsToArray($screen->denied_requests()->toStructure());

        foreach ($deniedArray as $entry) {
            if (($entry['uuid'] ?? '') === $uuid) {
                return true;
            }
        }

        return false;
    }

    private static function whitelistToArray($whitelist): array
    {
        $whitelistArray = [];

        foreach ($whitelist as $entry) {
            $whitelistArray[] = [
                'uuid' => $entry->uuid()->value(),
                'ip' => $entry->ip()->value(),
                'label' => $entry->label()->value(),
                'approved_at' => $entry->approved_at()->value(),
                'approved_by' => $entry->approved_by()->value(),
            ];
        }

        return $whitelistArray;
    }

    /**
     * Get content data for an approved device
     *
     * @param \Kirby\Cms\Page $screen
     * @return array Content data
     */
    public static function getContentData($screen): array
    {
        //var_dump('Getting content data for screen: ' . $screen->isActiveNow());
        // Check if screen is active
        if ($screen->status() != 'listed') {
            return [
                'status' => 'inactive',
                'message' => 'Screen is currently inactive',
            ];
        }

        // Check if within active hours
        if (! $screen->isActiveNow()) {
            return [
                'status' => 'standby',
                'standby_mode' => $screen->standby_mode()->value(),
                'standby_image' => $screen->standby_image()->toFile() ? $screen->standby_image()->toFile()->url() : null,
                'standby_message' => $screen->standby_message()->value(),
            ];
        }

        // Get active channel
        $channel = $screen->activeChannel();
        if (! $channel) {
            return [
                'status' => 'error',
                'message' => 'No channel assigned',
            ];
        }

        // Get slides from channel
        $slides = $channel->children()->unlisted();
        $slidesData = [];

        foreach ($slides as $slide) {
            $slidesData[] = self::getSlideData($slide);
        }

        return [
            'status' => 'active',
            'screen' => [
                'title' => $screen->title()->value(),
                'orientation' => $screen->orientation()->value(),
            ],
            'channel' => [
                'title' => $channel->title()->value(),
                'slides_count' => count($slidesData),
                'background' => [
                    'background_color' => $channel->background_color()->value() ?? '#000000',
                ],
            ],
            'slides' => $slidesData,
        ];
    }

    /**
     * Extract slide data for frontend
     *
     * @param \Kirby\Cms\Page $slide
     * @return array Slide data
     */
    private static function getSlideData($slide): array
    {
        $slideType = $slide->slide_type()->value();

        $baseData = [
            'id' => $slide->id(),
            'type' => $slideType,
            'duration' => $slide->calculatedDuration(),
            'transition' => $slide->transition()->value() ?? 'fade',
            'transition_duration' => (float) ($slide->transition_duration()->value() ?? 1),
            'background' => self::getBackgroundData($slide),
            'content_position' => $slide->content_position()->value() ?? 'center',
        ];

        // Add type-specific data
        switch ($slideType) {
            case 'blocks':
                $baseData['layout'] = self::getLayoutData($slide);

                break;

            case 'video':
                $baseData['video'] = self::getVideoData($slide);

                break;

            case 'calendar':
                $baseData['calendar'] = self::getCalendarData($slide);

                break;
        }

        return $baseData;
    }

    /**
     * Extract background data
     */
    private static function getBackgroundData($slide): array
    {
        $bgType = $slide->bg_type()->value() ?? 'none';

        $backgroundData = [
            'type' => $bgType,
        ];

        if ($bgType === 'image') {
            $bgImage = $slide->bg_image()->toFile();
            if ($bgImage) {
                $backgroundData['image'] = [
                    'url' => $bgImage->url(),
                    'position' => $slide->bg_position()->value() ?? 'center',
                    'size' => $slide->bg_size()->value() ?? 'cover',
                ];
            }
        } elseif ($bgType === 'video') {
            $bgVideo = $slide->bg_video()->toFile();
            if ($bgVideo) {
                $backgroundData['video'] = [
                    'url' => $bgVideo->url(),
                    'type' => $bgVideo->mime(),
                ];
            }
        } elseif ($bgType === 'color') {
            $backgroundData['color'] = $slide->overlay_color()->value() ?? '#000000';
        }

        // Overlay settings
        if ($slide->overlay_enabled()->toBool()) {
            $backgroundData['overlay'] = [
                'enabled' => true,
                'color' => $slide->overlay_color()->value() ?? '#000000',
                'opacity' => (int) ($slide->overlay_opacity()->value() ?? 40),
                'gradient' => $slide->overlay_gradient()->value() ?? 'none',
            ];
        } else {
            $backgroundData['overlay'] = ['enabled' => false];
        }

        return $backgroundData;
    }

    /**
     * Extract layout data (layout field with columns and blocks)
     */
    private static function getLayoutData($slide): array
    {
        $layout = $slide->content_layout()->toLayouts();
        $layoutData = [];

        foreach ($layout as $layoutRow) {
            $rowData = [
                'columns' => [],
                'settings' => [
                    'vertical_align' => $layoutRow->attrs()->vertical_align ?? 'center',
                    'padding' => $layoutRow->attrs()->padding ?? 'medium',
                ],
            ];

            foreach ($layoutRow->columns() as $column) {
                $columnData = [
                    'width' => $column->width(),
                    'blocks' => [],
                ];

                foreach ($column->blocks() as $block) {
                    $blockData = [
                        'type' => $block->type(),
                        'id' => $block->id(),
                    ];

                    switch ($block->type()) {
                        case 'signage-heading':
                            $blockData['text'] = $block->text()->toWriterHTML();
                            $blockData['level'] = $block->level()->value() ?? 'h2';

                            break;

                        case 'signage-text':
                            $blockData['text'] = $block->text()->toWriterHTML();

                            break;

                        case 'image':
                            $image = $block->image()->toFile();
                            if ($image) {
                                $blockData['src'] = $image->url();
                                $blockData['alt'] = $block->alt()->value();
                                $blockData['caption'] = $block->caption()->value();
                            }

                            break;

                        case 'list':
                            $blockData['text'] = $block->text()->kirbytext();

                            break;

                        case 'quote':
                            $blockData['text'] = $block->text()->kirbytext();
                            $blockData['citation'] = $block->citation()->value();

                            break;

                        case 'line':
                            // Line separator - no additional data needed
                            break;
                    }

                    $columnData['blocks'][] = $blockData;
                }

                $rowData['columns'][] = $columnData;
            }

            $layoutData[] = $rowData;
        }

        return $layoutData;
    }

    /**
     * Extract video data
     */
    private static function getVideoData($slide): array
    {
        $videoSource = $slide->video_source()->value();

        $videoData = [
            'source' => $videoSource,
            'autoplay' => $slide->video_autoplay()->toBool(),
            'muted' => $slide->video_muted()->toBool(),
            'loop' => $slide->video_loop()->toBool(),
        ];

        if ($videoSource === 'upload') {
            $videoFile = $slide->video_file()->toFile();
            if ($videoFile) {
                $videoData['url'] = $videoFile->url();
                $videoData['type'] = $videoFile->mime();
            }
        } else {
            $videoData['embed_url'] = $slide->video_embed()->value();
        }

        return $videoData;
    }

    /**
     * Extract calendar data
     */
    private static function getCalendarData($slide): array
    {
        $sourceType = $slide->calendar_source_type()->value();
        $range = $slide->calendar_range()->value() ?? 'week';
        $maxEvents = (int) ($slide->calendar_max_events()->value() ?? 5);

        $calendarData = [
            'source_type' => $sourceType,
            'layout' => $slide->calendar_layout()->value() ?? 'list',
            'range' => $range,
            'max_events' => $maxEvents,
            'events' => [],
        ];

        if ($sourceType === 'external') {
            // Fetch and parse iCal feed
            $icalUrl = $slide->calendar_source()->value();

            if ($icalUrl) {
                $icalResult = ICalParser::fetchEvents($icalUrl, $range, $maxEvents);

                if (! $icalResult['error']) {
                    // Format events for display
                    $calendarData['events'] = array_map(function ($event) {
                        return ICalParser::formatEventForDisplay($event);
                    }, $icalResult['events']);
                    $calendarData['fetched_at'] = $icalResult['fetched_at'];
                } else {
                    $calendarData['error'] = $icalResult['message'];
                }
            }
        } else {
            // Kirby pages - extract event data
            $pages = $slide->calendar_pages()->toPages();

            if ($pages) {
                foreach ($pages as $page) {
                    $calendarData['events'][] = [
                        'title' => $page->title()->value(),
                        'date' => $page->date()->value() ?? null,
                        'time' => $page->time()->value() ?? null,
                        'location' => $page->location()->value() ?? null,
                        'description' => $page->description()->excerpt(100)->value() ?? null,
                        'url' => $page->url(),
                    ];
                }
            }
        }

        return $calendarData;
    }
}
