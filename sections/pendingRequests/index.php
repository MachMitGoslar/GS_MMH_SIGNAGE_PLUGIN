<?php

/**
 * Pending Requests Section
 *
 * Custom panel section for managing pending device access requests
 */

use Kirby\Cms\App as Kirby;

return [
    'props' => [
        'headline' => function (string $headline = 'Pending Access Requests') {
            return $headline;
        },
    ],
    'computed' => [
        'requests' => function () {
            $page = $this->model();
            $pending = $page->pending_requests()->toStructure();
            $requests = [];

            foreach ($pending as $item) {
                $requests[] = [
                    'uuid' => $item->uuid()->value(),
                    'ip' => $item->ip()->value(),
                    'requested_at' => $item->requested_at()->value(),
                    'user_agent' => $item->user_agent()->value() ?? '',
                ];
            }

            return $requests;
        },
        'screen' => function () {
            return $this->model()->slug();
        },
    ],
    'api' => function () {
        return [
            [
                'pattern' => 'approve',
                'method' => 'POST',
                'action' => function () {
                    $uuid = $this->requestBody('uuid');
                    $screen = $this->model();

                    if (!$screen || !$uuid) {
                        return ['status' => 'error', 'message' => 'Missing parameters'];
                    }

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
                        'approved_by' => kirby()->user() ? kirby()->user()->email() : 'System',
                    ];

                    // Update page
                    try {
                        $screen->update([
                            'pending_requests' => \Kirby\Data\Yaml::encode($pendingArray),
                            'whitelist' => \Kirby\Data\Yaml::encode($whitelistArray),
                        ]);

                        return ['status' => 'success', 'message' => 'Device approved'];
                    } catch (Exception $e) {
                        return ['status' => 'error', 'message' => $e->getMessage()];
                    }
                },
            ],
            [
                'pattern' => 'deny',
                'method' => 'POST',
                'action' => function () {
                    $uuid = $this->requestBody('uuid');
                    $screen = $this->model();

                    if (!$screen || !$uuid) {
                        return ['status' => 'error', 'message' => 'Missing parameters'];
                    }

                    // Remove from pending
                    $pending = $screen->pending_requests()->toStructure();
                    $pendingArray = [];

                    foreach ($pending as $item) {
                        if ($item->uuid()->value() !== $uuid) {
                            $pendingArray[] = $item->toArray();
                        }
                    }

                    try {
                        $screen->update([
                            'pending_requests' => \Kirby\Data\Yaml::encode($pendingArray),
                        ]);

                        return ['status' => 'success', 'message' => 'Request denied'];
                    } catch (Exception $e) {
                        return ['status' => 'error', 'message' => $e->getMessage()];
                    }
                },
            ],
        ];
    },
];
