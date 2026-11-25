<?php

/**
 * Migration of signage slides: unified look ("Hintergrund & Lesbarkeit")
 * and block slides → template "Bild".
 *
 *   php scripts/migrate-look.php <content-dir> [--apply] [--report=file.md]
 *
 * Without --apply it only prints what would change. Works on the files
 * directly – Kirby is not booted, so git-content commits nothing; commit
 * the content repository yourself afterwards.
 */

require __DIR__ . '/../classes/SlideLook.php';
require __DIR__ . '/../classes/LookMigration.php';

$args = array_slice($argv, 1);
$root = null;
$apply = false;
$reportFile = null;

foreach ($args as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif (str_starts_with($arg, '--report=')) {
        $reportFile = substr($arg, 9);
    } else {
        $root = $arg;
    }
}

if ($root === null || !is_dir($root . '/signage')) {
    fwrite(STDERR, "Aufruf: php scripts/migrate-look.php <content-dir> [--apply] [--report=datei.md]\n");
    exit(1);
}

$plan = LookMigration::plan($root);
$report = LookMigration::report($plan, $root);

if ($reportFile) {
    file_put_contents($reportFile, $report);
}

echo $report, "\n";

if ($apply) {
    LookMigration::apply($plan);
    echo count($plan), " Dateien geschrieben.\n";
} else {
    echo "Trockenlauf – nichts geschrieben. Mit --apply ausführen.\n";
}
