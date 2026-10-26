<?php

/**
 * PHPUnit bootstrap.
 *
 * Boots a minimal Kirby instance in a temp directory, so tests need neither
 * the real content nor the host site (no DB, no network).
 */

require __DIR__ . '/../vendor/autoload.php';

$tmp = sys_get_temp_dir() . '/gs-mmh-signage-plugin-phpunit';

new Kirby\Cms\App([
    'roots' => [
        'index' => $tmp,
        'base' => $tmp,
        'site' => $tmp . '/site',
        'content' => $tmp . '/content',
        'cache' => $tmp . '/cache',
        'sessions' => $tmp . '/sessions',
        'accounts' => $tmp . '/accounts',
        'logs' => $tmp . '/logs',
    ],
    'urls' => [
        'index' => 'https://mmh.test',
    ],
]);

require_once dirname(__DIR__) . '/classes/DurationCalculator.php';
require_once dirname(__DIR__) . '/classes/ICalParser.php';
