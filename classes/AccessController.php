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
    private const ROOT_PAGE_ID = 'signage';

    public static function registerOnboardingRequest(string $uuid, string $ip, ?string $backend = null, ?string $url = null): array
    {
        $rootPage = self::getRootPage();
        if (! $rootPage) {
            return [
                'status' => 'error',
                'access' => 'denied',
                'message' => 'Signage root page not found',
            ];
        }

        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $requests = self::getOnboardingRequests();
        $deniedRequests = self::getDeniedOnboardingStorage();
        $approvedRequest = self::findApprovedOnboardingRequest($uuid, $requests);
        if ($approvedRequest) {
            self::saveOnboardingRequests($rootPage, self::storeApprovedOnboardingRequest($requests, $approvedRequest));
            self::saveDeniedOnboardingStorage($rootPage, self::removeOnboardingRequestByUuid($deniedRequests, $uuid));

            return self::formatOnboardingResponse($approvedRequest);
        }

        $deniedExisting = self::findOnboardingRequest($deniedRequests, $uuid);
        if ($deniedExisting) {
            foreach ($deniedRequests as &$request) {
                if (($request['uuid'] ?? null) !== $uuid) {
                    continue;
                }

                $request['ip'] = $ip;
                $request['backend'] = $backend ?? ($request['backend'] ?? '');
                $request['url'] = $url ?? ($request['url'] ?? '');
                $request['user_agent'] = $userAgent;
                $request['last_seen_at'] = date('Y-m-d H:i:s');
            }
            unset($request);

            self::saveDeniedOnboardingStorage($rootPage, $deniedRequests);

            return self::formatOnboardingResponse([
                'status' => 'denied',
            ]);
        }

        $existing = self::findOnboardingRequest($requests, $uuid);

        if ($existing) {
            if (($existing['status'] ?? 'pending') === 'approved' && ! empty($existing['assigned_screen'])) {
                return self::formatOnboardingResponse($existing);
            }

            if (($existing['status'] ?? 'pending') === 'denied') {
                return self::formatOnboardingResponse($existing);
            }
        }

        $updated = false;
        foreach ($requests as &$request) {
            if (($request['uuid'] ?? null) !== $uuid) {
                continue;
            }

            $request['ip'] = $ip;
            $request['backend'] = $backend ?? ($request['backend'] ?? '');
            $request['url'] = $url ?? ($request['url'] ?? '');
            $request['user_agent'] = $userAgent;
            $request['last_seen_at'] = date('Y-m-d H:i:s');
            $request['status'] = $request['status'] ?? 'pending';
            $updated = true;
        }
        unset($request);

        if (! $updated) {
            $requests[] = [
                'uuid' => $uuid,
                'ip' => $ip,
                'backend' => $backend ?? '',
                'url' => $url ?? '',
                'user_agent' => $userAgent,
                'status' => 'pending',
                'assigned_screen' => '',
                'requested_at' => date('Y-m-d H:i:s'),
                'last_seen_at' => date('Y-m-d H:i:s'),
                'approved_at' => '',
                'approved_by' => '',
                'denied_at' => '',
            ];
        }

        self::saveOnboardingRequests($rootPage, $requests);

        return self::formatOnboardingResponse(self::findOnboardingRequest($requests, $uuid) ?? ['status' => 'pending']);
    }

    public static function getOnboardingStatus(string $uuid): array
    {
        $requests = self::getOnboardingRequests();
        $approvedRequest = self::findApprovedOnboardingRequest($uuid, $requests);
        if ($approvedRequest) {
            $rootPage = self::getRootPage();
            if ($rootPage) {
                self::saveOnboardingRequests($rootPage, self::storeApprovedOnboardingRequest($requests, $approvedRequest));
                self::saveDeniedOnboardingStorage($rootPage, self::removeOnboardingRequestByUuid(self::getDeniedOnboardingStorage(), $uuid));
            }

            return self::formatOnboardingResponse($approvedRequest);
        }

        $deniedRequest = self::findOnboardingRequest(self::getDeniedOnboardingStorage(), $uuid);
        if ($deniedRequest) {
            return self::formatOnboardingResponse([
                'status' => 'denied',
            ]);
        }

        $request = self::findOnboardingRequest($requests, $uuid);

        if (! $request) {
            return [
                'status' => 'pending',
                'access' => 'pending',
                'message' => 'Waiting for assignment',
            ];
        }

        return self::formatOnboardingResponse($request);
    }

    public static function approveOnboardingRequest(string $uuid, string $screenSlug, ?string $label = null): array
    {
        if (! $uuid || ! $screenSlug) {
            return [
                'status' => 'error',
                'message' => 'Missing uuid or screen parameter',
            ];
        }

        $rootPage = self::getRootPage();
        $screen = kirby()->page('signage/screens/' . $screenSlug);
        if (! $rootPage || ! $screen) {
            return [
                'status' => 'error',
                'message' => 'Screen not found',
            ];
        }

        $existingRequests = self::getOnboardingRequests();
        $existingDeniedRequests = self::getDeniedOnboardingStorage();
        $request = self::findOnboardingRequest($existingRequests, $uuid);
        if (! $request) {
            $request = self::findOnboardingRequest($existingDeniedRequests, $uuid);
            if (! $request) {
                return [
                    'status' => 'error',
                    'message' => 'Onboarding request not found',
                ];
            }

            $request['status'] = 'pending';
            $request['denied_at'] = '';
        }

        $requests = self::removeOnboardingRequestByUuid($existingRequests, $uuid);
        $deniedRequests = self::removeOnboardingRequestByUuid($existingDeniedRequests, $uuid);

        $label = $label && trim($label) !== '' ? trim($label) : self::DEFAULT_LABEL_PREFIX . substr($uuid, 0, 8);
        $grant = self::grantDeviceForScreen($screen, [
            'uuid' => $uuid,
            'ip' => $request['ip'] ?? '',
            'user_agent' => $request['user_agent'] ?? 'Unknown',
            'requested_at' => $request['requested_at'] ?? date('Y-m-d H:i:s'),
        ], $label);

        if (($grant['status'] ?? 'error') !== 'success') {
            return $grant;
        }

        $requests[] = [
            'uuid' => $uuid,
            'ip' => $request['ip'] ?? '',
            'backend' => $request['backend'] ?? '',
            'url' => $request['url'] ?? '',
            'user_agent' => $request['user_agent'] ?? 'Unknown',
            'status' => 'approved',
            'assigned_screen' => $screenSlug,
            'requested_at' => $request['requested_at'] ?? date('Y-m-d H:i:s'),
            'last_seen_at' => date('Y-m-d H:i:s'),
            'approved_at' => date('Y-m-d H:i:s'),
            'approved_by' => kirby()->user() ? kirby()->user()->email() : 'system',
            'denied_at' => '',
        ];

        self::saveOnboardingRequests($rootPage, $requests);
        self::saveDeniedOnboardingStorage($rootPage, $deniedRequests);

        return [
            'status' => 'success',
            'message' => 'Device assigned and approved',
        ];
    }

    public static function denyOnboardingRequest(string $uuid): array
    {
        if (! $uuid) {
            return [
                'status' => 'error',
                'message' => 'Missing uuid parameter',
            ];
        }

        $rootPage = self::getRootPage();
        if (! $rootPage) {
            return [
                'status' => 'error',
                'message' => 'Signage root page not found',
            ];
        }

        $requests = self::getOnboardingRequests();
        $deniedRequests = self::getDeniedOnboardingStorage();
        $found = false;
        $deniedEntry = null;
        $remainingRequests = [];

        foreach ($requests as $entry) {
            if (($entry['uuid'] ?? null) !== $uuid) {
                $remainingRequests[] = $entry;
                continue;
            }

            $entry['status'] = 'denied';
            $entry['assigned_screen'] = '';
            $entry['denied_at'] = date('Y-m-d H:i:s');
            $found = true;
            $deniedEntry = $entry;
        }

        if (! $found) {
            return [
                'status' => 'error',
                'message' => 'Onboarding request not found',
            ];
        }

        $deniedRequests = array_values(array_filter($deniedRequests, function ($entry) use ($uuid) {
            return ($entry['uuid'] ?? '') !== $uuid;
        }));
        $deniedRequests[] = $deniedEntry;

        self::saveOnboardingRequests($rootPage, $remainingRequests);
        self::saveDeniedOnboardingStorage($rootPage, $deniedRequests);

        return [
            'status' => 'success',
            'message' => 'Request denied',
        ];
    }

    public static function removeDeniedOnboardingRequest(string $uuid): array
    {
        if (! $uuid) {
            return [
                'status' => 'error',
                'message' => 'Missing uuid parameter',
            ];
        }

        $rootPage = self::getRootPage();
        if (! $rootPage) {
            return [
                'status' => 'error',
                'message' => 'Signage root page not found',
            ];
        }

        $deniedRequests = self::getDeniedOnboardingStorage();
        $countBefore = count($deniedRequests);
        $deniedRequests = array_values(array_filter($deniedRequests, function ($entry) use ($uuid) {
            return ($entry['uuid'] ?? '') !== $uuid;
        }));

        if ($countBefore === count($deniedRequests)) {
            return [
                'status' => 'error',
                'message' => 'Denied request not found',
            ];
        }

        self::saveDeniedOnboardingStorage($rootPage, $deniedRequests);

        return [
            'status' => 'success',
            'message' => 'Denied request removed',
        ];
    }

    public static function getPendingOnboardingRequests(): array
    {
        $deniedUuids = array_map(function (array $request) {
            return $request['uuid'] ?? '';
        }, self::getDeniedOnboardingStorage());
        $approvedUuids = self::getApprovedDeviceUuids();

        return array_values(array_filter(self::getOnboardingRequests(), function (array $request) use ($deniedUuids, $approvedUuids) {
            return ($request['status'] ?? 'pending') === 'pending'
                && ! in_array(($request['uuid'] ?? ''), $deniedUuids, true)
                && ! in_array(($request['uuid'] ?? ''), $approvedUuids, true);
        }));
    }

    public static function getDeniedOnboardingRequests(): array
    {
        $denied = self::getDeniedOnboardingStorage();
        $fallbackDenied = array_values(array_filter(self::getOnboardingRequests(), function (array $request) {
            return ($request['status'] ?? '') === 'denied';
        }));

        foreach ($fallbackDenied as $request) {
            if (! self::findOnboardingRequest($denied, $request['uuid'] ?? '')) {
                $denied[] = $request;
            }
        }

        $approvedUuids = [];
        $screensRoot = kirby()->page('signage/screens');
        if ($screensRoot) {
            foreach ($screensRoot->children() as $screen) {
                foreach (self::whitelistToArray($screen->whitelist()->toStructure()) as $device) {
                    if (! empty($device['uuid'])) {
                        $approvedUuids[] = $device['uuid'];
                    }
                }
            }
        }

        return array_values(array_filter($denied, function (array $request) use ($approvedUuids) {
            return ! in_array(($request['uuid'] ?? ''), $approvedUuids, true);
        }));
    }

    public static function getApprovedOnboardingDevices(): array
    {
        $screensRoot = kirby()->page('signage/screens');
        if (! $screensRoot) {
            return [];
        }

        $onboardingRequests = self::getOnboardingRequests();
        $requestsByUuid = [];
        foreach ($onboardingRequests as $request) {
            if (! empty($request['uuid'])) {
                $requestsByUuid[$request['uuid']] = $request;
            }
        }

        $result = [];
        foreach ($screensRoot->children() as $screen) {
            foreach (self::whitelistToArray($screen->whitelist()->toStructure()) as $device) {
                $requestData = $requestsByUuid[$device['uuid']] ?? [];
                $result[] = [
                    'uuid' => $device['uuid'] ?? '',
                    'label' => $device['label'] ?? '',
                    'ip' => $device['ip'] ?? '',
                    'approved_at' => $device['approved_at'] ?? '',
                    'approved_by' => $device['approved_by'] ?? '',
                    'screen' => $screen->slug(),
                    'screen_title' => $screen->title()->value(),
                    'backend' => $requestData['backend'] ?? '',
                    'user_agent' => $requestData['user_agent'] ?? '',
                ];
            }
        }

        usort($result, function (array $a, array $b) {
            return strcmp((string) ($b['approved_at'] ?? ''), (string) ($a['approved_at'] ?? ''));
        });

        return $result;
    }

    public static function getAvailableScreens(): array
    {
        $screens = kirby()->page('signage/screens');
        if (! $screens) {
            return [];
        }

        $result = [];
        foreach ($screens->children() as $screen) {
            $result[] = [
                'value' => $screen->slug(),
                'text' => $screen->title()->value(),
            ];
        }

        return $result;
    }

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

    private static function grantDeviceForScreen($screen, array $deviceData, string $label): array
    {
        $whitelistArray = self::whitelistToArray($screen->whitelist()->toStructure());
        $pendingArray = self::pendingRequestsToArray($screen->pending_requests()->toStructure());
        $deniedArray = self::deniedRequestsToArray($screen->denied_requests()->toStructure());
        $existingWhitelistEntry = null;

        foreach ($whitelistArray as $entry) {
            if (($entry['uuid'] ?? '') === ($deviceData['uuid'] ?? '')) {
                $existingWhitelistEntry = $entry;
                break;
            }
        }

        $whitelistArray = array_values(array_filter($whitelistArray, function ($entry) use ($deviceData) {
            return ($entry['uuid'] ?? '') !== ($deviceData['uuid'] ?? '');
        }));
        $pendingArray = array_values(array_filter($pendingArray, function ($entry) use ($deviceData) {
            return ($entry['uuid'] ?? '') !== ($deviceData['uuid'] ?? '');
        }));
        $deniedArray = array_values(array_filter($deniedArray, function ($entry) use ($deviceData) {
            return ($entry['uuid'] ?? '') !== ($deviceData['uuid'] ?? '');
        }));

        $approvedAt = $deviceData['approved_at']
            ?? ($existingWhitelistEntry['approved_at'] ?? date('Y-m-d H:i:s'));
        $approvedBy = $deviceData['approved_by']
            ?? ($existingWhitelistEntry['approved_by'] ?? (kirby()->user() ? kirby()->user()->email() : 'system'));

        $whitelistArray[] = [
            'uuid' => $deviceData['uuid'],
            'ip' => $deviceData['ip'] ?? '',
            'label' => $label,
            'approved_at' => $approvedAt,
            'approved_by' => $approvedBy,
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

    public static function getApprovedDevicesForScreen($screen): array
    {
        return self::whitelistToArray($screen->whitelist()->toStructure());
    }

    public static function revokeApprovedDevice(string $screenSlug, string $uuid): array
    {
        $screen = kirby()->page('signage/screens/' . $screenSlug);
        if (! $screen) {
            return [
                'status' => 'error',
                'message' => 'Screen not found',
            ];
        }

        $whitelistArray = array_values(array_filter(
            self::whitelistToArray($screen->whitelist()->toStructure()),
            fn ($entry) => ($entry['uuid'] ?? '') !== $uuid
        ));

        try {
            $screen->update([
                'whitelist' => \Kirby\Data\Yaml::encode($whitelistArray),
            ]);
            self::clearOnboardingAssignment($uuid, $screenSlug);

            return [
                'status' => 'success',
                'message' => 'Device approval revoked',
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Failed to update screen: ' . $e->getMessage(),
            ];
        }
    }

    public static function reassignApprovedDevice(string $fromScreenSlug, string $toScreenSlug, string $uuid): array
    {
        if (! $fromScreenSlug || ! $toScreenSlug || ! $uuid) {
            return [
                'status' => 'error',
                'message' => 'Missing screen or uuid parameter',
            ];
        }

        $fromScreen = kirby()->page('signage/screens/' . $fromScreenSlug);
        $toScreen = kirby()->page('signage/screens/' . $toScreenSlug);

        if (! $fromScreen || ! $toScreen) {
            return [
                'status' => 'error',
                'message' => 'Screen not found',
            ];
        }

        $device = null;
        foreach (self::whitelistToArray($fromScreen->whitelist()->toStructure()) as $entry) {
            if (($entry['uuid'] ?? '') === $uuid) {
                $device = $entry;
                break;
            }
        }

        if (! $device) {
            return [
                'status' => 'error',
                'message' => 'Approved device not found',
            ];
        }

        $revoke = self::revokeApprovedDevice($fromScreenSlug, $uuid);
        if (($revoke['status'] ?? 'error') !== 'success') {
            return $revoke;
        }

        $grant = self::grantDeviceForScreen($toScreen, [
            'uuid' => $uuid,
            'ip' => $device['ip'] ?? '',
            'user_agent' => 'Reassigned device',
            'requested_at' => $device['approved_at'] ?? date('Y-m-d H:i:s'),
            'approved_at' => $device['approved_at'] ?? '',
            'approved_by' => $device['approved_by'] ?? '',
        ], $device['label'] ?? (self::DEFAULT_LABEL_PREFIX . substr($uuid, 0, 8)));

        if (($grant['status'] ?? 'error') !== 'success') {
            return $grant;
        }

        self::syncOnboardingAssignment(
            $uuid,
            $toScreenSlug,
            $device['approved_at'] ?? null,
            $device['approved_by'] ?? null
        );

        return [
            'status' => 'success',
            'message' => 'Device reassigned',
        ];
    }

    public static function renameApprovedDevice(string $screenSlug, string $uuid, string $label): array
    {
        if (! $screenSlug || ! $uuid) {
            return [
                'status' => 'error',
                'message' => 'Missing screen or uuid parameter',
            ];
        }

        $screen = kirby()->page('signage/screens/' . $screenSlug);
        if (! $screen) {
            return [
                'status' => 'error',
                'message' => 'Screen not found',
            ];
        }

        $label = trim($label);
        if ($label === '') {
            $label = self::DEFAULT_LABEL_PREFIX . substr($uuid, 0, 8);
        }

        $whitelistArray = self::whitelistToArray($screen->whitelist()->toStructure());
        $found = false;

        foreach ($whitelistArray as &$entry) {
            if (($entry['uuid'] ?? '') !== $uuid) {
                continue;
            }

            $entry['label'] = $label;
            $found = true;
        }
        unset($entry);

        if (! $found) {
            return [
                'status' => 'error',
                'message' => 'Approved device not found',
            ];
        }

        try {
            $screen->update([
                'whitelist' => \Kirby\Data\Yaml::encode($whitelistArray),
            ]);

            return [
                'status' => 'success',
                'message' => 'Device renamed',
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Failed to update screen: ' . $e->getMessage(),
            ];
        }
    }

    public static function moveScreenDevicesToPendingForScreen($screen): void
    {
        if (! $screen || $screen->intendedTemplate()->name() !== 'screen') {
            return;
        }

        $rootPage = self::getRootPage();
        if (! $rootPage) {
            return;
        }

        $screenSlug = $screen->slug();
        $requests = self::getOnboardingRequests();
        $requestsByUuid = [];
        foreach ($requests as $request) {
            if (! empty($request['uuid'])) {
                $requestsByUuid[$request['uuid']] = $request;
            }
        }

        $devices = array_merge(
            self::whitelistToArray($screen->whitelist()->toStructure()),
            self::pendingRequestsToArray($screen->pending_requests()->toStructure()),
            self::deniedRequestsToArray($screen->denied_requests()->toStructure())
        );

        $updated = false;
        foreach ($devices as $device) {
            $uuid = $device['uuid'] ?? '';
            if ($uuid === '') {
                continue;
            }

            $existing = $requestsByUuid[$uuid] ?? [];
            $requestsByUuid[$uuid] = [
                'uuid' => $uuid,
                'ip' => $existing['ip'] ?? ($device['ip'] ?? ''),
                'backend' => $existing['backend'] ?? '',
                'url' => $existing['url'] ?? '',
                'user_agent' => $existing['user_agent'] ?? ($device['user_agent'] ?? 'Unknown'),
                'status' => 'pending',
                'assigned_screen' => '',
                'requested_at' => $existing['requested_at'] ?? ($device['requested_at'] ?? date('Y-m-d H:i:s')),
                'last_seen_at' => date('Y-m-d H:i:s'),
                'approved_at' => '',
                'approved_by' => '',
                'denied_at' => '',
            ];
            $updated = true;
        }

        if (! $updated) {
            return;
        }

        self::saveOnboardingRequests($rootPage, array_values($requestsByUuid));
        self::saveDeniedOnboardingStorage(
            $rootPage,
            array_values(array_filter(self::getDeniedOnboardingStorage(), fn ($request) => ($request['assigned_screen'] ?? '') !== $screenSlug))
        );
    }

    private static function approveRequestForScreenModel($screen, string $uuid, ?string $label = null): array
    {
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
                }
            }
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

        return self::grantDeviceForScreen($screen, $approvedRequest, $label);
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

    private static function getRootPage()
    {
        $rootPage = kirby()->page(self::ROOT_PAGE_ID);
        if (! $rootPage) {
            return null;
        }

        if ($rootPage->intendedTemplate()->name() !== 'signage') {
            try {
                $rootPage = $rootPage->changeTemplate('signage');
            } catch (Throwable $e) {
                error_log('Signage: Failed to normalize root page template - ' . $e->getMessage());
            }
        }

        return kirby()->page(self::ROOT_PAGE_ID) ?? $rootPage;
    }

    private static function getOnboardingRequests(): array
    {
        $rootPage = self::getRootPage();
        if (! $rootPage) {
            return [];
        }

        return self::onboardingRequestsToArray($rootPage->onboarding_requests()->toStructure());
    }

    private static function getDeniedOnboardingStorage(): array
    {
        $rootPage = self::getRootPage();
        if (! $rootPage) {
            return [];
        }

        return self::onboardingRequestsToArray($rootPage->denied_onboarding_requests()->toStructure());
    }

    private static function saveOnboardingRequests($rootPage, array $requests): void
    {
        self::writeRootPageContent($rootPage, [
            'onboarding_requests' => empty($requests) ? '' : \Kirby\Data\Yaml::encode($requests),
        ]);
    }

    private static function saveDeniedOnboardingStorage($rootPage, array $requests): void
    {
        self::writeRootPageContent($rootPage, [
            'denied_onboarding_requests' => empty($requests) ? '' : \Kirby\Data\Yaml::encode($requests),
        ]);
    }

    private static function writeRootPageContent($rootPage, array $data): void
    {
        if (! $rootPage) {
            return;
        }

        if ($rootPage->intendedTemplate()->name() !== 'signage') {
            $rootPage = $rootPage->changeTemplate('signage');
        }

        $content = $rootPage->content()->toArray();
        $rootPage->version()->save(array_merge($content, $data), 'default', true);
    }

    private static function onboardingRequestsToArray($requests): array
    {
        $result = [];

        foreach ($requests as $request) {
            $result[] = [
                'uuid' => $request->uuid()->value(),
                'ip' => $request->ip()->value(),
                'backend' => $request->backend()->value(),
                'url' => $request->url()->value(),
                'user_agent' => $request->user_agent()->value(),
                'status' => $request->status()->value() ?: 'pending',
                'assigned_screen' => $request->assigned_screen()->value(),
                'requested_at' => $request->requested_at()->value(),
                'last_seen_at' => $request->last_seen_at()->value(),
                'approved_at' => $request->approved_at()->value(),
                'approved_by' => $request->approved_by()->value(),
                'denied_at' => $request->denied_at()->value(),
            ];
        }

        return $result;
    }

    private static function findOnboardingRequest(array $requests, string $uuid): ?array
    {
        foreach ($requests as $request) {
            if (($request['uuid'] ?? null) === $uuid) {
                return $request;
            }
        }

        return null;
    }

    private static function removeOnboardingRequestByUuid(array $requests, string $uuid): array
    {
        return array_values(array_filter($requests, function ($request) use ($uuid) {
            return ($request['uuid'] ?? '') !== $uuid;
        }));
    }

    private static function storeApprovedOnboardingRequest(array $requests, array $approvedRequest): array
    {
        $requests = self::removeOnboardingRequestByUuid($requests, $approvedRequest['uuid'] ?? '');
        $requests[] = $approvedRequest;

        return $requests;
    }

    private static function findApprovedOnboardingRequest(string $uuid, ?array $requests = null): ?array
    {
        $requests ??= self::getOnboardingRequests();
        $request = self::findOnboardingRequest($requests, $uuid);

        if ($request && ($request['status'] ?? '') === 'approved' && ! empty($request['assigned_screen'])) {
            return $request;
        }

        $screenSlug = self::findApprovedScreenSlugByUuid($uuid);
        if (! $screenSlug) {
            return null;
        }

        return [
            'uuid' => $uuid,
            'ip' => $request['ip'] ?? '',
            'backend' => $request['backend'] ?? '',
            'url' => $request['url'] ?? '',
            'user_agent' => $request['user_agent'] ?? 'Unknown',
            'status' => 'approved',
            'assigned_screen' => $screenSlug,
            'requested_at' => $request['requested_at'] ?? date('Y-m-d H:i:s'),
            'last_seen_at' => date('Y-m-d H:i:s'),
            'approved_at' => $request['approved_at'] ?? date('Y-m-d H:i:s'),
            'approved_by' => $request['approved_by'] ?? 'system',
            'denied_at' => '',
        ];
    }

    private static function findApprovedScreenSlugByUuid(string $uuid): ?string
    {
        $screensRoot = kirby()->page('signage/screens');
        if (! $screensRoot) {
            return null;
        }

        foreach ($screensRoot->children() as $screen) {
            foreach (self::whitelistToArray($screen->whitelist()->toStructure()) as $device) {
                if (($device['uuid'] ?? '') === $uuid) {
                    return $screen->slug();
                }
            }
        }

        return null;
    }

    private static function getApprovedDeviceUuids(): array
    {
        $screensRoot = kirby()->page('signage/screens');
        if (! $screensRoot) {
            return [];
        }

        $approvedUuids = [];
        foreach ($screensRoot->children() as $screen) {
            foreach (self::whitelistToArray($screen->whitelist()->toStructure()) as $device) {
                if (! empty($device['uuid'])) {
                    $approvedUuids[] = $device['uuid'];
                }
            }
        }

        return array_values(array_unique($approvedUuids));
    }

    private static function syncOnboardingAssignment(
        string $uuid,
        string $screenSlug,
        ?string $approvedAt = null,
        ?string $approvedBy = null
    ): void {
        $rootPage = self::getRootPage();
        if (! $rootPage) {
            return;
        }

        $requests = self::getOnboardingRequests();
        $updated = false;

        foreach ($requests as &$request) {
            if (($request['uuid'] ?? null) !== $uuid) {
                continue;
            }

            $request['status'] = 'approved';
            $request['assigned_screen'] = $screenSlug;
            $request['approved_at'] = $approvedAt ?: ($request['approved_at'] ?? date('Y-m-d H:i:s'));
            $request['approved_by'] = $approvedBy ?: ($request['approved_by'] ?? (kirby()->user() ? kirby()->user()->email() : 'system'));
            $request['denied_at'] = '';
            $updated = true;
        }
        unset($request);

        if ($updated) {
            self::saveOnboardingRequests($rootPage, $requests);
        }
    }

    private static function clearOnboardingAssignment(string $uuid, string $screenSlug): void
    {
        $rootPage = self::getRootPage();
        if (! $rootPage) {
            return;
        }

        $requests = self::getOnboardingRequests();
        $updated = false;

        foreach ($requests as &$request) {
            if (($request['uuid'] ?? null) !== $uuid) {
                continue;
            }

            if (($request['assigned_screen'] ?? '') !== $screenSlug) {
                continue;
            }

            $request['status'] = 'pending';
            $request['assigned_screen'] = '';
            $request['approved_at'] = '';
            $request['approved_by'] = '';
            $updated = true;
        }
        unset($request);

        if ($updated) {
            self::saveOnboardingRequests($rootPage, $requests);
        }
    }

    private static function formatOnboardingResponse(array $request): array
    {
        $status = $request['status'] ?? 'pending';
        $screenSlug = $request['assigned_screen'] ?? null;

        if ($status === 'approved' && $screenSlug) {
            return [
                'status' => 'success',
                'access' => 'granted',
                'screen' => $screenSlug,
                'message' => 'Device approved',
            ];
        }

        if ($status === 'denied') {
            return [
                'status' => 'denied',
                'access' => 'denied',
                'message' => 'Access denied. Contact your administrator.',
            ];
        }

        return [
            'status' => 'pending',
            'access' => 'pending',
            'message' => 'Waiting for assignment',
        ];
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
        $slides = $channel->children()->listed();
        $legacySlides = $channel->children()->unlisted();

        if ($slides->isEmpty()) {
            $slides = $legacySlides;
        } elseif ($legacySlides->isNotEmpty()) {
            $slides = $slides->merge($legacySlides);
        }
        $slidesData = [];

        foreach ($slides as $slide) {
            $slidesData[] = self::getSlideData($slide);
        }

        return [
            'status' => 'active',
            'revision' => self::getContentRevision($screen),
            'screen' => [
                'title' => $screen->title()->value(),
                'orientation' => $screen->orientation()->value(),
            ],
            'channel' => [
                'title' => $channel->title()->value(),
                'slides_count' => count($slidesData),
                'background' => self::getChannelBackgroundData($channel),
            ],
            'slides' => $slidesData,
        ];
    }

    public static function getContentState($screen): array
    {
        return [
            'status' => 'success',
            'revision' => self::getContentRevision($screen),
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

    private static function getChannelBackgroundData($channel): array
    {
        $background = [
            'type' => 'color',
            'background_color' => $channel->background_color()->value() ?: '#000000',
            'overlay' => [
                'enabled' => true,
                'color' => $channel->default_overlay_color()->value() ?: '#000000',
                'opacity' => (int) ($channel->default_overlay_opacity()->value() ?: 40),
                'gradient' => 'none',
            ],
        ];

        $bgImage = $channel->default_bg_image()->toFile();
        if ($bgImage) {
            $background['type'] = 'image';
            $background['image'] = [
                'url' => $bgImage->url(),
                'position' => 'center',
                'size' => 'cover',
            ];
        }

        $bgVideo = $channel->default_bg_video()->toFile();
        if ($bgVideo) {
            $background['type'] = 'video';
            $background['video'] = [
                'url' => $bgVideo->url(),
                'type' => $bgVideo->mime(),
            ];
        }

        return $background;
    }

    private static function getContentRevision($screen): string
    {
        $parts = [
            'screen:' . $screen->modified('U'),
            'assigned:' . $screen->assigned_channel()->value(),
            'schedule:' . sha1($screen->channel_schedule()->value()),
            'times:' . sha1($screen->active_times()->value()),
            'standby:' . sha1($screen->standby_mode()->value() . '|' . $screen->standby_message()->value() . '|' . $screen->standby_image()->value()),
        ];

        $channelIds = [$screen->assigned_channel()->value()];
        foreach ($screen->channel_schedule()->toStructure() as $entry) {
            $channelIds[] = $entry->channel()->value();
        }

        foreach (array_unique(array_filter($channelIds)) as $channelId) {
            $channel = kirby()->page($channelId);
            if (! $channel) {
                continue;
            }

            $parts[] = 'channel:' . $channel->id() . ':' . $channel->modified('U');

            foreach ($channel->childrenAndDrafts() as $slide) {
                $parts[] = 'slide:' . $slide->id() . ':' . $slide->modified('U');
            }
        }

        return sha1(implode('|', $parts));
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
