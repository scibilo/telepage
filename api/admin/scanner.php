<?php

/**
 * TELEPAGE — api/admin/scanner.php
 *
 * Admin API module: history scanner.
 * Included by api/admin.php after auth, CSRF, and rate-limit checks.
 *
 * Actions: scan_get_start, scan_batch, scan_stats
 */

declare(strict_types=1);

match ($action) {
    'scan_get_start' => actionScanGetStart(),
    'scan_batch'     => actionScanBatch(),
    'scan_stats'     => actionScanStats(),
    default          => jsonError(400, "Unknown action: {$action}"),
};

// -----------------------------------------------------------------------

/** GET /api/admin.php?action=scan_get_start */
function actionScanGetStart(): void
{
    jsonOk(HistoryScanner::getStartId());
}

/** POST /api/admin.php?action=scan_batch  body: {start_id, batch_size} */
function actionScanBatch(): void
{
    requirePost();

    set_time_limit(300); // 5 min — enough for 2000 IDs with rate-limit pauses

    $body      = getJsonBody();
    $startId   = (int) ($body['start_id']   ?? 0);
    $batchSize = min(100, max(10, (int) ($body['batch_size'] ?? 50)));

    if ($startId <= 0) {
        jsonError(400, 'Invalid start_id');
    }

    $result = HistoryScanner::scanBatch($startId, $batchSize);

    // Tell the UI how many contents are pending AI so it can auto-trigger
    // the AI queue as a separate async call (avoids set_time_limit exhaustion
    // when Gemini calls are stacked right after a long scan).
    $config = Config::get();
    $result['ai_pending'] = 0;
    if (!empty($config['ai_enabled']) && (!empty($config['ai_auto_tag']) || !empty($config['ai_auto_summary']))) {
        $result['ai_pending'] = (int) DB::fetchScalar(
            'SELECT COUNT(*) FROM contents WHERE ai_processed=0 AND is_deleted=0'
        );
    }

    Logger::admin(Logger::INFO, 'scan_batch', [
        'start'      => $startId,
        'imported'   => $result['imported'],
        'skipped'    => $result['skipped'],
        'ai_pending' => $result['ai_pending'],
    ]);
    jsonOk($result);
}

/** GET /api/admin.php?action=scan_stats */
function actionScanStats(): void
{
    jsonOk(HistoryScanner::getStats());
}
