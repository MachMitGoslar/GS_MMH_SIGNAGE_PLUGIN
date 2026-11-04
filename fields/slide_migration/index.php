<?php

/**
 * Slide Migration Field
 *
 * Shows how many legacy slides (stored below channels) still have to be
 * moved into the shared slide library and offers a one-click migration.
 */

return [
    'computed' => [
        'pending' => function () {
            return SlideLibrary::pendingMigrationCount();
        },
    ],
];
