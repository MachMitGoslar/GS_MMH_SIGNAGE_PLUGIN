<?php

return [
    'props' => [
        'value' => function ($value = null) {
            return $value;
        },
    ],
    'computed' => [
        'requests' => function () {
            return AccessController::getPendingOnboardingRequests();
        },
        'deniedRequests' => function () {
            return AccessController::getDeniedOnboardingRequests();
        },
        'approvedDevices' => function () {
            return AccessController::getApprovedOnboardingDevices();
        },
        'screens' => function () {
            return AccessController::getAvailableScreens();
        },
    ],
    'api' => function () {
        return [
            [
                'pattern' => 'approve',
                'method' => 'POST',
                'action' => function () {
                    $uuid = $this->requestBody('uuid');
                    $screen = $this->requestBody('screen');
                    $label = $this->requestBody('label');

                    return AccessController::approveOnboardingRequest($uuid, $screen, $label ?: 'Unknown Device');
                },
            ],
            [
                'pattern' => 'deny',
                'method' => 'POST',
                'action' => function () {
                    $uuid = $this->requestBody('uuid');

                    return AccessController::denyOnboardingRequest($uuid);
                },
            ],
        ];
    },
];
