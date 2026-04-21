<?php
/**
 * TELEPAGE — tests/ConfigConcurrencyTest.php
 * Stress test for Config::update() under concurrent writers.
 *
 * Spawns N parallel PHP processes; each process calls Config::update()
 * K times with a unique key (e.g. worker #3 writes 'ccurrent_3' = 42).
 * After all processes complete, the test reads config.json once and
 * asserts that ALL N*K updates survived. A lost-update bug would show
 * as missing keys in the final file.
 *
 * Usage: php tests/ConfigConcurrencyTest.php
 *
 * This test TEMPORARILY OVERWRITES config.json — it backs up the file
 * first and restores it at the end. Abort at the wrong moment and the
 * backup is at config.json.stress-backup.
 */

declare(strict_types=1);

define('TELEPAGE_ROOT', dirname(__DIR__));
require_once TELEPAGE_ROOT . '/app/Config.php';

$configPath = TELEPAGE_ROOT . '/config.json';
$backupPath = $configPath . '.stress-backup';

// Worker mode (a child process).
if (($argv[1] ?? '') === '--worker') {
    $workerId   = (int) $argv[2];
    $iterations = (int) $argv[3];
    for ($i = 0; $i < $iterations; $i++) {
        Config::update(["ccurrent_{$workerId}_{$i}" => "value_{$workerId}_{$i}"]);
    }
    exit(0);
}

// --- Main test driver ---

echo "Config concurrency stress test\n";

if (!file_exists($configPath)) {
    echo "NOTICE: config.json does not exist. Creating minimal config for test.\n";
    file_put_contents($configPath, json_encode(['installed' => true]));
    $createdConfig = true;
} else {
    $createdConfig = false;
    copy($configPath, $backupPath);
    echo "Backed up config.json to config.json.stress-backup\n";
}

$WORKERS    = 8;
$ITERATIONS = 15;
$EXPECTED   = $WORKERS * $ITERATIONS;

echo "Spawning {$WORKERS} workers × {$ITERATIONS} iterations = {$EXPECTED} expected keys\n";

// Fork via proc_open: avoids pcntl dependency.
$procs = [];
for ($w = 0; $w < $WORKERS; $w++) {
    $cmd = sprintf(
        '%s %s --worker %d %d',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__FILE__),
        $w,
        $ITERATIONS
    );
    $procs[$w] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
}

// Wait for all.
foreach ($procs as $proc) {
    if (is_resource($proc)) {
        proc_close($proc);
    }
}

// Read final state.
Config::clearCache();
$final = Config::get();

$found = 0;
$missing = [];
for ($w = 0; $w < $WORKERS; $w++) {
    for ($i = 0; $i < $ITERATIONS; $i++) {
        $key = "ccurrent_{$w}_{$i}";
        if (isset($final[$key]) && $final[$key] === "value_{$w}_{$i}") {
            $found++;
        } else {
            $missing[] = $key;
        }
    }
}

// Restore backup.
if ($createdConfig) {
    unlink($configPath);
} else {
    rename($backupPath, $configPath);
}
echo "config.json restored from backup.\n\n";

echo "Result: {$found} / {$EXPECTED} keys survived.\n";
if ($found === $EXPECTED) {
    echo "All updates survived — no lost updates. PASS\n";
    exit(0);
}
$lost = $EXPECTED - $found;
echo "LOST UPDATES: {$lost} keys were overwritten.\n";
echo "Sample missing keys: " . implode(', ', array_slice($missing, 0, 5)) . "\n";
exit(1);
