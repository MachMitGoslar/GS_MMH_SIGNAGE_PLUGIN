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
use Kirby\Toolkit\Tpl;

// Load plugin classes
require_once __DIR__ . '/classes/DurationCalculator.php';
require_once __DIR__ . '/classes/ICalParser.php';
require_once __DIR__ . '/classes/AccessController.php';

Kirby::plugin('gs/mmh-signage', [
    'panel' => [
        'js' => [
            'index.js',
        ],
        'css' => [
            'index.css',
        ],
    ],
    /**
     * Custom Panel Fields
     */
    'fields' => [
        'pending_requests' => require __DIR__ . '/fields/pending_requests/index.php',
        'onboarding_requests' => require __DIR__ . '/fields/onboarding_requests/index.php',
    ],

    /**
     * Panel Area Registration
     * Adds "Signage" menu item to panel sidebar
     */
    'areas' => [
        'signage' => function ($kirby) {
            return [
                'label' => 'Signage',
                'icon' => 'display',
                'menu' => true,
                'link' => 'signage',
                'views' => [
                    [
                        'pattern' => 'signage',
                        'action' => function () {
                            return page('signage')->panel()->view();
                        },
                    ],
                    [
                        'pattern' => 'signage/screens',
                        'action' => function () use ($kirby) {
                            return page('signage/screens')->panel()->view();
                        },
                    ],
                    [
                        'pattern' => 'signage/channels',
                        'action' => function () use ($kirby) {
                            return page('signage/channels')->panel()->view();
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
        'signage-onboarding' => __DIR__ . '/templates/onboarding.php',
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
                    'pattern' => 'signage/onboarding/request',
                    'method' => 'POST',
                    'auth' => false,
                    'action' => function () use ($kirby) {
                        $data = $kirby->request()->body()->toArray();
                        $uuid = $data['uuid'] ?? null;
                        $backend = $data['backend'] ?? null;
                        $url = $data['url'] ?? null;
                        $ip = $kirby->visitor()->ip();

                        if (! $uuid) {
                            return [
                                'status' => 'error',
                                'access' => 'denied',
                                'message' => 'Missing uuid parameter',
                            ];
                        }

                        return AccessController::registerOnboardingRequest($uuid, $ip, $backend, $url);
                    },
                ],
                [
                    'pattern' => 'signage/onboarding-status/(:any)',
                    'method' => 'GET',
                    'auth' => false,
                    'action' => function (string $uuid) {
                        return AccessController::getOnboardingStatus($uuid);
                    },
                ],
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

                        if (! $screenSlug || ! $uuid) {
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

                        if (! $screen) {
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
                    'pattern' => 'signage/approve-onboarding-request',
                    'method' => 'POST',
                    'auth' => true,
                    'action' => function () use ($kirby) {
                        $uuid = $kirby->request()->get('uuid');
                        $screenSlug = $kirby->request()->get('screen');
                        $label = $kirby->request()->get('label', 'Unknown Device');

                        return AccessController::approveOnboardingRequest($uuid, $screenSlug, $label);
                    },
                ],
                [
                    'pattern' => 'signage/deny-onboarding-request',
                    'method' => 'POST',
                    'auth' => true,
                    'action' => function () use ($kirby) {
                        $uuid = $kirby->request()->get('uuid');

                        return AccessController::denyOnboardingRequest($uuid);
                    },
                ],
                [
                    'pattern' => 'signage/remove-denied-onboarding-request',
                    'method' => 'POST',
                    'auth' => true,
                    'action' => function () use ($kirby) {
                        $uuid = $kirby->request()->get('uuid');

                        return AccessController::removeDeniedOnboardingRequest($uuid);
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
                [
                    'pattern' => 'signage/reassign-approved-device',
                    'method' => 'POST',
                    'auth' => true,
                    'action' => function () use ($kirby) {
                        $fromScreenSlug = $kirby->request()->get('fromScreen');
                        $toScreenSlug = $kirby->request()->get('toScreen');
                        $uuid = $kirby->request()->get('uuid');

                        return AccessController::reassignApprovedDevice($fromScreenSlug, $toScreenSlug, $uuid);
                    },
                ],
                [
                    'pattern' => 'signage/revoke-approved-device',
                    'method' => 'POST',
                    'auth' => true,
                    'action' => function () use ($kirby) {
                        $screenSlug = $kirby->request()->get('screen');
                        $uuid = $kirby->request()->get('uuid');

                        return AccessController::revokeApprovedDevice($screenSlug, $uuid);
                    },
                ],
                [
                    'pattern' => 'signage/rename-approved-device',
                    'method' => 'POST',
                    'auth' => true,
                    'action' => function () use ($kirby) {
                        $screenSlug = $kirby->request()->get('screen');
                        $uuid = $kirby->request()->get('uuid');
                        $label = $kirby->request()->get('label', '');

                        return AccessController::renameApprovedDevice($screenSlug, $uuid, $label);
                    },
                ],
                [
                    'pattern' => 'signage/deny-request',
                    'method' => 'POST',
                    'auth' => true, // Requires panel login
                    'action' => function () use ($kirby) {
                        $screenSlug = $kirby->request()->get('screen');
                        $uuid = $kirby->request()->get('uuid');

                        return AccessController::denyRequest($screenSlug, $uuid);
                    },
                ],
                [
                    'pattern' => 'signage/remove-denied',
                    'method' => 'POST',
                    'auth' => true, // Requires panel login
                    'action' => function () use ($kirby) {
                        $screenSlug = $kirby->request()->get('screen');
                        $uuid = $kirby->request()->get('uuid');

                        if (! $screenSlug || ! $uuid) {
                            return [
                                'status' => 'error',
                                'message' => 'Missing screen or uuid parameter',
                            ];
                        }

                        $screen = $kirby->page('signage/screens/' . $screenSlug);
                        if (! $screen) {
                            return [
                                'status' => 'error',
                                'message' => 'Screen not found',
                            ];
                        }

                        $deniedArray = [];
                        foreach ($screen->denied_requests()->toStructure() as $entry) {
                            if ($entry->uuid()->value() !== $uuid) {
                                $deniedArray[] = [
                                    'uuid' => $entry->uuid()->value(),
                                    'ip' => $entry->ip()->value(),
                                    'user_agent' => $entry->user_agent()->value(),
                                    'denied_at' => $entry->denied_at()->value(),
                                ];
                            }
                        }

                        $screen->update([
                            'denied_requests' => \Kirby\Data\Yaml::encode($deniedArray),
                        ]);

                        return [
                            'status' => 'success',
                            'message' => 'Denied entry removed',
                        ];
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
                    if (! in_array($currentDay, $allowedDays)) {
                        continue; // Skip this time range if today is not an allowed day
                    }
                }

                // Parse time values (handle both H:i and H:i:s formats)
                $startValue = $timeRange->start()->value();
                $endValue = $timeRange->end()->value();

                $start = DateTime::createFromFormat('H:i:s', $startValue);
                if (! $start) {
                    $start = DateTime::createFromFormat('H:i', $startValue);
                }

                $end = DateTime::createFromFormat('H:i:s', $endValue);
                if (! $end) {
                    $end = DateTime::createFromFormat('H:i', $endValue);
                }

                if (! $start || ! $end) {
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
        'page.delete:before' => function ($page) {
            if ($page->intendedTemplate()->name() === 'screen') {
                try {
                    AccessController::moveScreenDevicesToPendingForScreen($page);
                } catch (Throwable $e) {
                    error_log('Signage: Failed to move devices before screen deletion - ' . $e->getMessage());
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
            'pattern' => 'signage',
            'action' => function () {
                $page = page('signage');
                if (! $page) {
                    return page('error');
                }

                return new Response(
                    Tpl::load(__DIR__ . '/templates/onboarding.php', ['page' => $page]),
                    'text/html'
                );
            },
        ],
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
                if (! $screen) {
                    return page('error');
                }

                // Return the page itself, which will use the screen template
                return $screen;
            },
        ],
    ],

    /**
     * Translations
     */
    'translations' => [
        'en' => [
            // Signage Area
            'signage.area.label' => 'Signage',
            'signage.screens.title' => 'Signage Screens',
            'signage.channels.title' => 'Content Channels',

            // Screen Blueprint
            'signage.screen.title' => 'Screen Configuration',
            'signage.screen.tab.settings' => 'Settings',
            'signage.screen.tab.content' => 'Content',
            'signage.screen.tab.access' => 'Access Control',
            'signage.screen.tab.schedule' => 'Schedule',
            'signage.screen.field.screen_id' => 'Screen ID',
            'signage.screen.field.screen_id.help' => 'Unique identifier for this screen (used in URLs)',
            'signage.screen.field.orientation' => 'Orientation',
            'signage.screen.field.orientation.horizontal' => 'Horizontal (Landscape)',
            'signage.screen.field.orientation.vertical' => 'Vertical (Portrait)',
            'signage.screen.field.assigned_channel' => 'Primary Channel',
            'signage.screen.field.assigned_channel.help' => 'Default channel when no schedule matches',
            'signage.screen.field.active_times' => 'Active Hours',
            'signage.screen.field.active_times.help' => 'Screen displays content during these hours',
            'signage.screen.field.whitelist_enabled' => 'Enable Access Control',
            'signage.screen.field.whitelist_enabled.help' => 'Require device approval before displaying content',
            'signage.screen.field.whitelist' => 'Approved Devices',
            'signage.screen.field.pending_requests' => 'Pending Access Requests',
            'signage.screen.field.standby_mode' => 'Standby Mode',
            'signage.screen.field.standby_image' => 'Standby Image',
            'signage.screen.field.standby_message' => 'Standby Message',
            'signage.screen.field.channel_schedule' => 'Time-Based Channel Schedule',

            // Channel Blueprint
            'signage.channel.title' => 'Content Channel',
            'signage.channel.field.channel_id' => 'Channel ID',
            'signage.channel.field.description' => 'Description',
            'signage.channel.field.background_color' => 'Background Color',
            'signage.channel.field.default_bg_image' => 'Default Background Image',
            'signage.channel.field.default_bg_video' => 'Default Background Video',
            'signage.channel.field.default_overlay_color' => 'Default Overlay Color',
            'signage.channel.field.default_overlay_opacity' => 'Default Overlay Opacity',

            // Slide Blueprint
            'signage.slide.title' => 'Content Slide',
            'signage.slide.tab.content' => 'Content',
            'signage.slide.tab.background' => 'Background',
            'signage.slide.tab.preview' => 'Preview',
            'signage.slide.field.slide_type' => 'Slide Type',
            'signage.slide.field.slide_type.blocks' => 'Content Blocks',
            'signage.slide.field.slide_type.video' => 'Full-Screen Video',
            'signage.slide.field.slide_type.calendar' => 'Calendar / Events',
            'signage.slide.field.content_layout' => 'Content Layout',
            'signage.slide.field.duration_mode' => 'Duration',
            'signage.slide.field.duration_mode.auto' => 'Auto-Calculate',
            'signage.slide.field.duration_mode.manual' => 'Manual Override',
            'signage.slide.field.duration_override' => 'Duration (seconds)',
            'signage.slide.field.transition' => 'Transition Effect',
            'signage.slide.field.transition.fade' => 'Fade',
            'signage.slide.field.transition.slide' => 'Slide',
            'signage.slide.field.transition.zoom' => 'Zoom',
            'signage.slide.field.transition.none' => 'None (Instant)',
            'signage.slide.field.bg_type' => 'Background Type',
            'signage.slide.field.bg_type.none' => 'Use Channel Default',
            'signage.slide.field.bg_type.image' => 'Custom Image',
            'signage.slide.field.bg_type.video' => 'Custom Video (Looping)',
            'signage.slide.field.bg_type.color' => 'Solid Color Only',
            'signage.slide.field.overlay_enabled' => 'Enable Overlay',
            'signage.slide.field.overlay_color' => 'Overlay Color',
            'signage.slide.field.overlay_opacity' => 'Opacity',

            // Calendar
            'signage.slide.field.calendar_source_type' => 'Calendar Source',
            'signage.slide.field.calendar_source_type.external' => 'External iCal URL',
            'signage.slide.field.calendar_source_type.pages' => 'Kirby Pages',
            'signage.slide.field.calendar_source' => 'iCal URL',
            'signage.slide.field.calendar_layout' => 'Display Layout',
            'signage.slide.field.calendar_layout.list' => 'Event List',
            'signage.slide.field.calendar_layout.agenda' => 'Agenda View',
            'signage.slide.field.calendar_layout.grid' => 'Grid View',
            'signage.slide.field.calendar_range' => 'Date Range',
            'signage.slide.field.calendar_range.today' => 'Today Only',
            'signage.slide.field.calendar_range.week' => 'This Week',
            'signage.slide.field.calendar_range.month' => 'This Month',
            'signage.slide.field.calendar_max_events' => 'Max Events',

            // Blocks
            'signage.block.signage-text' => 'Signage Text',
            'signage.block.signage-heading' => 'Signage Heading',
        ],

        'de' => [
            // Signage Bereich
            'signage.area.label' => 'Beschilderung',
            'signage.screens.title' => 'Bildschirme',
            'signage.channels.title' => 'Inhaltskanäle',

            // Bildschirm Blueprint
            'signage.screen.title' => 'Bildschirm-Konfiguration',
            'signage.screen.tab.settings' => 'Einstellungen',
            'signage.screen.tab.content' => 'Inhalt',
            'signage.screen.tab.access' => 'Zugriffskontrolle',
            'signage.screen.tab.schedule' => 'Zeitplan',
            'signage.screen.field.screen_id' => 'Bildschirm-ID',
            'signage.screen.field.screen_id.help' => 'Eindeutige Kennung für diesen Bildschirm (wird in URLs verwendet)',
            'signage.screen.field.orientation' => 'Ausrichtung',
            'signage.screen.field.orientation.horizontal' => 'Horizontal (Querformat)',
            'signage.screen.field.orientation.vertical' => 'Vertikal (Hochformat)',
            'signage.screen.field.assigned_channel' => 'Primärer Kanal',
            'signage.screen.field.assigned_channel.help' => 'Standardkanal wenn kein Zeitplan passt',
            'signage.screen.field.active_times' => 'Aktive Zeiten',
            'signage.screen.field.active_times.help' => 'Bildschirm zeigt Inhalte während dieser Zeiten',
            'signage.screen.field.whitelist_enabled' => 'Zugriffskontrolle aktivieren',
            'signage.screen.field.whitelist_enabled.help' => 'Gerätegenehmigung vor Anzeige von Inhalten erforderlich',
            'signage.screen.field.whitelist' => 'Genehmigte Geräte',
            'signage.screen.field.pending_requests' => 'Ausstehende Zugriffsanfragen',
            'signage.screen.field.standby_mode' => 'Standby-Modus',
            'signage.screen.field.standby_image' => 'Standby-Bild',
            'signage.screen.field.standby_message' => 'Standby-Nachricht',
            'signage.screen.field.channel_schedule' => 'Zeitbasierter Kanalplan',

            // Kanal Blueprint
            'signage.channel.title' => 'Inhaltskanal',
            'signage.channel.field.channel_id' => 'Kanal-ID',
            'signage.channel.field.description' => 'Beschreibung',
            'signage.channel.field.background_color' => 'Hintergrundfarbe',
            'signage.channel.field.default_bg_image' => 'Standard-Hintergrundbild',
            'signage.channel.field.default_bg_video' => 'Standard-Hintergrundvideo',
            'signage.channel.field.default_overlay_color' => 'Standard-Overlay-Farbe',
            'signage.channel.field.default_overlay_opacity' => 'Standard-Overlay-Deckkraft',

            // Folie Blueprint
            'signage.slide.title' => 'Inhaltsfolie',
            'signage.slide.tab.content' => 'Inhalt',
            'signage.slide.tab.background' => 'Hintergrund',
            'signage.slide.tab.preview' => 'Vorschau',
            'signage.slide.field.slide_type' => 'Folientyp',
            'signage.slide.field.slide_type.blocks' => 'Inhaltsblöcke',
            'signage.slide.field.slide_type.video' => 'Vollbild-Video',
            'signage.slide.field.slide_type.calendar' => 'Kalender / Termine',
            'signage.slide.field.content_layout' => 'Inhalts-Layout',
            'signage.slide.field.duration_mode' => 'Anzeigedauer',
            'signage.slide.field.duration_mode.auto' => 'Automatisch berechnen',
            'signage.slide.field.duration_mode.manual' => 'Manuell festlegen',
            'signage.slide.field.duration_override' => 'Dauer (Sekunden)',
            'signage.slide.field.transition' => 'Übergangseffekt',
            'signage.slide.field.transition.fade' => 'Überblenden',
            'signage.slide.field.transition.slide' => 'Schieben',
            'signage.slide.field.transition.zoom' => 'Zoom',
            'signage.slide.field.transition.none' => 'Ohne (Sofort)',
            'signage.slide.field.bg_type' => 'Hintergrundtyp',
            'signage.slide.field.bg_type.none' => 'Kanal-Standard verwenden',
            'signage.slide.field.bg_type.image' => 'Eigenes Bild',
            'signage.slide.field.bg_type.video' => 'Eigenes Video (Endlosschleife)',
            'signage.slide.field.bg_type.color' => 'Nur Volltonfarbe',
            'signage.slide.field.overlay_enabled' => 'Overlay aktivieren',
            'signage.slide.field.overlay_color' => 'Overlay-Farbe',
            'signage.slide.field.overlay_opacity' => 'Deckkraft',

            // Kalender
            'signage.slide.field.calendar_source_type' => 'Kalenderquelle',
            'signage.slide.field.calendar_source_type.external' => 'Externe iCal-URL',
            'signage.slide.field.calendar_source_type.pages' => 'Kirby-Seiten',
            'signage.slide.field.calendar_source' => 'iCal-URL',
            'signage.slide.field.calendar_layout' => 'Anzeigeformat',
            'signage.slide.field.calendar_layout.list' => 'Terminliste',
            'signage.slide.field.calendar_layout.agenda' => 'Agenda-Ansicht',
            'signage.slide.field.calendar_layout.grid' => 'Rasteransicht',
            'signage.slide.field.calendar_range' => 'Zeitraum',
            'signage.slide.field.calendar_range.today' => 'Nur heute',
            'signage.slide.field.calendar_range.week' => 'Diese Woche',
            'signage.slide.field.calendar_range.month' => 'Dieser Monat',
            'signage.slide.field.calendar_max_events' => 'Max. Termine',

            // Blöcke
            'signage.block.signage-text' => 'Signage-Text',
            'signage.block.signage-heading' => 'Signage-Überschrift',
        ],
    ],
]);
