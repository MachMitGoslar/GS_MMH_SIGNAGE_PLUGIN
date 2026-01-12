<?php

/**
 * Pending Requests Field
 *
 * Custom panel field for managing pending device access requests
 * with approve/deny buttons.
 */


return [
    'props' => [
        'value' => function ($value = null) {
            return $value;
        },
        'screen' => function (?string $screen = null) {
            return $screen;
        },
    ],
    'computed' => [
        'requests' => function () {
            $value = $this->value;
            if (is_string($value)) {
                $value = \Kirby\Data\Yaml::decode($value);
            }

            return $value ?? [];
        },
    ],
    'api' => function () {
        return [
            [
                'pattern' => 'approve',
                'method' => 'POST',
                'action' => function () {
                    $uuid = $this->requestBody('uuid');
                    $screenSlug = $this->requestBody('screen');

                    if (! $uuid || ! $screenSlug) {
                        return [
                            'status' => 'error',
                            'message' => 'Missing UUID or screen parameter',
                        ];
                    }

                    $screen = kirby()->page('signage/screens/' . $screenSlug);
                    if (! $screen) {
                        return [
                            'status' => 'error',
                            'message' => 'Screen not found',
                        ];
                    }

                    // Get current pending requests
                    $pending = $screen->pending_requests()->toStructure();
                    $pendingArray = [];
                    foreach ($pending as $item) {
                        if ($item->uuid()->value() !== $uuid) {
                            $pendingArray[] = $item->toArray();
                        }
                    }

                    // Get current whitelist
                    $whitelist = $screen->whitelist()->toStructure();
                    $whitelistArray = [];
                    foreach ($whitelist as $item) {
                        $whitelistArray[] = $item->toArray();
                    }

                    // Find the request to get IP
                    $requestIp = null;
                    foreach ($pending as $item) {
                        if ($item->uuid()->value() === $uuid) {
                            $requestIp = $item->ip()->value();

                            break;
                        }
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

                        return [
                            'status' => 'success',
                            'message' => 'Device approved',
                        ];
                    } catch (Exception $e) {
                        return [
                            'status' => 'error',
                            'message' => $e->getMessage(),
                        ];
                    }
                },
            ],
            [
                'pattern' => 'deny',
                'method' => 'POST',
                'action' => function () {
                    $uuid = $this->requestBody('uuid');
                    $screenSlug = $this->requestBody('screen');

                    if (! $uuid || ! $screenSlug) {
                        return [
                            'status' => 'error',
                            'message' => 'Missing UUID or screen parameter',
                        ];
                    }

                    $screen = kirby()->page('signage/screens/' . $screenSlug);
                    if (! $screen) {
                        return [
                            'status' => 'error',
                            'message' => 'Screen not found',
                        ];
                    }

                    // Remove from pending requests
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

                        return [
                            'status' => 'success',
                            'message' => 'Access request denied',
                        ];
                    } catch (Exception $e) {
                        return [
                            'status' => 'error',
                            'message' => $e->getMessage(),
                        ];
                    }
                },
            ],
        ];
    },
];
