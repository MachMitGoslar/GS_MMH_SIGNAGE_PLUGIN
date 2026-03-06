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
            if (method_exists($this, 'model')) {
                $model = $this->model();
                if ($model) {
                    return AccessController::getPendingRequestsForScreen($model);
                }
            }

            $value = $this->value;
            if (is_string($value)) {
                $value = \Kirby\Data\Yaml::decode($value);
            }

            return $value ?? [];
        },
        'screen' => function () {
            if (method_exists($this, 'model')) {
                $model = $this->model();
                if ($model) {
                    return $model->slug();
                }
            }

            return $this->screen;
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
                    $label = $this->requestBody('label');

                    if (! $uuid) {
                        return [
                            'status' => 'error',
                            'message' => 'Missing UUID or screen parameter',
                        ];
                    }

                    $screen = method_exists($this, 'model') ? $this->model() : null;
                    if ($screen) {
                        return AccessController::approveRequestForScreen($screen, $uuid, $label ?: 'Unknown Device');
                    }

                    if (! $screenSlug) {
                        return [
                            'status' => 'error',
                            'message' => 'Missing screen parameter',
                        ];
                    }

                    return AccessController::approveRequest($screenSlug, $uuid, $label ?: 'Unknown Device');
                },
            ],
            [
                'pattern' => 'deny',
                'method' => 'POST',
                'action' => function () {
                    $uuid = $this->requestBody('uuid');
                    $screenSlug = $this->requestBody('screen');

                    if (! $uuid) {
                        return [
                            'status' => 'error',
                            'message' => 'Missing UUID or screen parameter',
                        ];
                    }

                    $screen = method_exists($this, 'model') ? $this->model() : null;
                    if ($screen) {
                        return AccessController::denyRequestForScreen($screen, $uuid);
                    }

                    if (! $screenSlug) {
                        return [
                            'status' => 'error',
                            'message' => 'Missing screen parameter',
                        ];
                    }

                    return AccessController::denyRequest($screenSlug, $uuid);
                },
            ],
        ];
    },
];
