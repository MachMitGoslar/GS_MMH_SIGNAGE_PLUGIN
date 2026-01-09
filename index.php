<?php

/**
 * GS MachMit!Haus Signage Plugin
 *
 * Digital signage system with screen management, content channels,
 * and whitelist-based access control.
 *
 * @version 1.0.0
 * @author stuffdev.de
 */

use Kirby\Cms\App as Kirby;
use Kirby\Http\Response as Response;

// Load plugin classes
require_once __DIR__ . '/classes/DurationCalculator.php';
require_once __DIR__ . '/classes/ICalParser.php';
require_once __DIR__ . '/classes/AccessController.php';

Kirby::plugin('gs/mmh-signage', [
    /**
     * Custom Panel Fields
     */
    'fields' => [
        'pendingRequests' => __DIR__ . '/fields/pendingRequests/index.php',
    ],

    /**
     * Custom Panel Sections
     */
    'sections' => [
        'pendingRequests' => __DIR__ . '/sections/pendingRequests/index.php',
    ],

    /**
     * Panel Area Registration
     * Adds "Signage" menu item to panel sidebar
     */
    'areas' => [
        'signage' => function ($kirby) {
            return [
                'label' => 'Signage',
                'icon' => 'monitor',
                'menu' => true,
                'link' => 'signage',
                'views' => [
                    [
                        'pattern' => 'signage',
                        'action' => function () {
                            // Redirect to screens overview
                            return [
                                'redirect' => 'signage/screens',
                            ];
                        },
                    ],
                    [
                        'pattern' => 'signage/screens',
                        'action' => function () use ($kirby) {
                            return [
                                'component' => 'k-page-view',
                                'title' => 'Signage Screens',
                                'props' => [
                                    'page' => 'signage/screens',
                                ],
                            ];
                        },
                    ],
                    [
                        'pattern' => 'signage/channels',
                        'action' => function () use ($kirby) {
                            return [
                                'component' => 'k-page-view',
                                'title' => 'Content Channels',
                                'props' => [
                                    'page' => 'signage/channels',
                                ],
                            ];
                        },
                    ],
                    [
                        'pattern' => 'pages/signage\+screens\+(:any)/approve-device',
                        'action' => function (string $screenDirname) use ($kirby) {
                            $uuid = $kirby->request()->get('uuid');
                            // Convert panel path format back to page id
                            $screenId = 'signage/screens/' . $screenDirname;
                            $screen = $kirby->page($screenId);

                            if ($screen && $uuid) {
                                // Get pending requests
                                $pending = $screen->pending_requests()->toStructure();
                                $pendingArray = [];
                                $requestIp = null;

                                foreach ($pending as $item) {
                                    if ($item->uuid()->value() === $uuid) {
                                        $requestIp = $item->ip()->value();
                                    } else {
                                        $pendingArray[] = $item->toArray();
                                    }
                                }

                                // Get whitelist
                                $whitelist = $screen->whitelist()->toStructure();
                                $whitelistArray = [];
                                foreach ($whitelist as $item) {
                                    $whitelistArray[] = $item->toArray();
                                }

                                // Add to whitelist
                                $whitelistArray[] = [
                                    'label' => 'Device ' . substr($uuid, 0, 8),
                                    'uuid' => $uuid,
                                    'ip' => $requestIp ?? 'Unknown',
                                    'approved_at' => date('Y-m-d H:i:s'),
                                    'approved_by' => $kirby->user() ? $kirby->user()->email() : 'System',
                                ];

                                // Update screen
                                $screen->update([
                                    'pending_requests' => \Kirby\Data\Yaml::encode($pendingArray),
                                    'whitelist' => \Kirby\Data\Yaml::encode($whitelistArray),
                                ]);
                            }

                            return [
                                'redirect' => "pages/signage+screens+{$screenDirname}",
                            ];
                        },
                    ],
                    [
                        'pattern' => 'pages/signage\+screens\+(:any)/deny-device',
                        'action' => function (string $screenDirname) use ($kirby) {
                            $uuid = $kirby->request()->get('uuid');
                            // Convert panel path format back to page id
                            $screenId = 'signage/screens/' . $screenDirname;
                            $screen = $kirby->page($screenId);

                            if ($screen && $uuid) {
                                // Remove from pending
                                $pending = $screen->pending_requests()->toStructure();
                                $pendingArray = [];

                                foreach ($pending as $item) {
                                    if ($item->uuid()->value() !== $uuid) {
                                        $pendingArray[] = $item->toArray();
                                    }
                                }

                                // Update screen
                                $screen->update([
                                    'pending_requests' => \Kirby\Data\Yaml::encode($pendingArray),
                                ]);
                            }

                            return [
                                'redirect' => "pages/signage+screens+{$screenDirname}",
                            ];
                        },
                    ],
                ],
            ];
        },
    ],

    /**
     * Page Blueprints
     */
    'blueprints' => [
        'pages/signage' => __DIR__ . '/blueprints/pages/signage.yml',
        'pages/screens' => __DIR__ . '/blueprints/pages/screens.yml',
        'pages/channels' => __DIR__ . '/blueprints/pages/channels.yml',
        'pages/screen' => __DIR__ . '/blueprints/pages/screen.yml',
        'pages/channel' => __DIR__ . '/blueprints/pages/channel.yml',
        'pages/slide' => __DIR__ . '/blueprints/pages/slide.yml',
        'blocks/signage-heading' => __DIR__ . '/blueprints/blocks/signage-heading.yml',
        'blocks/signage-text' => __DIR__ . '/blueprints/blocks/signage-text.yml',
    ],

    /**
     * Templates
     */
    'templates' => [
        'screen' => __DIR__ . '/templates/screen.php',
    ],

    /**
     * Snippets
     */
    'snippets' => [
        'signage/player' => __DIR__ . '/snippets/player.php',
        'signage/standby' => __DIR__ . '/snippets/standby.php',
        'signage/slide-blocks' => __DIR__ . '/snippets/slide-blocks.php',
        'signage/slide-video' => __DIR__ . '/snippets/slide-video.php',
        'signage/slide-calendar' => __DIR__ . '/snippets/slide-calendar.php',
        'blocks/signage-text' => __DIR__ . '/snippets/blocks/signage-text.php',
        'blocks/signage-heading' => __DIR__ . '/snippets/blocks/signage-heading.php',
    ],

    /**
     * API Routes
     */
    'api' => [
        'routes' => function ($kirby) {
            return [
                [
                    'pattern' => 'signage/check-access',
                    'method' => 'POST',
                    'auth' => false, // Allow unauthenticated access
                    'action' => function () use ($kirby) {
                        // Read JSON body
                        $data = $kirby->request()->body()->toArray();
                        $screenSlug = $data['screen'] ?? null;
                        $uuid = $data['uuid'] ?? null;
                        $ip = $kirby->visitor()->ip();

                        if (!$screenSlug || !$uuid) {
                            return [
                                'status' => 'error',
                                'access' => 'denied',
                                'message' => 'Missing screen or uuid parameter',
                            ];
                        }

                        return AccessController::checkAccess($screenSlug, $uuid, $ip);
                    },
                ],
                [
                    'pattern' => 'signage/content/(:any)',
                    'method' => 'GET',
                    'auth' => false, // Allow unauthenticated access
                    'action' => function (string $screenSlug) use ($kirby) {
                        $screen = $kirby->page('signage/screens/' . $screenSlug);

                        if (!$screen) {
                            return [
                                'code' => 404,
                                'status' => 'error',
                                'message' => 'Screen not found',
                            ];
                        }

                        return AccessController::getContentData($screen);
                    },
                ],
                [
                    'pattern' => 'signage/approve-request',
                    'method' => 'POST',
                    'auth' => true, // Requires panel login
                    'action' => function () use ($kirby) {
                        $screenSlug = $kirby->request()->get('screen');
                        $uuid = $kirby->request()->get('uuid');
                        $label = $kirby->request()->get('label', 'Unknown Device');

                        return AccessController::approveRequest($screenSlug, $uuid, $label);
                    },
                ],
            ];
        },
    ],

    /**
     * Page Methods
     * Custom methods available on page objects
     */
    'pageMethods' => [
        'calculatedDuration' => function () {
            if ($this->intendedTemplate()->name() === 'slide') {
                return DurationCalculator::calculate($this);
            }
            return 0;
        },
        'durationCalculationDetails' => function () {
            if ($this->intendedTemplate()->name() === 'slide') {
                return DurationCalculator::getCalculationDetails($this);
            }
            return '';
        },
        'isActiveNow' => function () {
            if ($this->intendedTemplate()->name() !== 'screen') {
                return false;
            }

            $now = new DateTime();
            $currentDay = strtolower($now->format('D')); // mon, tue, wed, etc.
            $activeTimes = $this->active_times()->toStructure();

            foreach ($activeTimes as $timeRange) {
                // Check if current day is in the allowed days list
                $daysValue = $timeRange->days()->value();
                if ($daysValue) {
                    $allowedDays = array_map('trim', explode(',', strtolower($daysValue)));
                    if (!in_array($currentDay, $allowedDays)) {
                        continue; // Skip this time range if today is not an allowed day
                    }
                }

                // Parse time values (handle both H:i and H:i:s formats)
                $startValue = $timeRange->start()->value();
                $endValue = $timeRange->end()->value();

                $start = DateTime::createFromFormat('H:i:s', $startValue);
                if (!$start) {
                    $start = DateTime::createFromFormat('H:i', $startValue);
                }

                $end = DateTime::createFromFormat('H:i:s', $endValue);
                if (!$end) {
                    $end = DateTime::createFromFormat('H:i', $endValue);
                }

                if (!$start || !$end) {
                    continue;
                }

                // Set the same date for comparison
                $start->setDate($now->format('Y'), $now->format('m'), $now->format('d'));
                $end->setDate($now->format('Y'), $now->format('m'), $now->format('d'));

                if ($now >= $start && $now <= $end) {
                    return true;
                }
            }

            return false;
        },
        'activeChannel' => function () {
            if ($this->intendedTemplate()->name() === 'screen') {
                // Check for time-based channel schedule
                $schedule = $this->channel_schedule()->toStructure();
                $now = new DateTime();

                foreach ($schedule as $entry) {
                    $start = DateTime::createFromFormat('H:i', $entry->time_start()->value());
                    $end = DateTime::createFromFormat('H:i', $entry->time_end()->value());

                    if ($now >= $start && $now <= $end) {
                        $channelSlug = $entry->channel()->value();
                        
                        return kirby()->page('signage/channels/' . $channelSlug);
                    }
                }

                // Fallback to assigned channel
                $channelId = $this->assigned_channel()->value();
                return kirby()->page($channelId);
            }
            return null;
        },
        'pendingRequestsHtml' => function () {
            if ($this->intendedTemplate()->name() === 'screen') {
                $pending = $this->pending_requests()->toStructure();

                if ($pending->count() === 0) {
                    return '✅ No pending access requests';
                }

                $html = '<strong>' . $pending->count() . ' device(s) awaiting approval</strong><br><br>';

                // Get the panel-friendly page path (with + separators)
                $panelPath = str_replace('/', '+', $this->id());

                foreach ($pending as $request) {
                    $uuid = $request->uuid()->value();
                    $ip = $request->ip()->value();
                    $requestedAt = $request->requested_at()->value();

                    $html .= '<hr style="margin: 1rem 0;">';
                    $html .= '<strong>UUID:</strong> <code style="background: #f0f0f0; padding: 2px 6px; border-radius: 3px;">' . htmlspecialchars($uuid) . '</code><br>';
                    $html .= '<strong>IP Address:</strong> ' . htmlspecialchars($ip) . '<br>';
                    $html .= '<strong>Requested:</strong> ' . htmlspecialchars($requestedAt) . '<br><br>';
                    $html .= '<a href="/panel/pages/' . $panelPath . '/approve-device?uuid=' . urlencode($uuid) . '" style="display: inline-block; padding: 0.5rem 1rem; background: #16a34a; color: white; text-decoration: none; border-radius: 4px; margin-right: 0.5rem;">✓ Approve</a>';
                    $html .= '<a href="/panel/pages/' . $panelPath . '/deny-device?uuid=' . urlencode($uuid) . '" style="display: inline-block; padding: 0.5rem 1rem; background: #dc2626; color: white; text-decoration: none; border-radius: 4px;">✗ Deny</a>';
                    $html .= '<br>';
                }

                return $html;
            }
            return '';
        },
    ],

    /**
     * Hooks
     * Validate channel schedule to prevent overlaps
     */
    'hooks' => [
        'page.update:before' => function ($page, $values, $strings) {
            if ($page->intendedTemplate()->name() === 'screen') {
                // Validate channel schedule for overlaps
                if (isset($values['channel_schedule'])) {
                    $schedule = $values['channel_schedule'];

                    if (is_string($schedule)) {
                        $schedule = \Kirby\Data\Yaml::decode($schedule);
                    }

                    if (is_array($schedule) && count($schedule) > 1) {
                        // Check for overlaps
                        for ($i = 0; $i < count($schedule); $i++) {
                            for ($j = $i + 1; $j < count($schedule); $j++) {
                                $start1 = DateTime::createFromFormat('H:i', $schedule[$i]['time_start'] ?? '00:00');
                                $end1 = DateTime::createFromFormat('H:i', $schedule[$i]['time_end'] ?? '00:00');
                                $start2 = DateTime::createFromFormat('H:i', $schedule[$j]['time_start'] ?? '00:00');
                                $end2 = DateTime::createFromFormat('H:i', $schedule[$j]['time_end'] ?? '00:00');

                                // Check for overlap
                                if ($start1 < $end2 && $end1 > $start2) {
                                    throw new Exception('Channel schedule times cannot overlap. Please adjust the time ranges.');
                                }
                            }
                        }
                    }
                }
            }
        },
    ],

    /**
     * Routes
     * Frontend routes for screen display and plugin assets
     */
    'routes' => [
        [
            'pattern' => 'signage/assets/css/(:any)',
            'action' => function ($file) {
                $path = __DIR__ . '/assets/css/' . $file;
                if (file_exists($path)) {
                    return new Response(file_get_contents($path), 'text/css');
                }
                return false;
            },
        ],
        [
            'pattern' => 'signage/assets/js/(:any)',
            'action' => function ($file) {
                $path = __DIR__ . '/assets/js/' . $file;
                if (file_exists($path)) {
                    return [
                        'body' => file_get_contents($path),
                        'type' => 'text/javascript',
                    ];
                }
                return false;
            },
        ],
        [
            'pattern' => 'signage/(:any)',
            'action' => function ($screenSlug) {
                $screen = page('signage/screens/' . $screenSlug);
                if (!$screen) {
                    return page('error');
                }

                // Return the page itself, which will use the screen template
                return $screen;
            },
        ],
    ],
]);
