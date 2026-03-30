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
                    return AccessController::getApprovedDevicesForScreen($model);
                }
            }

            $value = $this->value;
            if (is_string($value)) {
                $value = \Kirby\Data\Yaml::decode($value);
            }

            return $value ?? [];
        },
        'approvedDevices' => function () {
            if (method_exists($this, 'model')) {
                $model = $this->model();
                if ($model) {
                    return AccessController::getApprovedDevicesForScreen($model);
                }
            }

            return [];
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
        return [];
    },
];
