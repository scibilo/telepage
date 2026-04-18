<?php
define('TELEPAGE_ROOT', dirname(__DIR__));
require_once __DIR__ . '/_auth.php';
require_once TELEPAGE_ROOT . '/app/HistoryScanner.php';

$stats = HistoryScanner::getStats();

adminHeader('History Scanner', 'import');
?>

<div class="card">
    <div class="card-title">📜 History Scanner — Historical Message Import</div>
    <p style="color:var(--muted);margin-bottom:20px;font-size:13px;">
        Retrieves past messages from the Telegram channel by scanning backwards from the most recent ID.
        The bot must be an admin of the channel (standard requirement to have a token).
        Each batch retrieves up to 50 messages — click "Start Batch" as many times as needed to cover the full history.
    </p>

    <!-- DB statistics -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px">
        <div style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:16px;text-align:center">
            <div style="font-size:28px;font-weight:700;color:var(--accent)" id="stat-total"><?= $stats['total_contents'] ?></div>
            <div style="font-size:11px;color:var(--muted);margin-top:4px">Contents in DB</div>
        </div>
        <div style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:16px;text-align:center">
            <div style="font-size:28px;font-weight:700;color:var(--success)" id="stat-imported">0</div>
            <div style="font-size:11px;color:var(--muted);margin-top:4px">Imported now</div>
        </div>
        <div style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:16px;text-align:center">
            <div style="font-size:28px;font-weight:700;color:var(--muted)" id="stat-skipped">0</div>
            <div style="font-size:11px;color:var(--muted);margin-top:4px">Already present</div>
        </div>
        <div style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:16px;text-align:center">
            <div style="font-size:28px;font-weight:700;color:var(--text)" id="stat-current-id">—</div>
            <div style="font-size:11px;color:var(--muted);margin-top:4px">Current message ID</div>
        </div>
    </div>

    <!-- Channel ID range -->
    <?php if ($stats['min_message_id'] || $stats['max_message_id']): ?>
    <div style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:12px;color:var(--muted)">
        📊 DB range: ID <strong style="color:var(--text)"><?= (int)$stats['min_message_id'] ?></strong>
        → <strong style="color:var(--text)"><?= (int)$stats['max_message_id'] ?></strong>
        (<?= $stats['with_telegram_id'] ?> messages with Telegram ID)
    </div>
    <?php endif; ?>

    <!-- Controls -->
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:20px">
        <div style="display:flex;align-items:center;gap:8px;background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:6px 12px">
            <label style="font-size:11px;color:var(--muted);white-space:nowrap">Start from ID:</label>
            <input type="number" id="manual-start-id" min="1"
                   style="width:100px;background:transparent;border:none;color:var(--text);font-size:13px;font-weight:600;outline:none"
                   placeholder="auto" oninput="if(this.value) currentStartId=parseInt(this.value)">
        </div>
        <button class="btn btn-primary" id="btn-start" onclick="startScan()">
            🚀 Start Batch (50 messages)
        </button>
        <button class="btn btn-outline" id="btn-auto" onclick="toggleAuto()">
            ▶️ Auto Mode
        </button>
        <button class="btn btn-outline" id="btn-stop" onclick="stopScan()" style="display:none">
            ⏸ Stop
        </button>
        <div style="margin-left:auto;font-size:12px;color:var(--muted)" id="status-text">
            Ready. Click "Start Batch" to begin.
        </div>
    </div>

    <!-- Progress bar -->
    <div style="background:var(--bg2);border-radius:6px;height:6px;margin-bottom:20px;overflow:hidden">
        <div id="progress-bar" style="height:100%;width:0%;background:var(--accent);transition:width .3s"></div>
    </div>

    <!-- Messages imported in last batch -->
    <div id="batch-results" style="display:none;margin-bottom:20px">
        <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em">Last imported</div>
        <div id="batch-list" style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;max-height:160px;overflow-y:auto;font-size:12px"></div>
    </div>
</div>

<!-- Console -->
<div class="card">
    <div class="card-title" style="justify-content:space-between">
        <span>💻 Console</span>
        <button class="btn btn-outline btn-sm" onclick="clearConsole()">Clear</button>
    </div>
    <div class="console" id="console">
        <div class="console-line info">[<?= date('H:i:s') ?>] History Scanner ready. Contents in DB: <?= $stats['total_contents'] ?></div>
    </div>
</div>

<script>
let currentStartId  = null;
let totalImported   = 0;
let totalSkipped    = 0;
let totalErrors     = 0;
let isScanning      = false;
let autoMode        = false;
let autoTimer       = null;

// ── Init ──────────────────────────────────────────────────────────────────
async function init() {
    try {
        const res  = await apiFetch('scan_get_start');
        currentStartId = res.suggested_start;
        document.getElementById('stat-current-id').textContent = currentStartId;
        log(`Channel detected: latest ID ~${currentStartId}`, 'info');
        if (res.gap > 0) {
            log(`Gap to cover: ~${res.gap} message IDs to scan`, 'warn');
        }
    } catch (e) {
        log('Init error: ' + e.message, 'err');
    }
}

// ── Single Batch ──────────────────────────────────────────────────────────
async function startScan() {
    if (isScanning || !currentStartId || currentStartId <= 0) return;

    isScanning = true;
    setStatus('Scanning…');
    document.getElementById('btn-start').disabled = true;
    document.getElementById('progress-bar').style.width = '30%';

    log(`Starting batch from ID ${currentStartId}…`, 'info');

    try {
        const res = await apiFetch('scan_batch', { start_id: currentStartId, batch_size: 50 });

        totalImported += res.imported;
        totalSkipped  += res.skipped;
        totalErrors   += res.errors;

        document.getElementById('stat-imported').textContent  = totalImported;
        document.getElementById('stat-skipped').textContent   = totalSkipped;
        document.getElementById('stat-current-id').textContent = res.next_start_id;

        // Update DB total
        const statsRes = await apiFetch('scan_stats');
        document.getElementById('stat-total').textContent = statsRes.total_contents;

        // Log result
        log(
            `✅ Batch: ${res.imported} imported, ${res.skipped} already present, ${res.errors} errors — ${res.attempts} attempts`,
            res.imported > 0 ? 'ok' : 'info'
        );

        // Show imported messages
        if (res.messages?.length > 0) {
            showBatchResults(res.messages);
        }

        currentStartId = res.next_start_id;
        document.getElementById('progress-bar').style.width = '100%';

        // Run AI queue as a separate async call so it doesn't race against
        // the scan's set_time_limit. Do this before deciding has_more so the
        // user sees tags appear immediately after each batch.
        if (res.ai_pending > 0) {
            await runAiQueueAfterScan(res.ai_pending);
        }

        if (!res.has_more || currentStartId <= 0) {
            log('🎉 Scan complete. No more messages to retrieve.', 'ok');
            setStatus('Complete.');
            stopAuto();
        } else {
            setStatus(`Ready for next batch. Next ID: ${currentStartId}`);
            if (autoMode) scheduleNext();
        }

    } catch (e) {
        log('❌ Error: ' + e.message, 'err');
        setStatus('Error — please retry.');
        stopAuto();
    } finally {
        isScanning = false;
        document.getElementById('btn-start').disabled = false;
        document.getElementById('progress-bar').style.width = '0%';
    }
}

// ── AI queue loop (runs after each scan batch, separate HTTP calls) ───────
async function runAiQueueAfterScan(initialPending) {
    log(`🤖 AI tagging ${initialPending} pending content…`, 'info');
    let iterations = 0;
    const maxIter  = 20; // safety cap

    while (iterations < maxIter) {
        iterations++;
        try {
            const res = await apiFetch('process_ai_queue', {}, 'POST');
            if (!res.ok && res.error) { log('⚠️ AI error: ' + res.error, 'warn'); break; }
            const d = res;
            if (d.processed > 0) {
                log(`🤖 AI batch ${iterations}: ${d.processed} tagged, ${d.remaining} remaining`, 'ok');
            }
            if (d.status === 'done' || d.remaining === 0) {
                log('✅ AI tagging complete.', 'ok');
                break;
            }
            if (d.processed === 0 && d.remaining > 0) {
                log('⚠️ AI: no progress, some contents may have errors.', 'warn');
                break;
            }
            await new Promise(r => setTimeout(r, 500));
        } catch (e) {
            log('⚠️ AI queue error: ' + e.message, 'warn');
            break;
        }
    }
}

// ── Auto Mode ─────────────────────────────────────────────────────────────
function toggleAuto() {
    if (autoMode) {
        stopAuto();
    } else {
        autoMode = true;
        document.getElementById('btn-auto').textContent  = '⏸ Stop Auto';
        document.getElementById('btn-auto').className    = 'btn btn-danger';
        document.getElementById('btn-stop').style.display = 'inline-flex';
        log('Auto mode enabled — continuous batches every 2s.', 'warn');
        startScan();
    }
}

function stopAuto() {
    autoMode = false;
    clearTimeout(autoTimer);
    document.getElementById('btn-auto').textContent = '▶️ Auto Mode';
    document.getElementById('btn-auto').className   = 'btn btn-outline';
    document.getElementById('btn-stop').style.display = 'none';
}

function stopScan() {
    stopAuto();
    log('⏸ Scan stopped by user.', 'warn');
    setStatus('Stopped.');
}

function scheduleNext() {
    autoTimer = setTimeout(() => {
        if (autoMode && !isScanning) startScan();
    }, 2000);
}

// ── UI helpers ────────────────────────────────────────────────────────────
function showBatchResults(messages) {
    const wrapper = document.getElementById('batch-results');
    const list    = document.getElementById('batch-list');
    wrapper.style.display = 'block';
    list.innerHTML = '';
    messages.slice(0, 20).forEach(m => {
        const row = document.createElement('div');
        row.style.cssText = 'padding:8px 12px;border-bottom:1px solid var(--border)';
        const title = (m.title || m.url || '').substring(0, 70);
        row.innerHTML = `<span style="color:var(--muted)">#${m.id}</span> <span style="color:var(--text)">${title}</span>`;
        list.appendChild(row);
    });
}

function log(msg, type = 'info') {
    const c = document.getElementById('console');
    const t = new Date().toLocaleTimeString('en-GB', { hour12: false });
    const d = document.createElement('div');
    d.className = `console-line ${type}`;
    d.textContent = `[${t}] ${msg}`;
    c.appendChild(d);
    c.scrollTop = c.scrollHeight;
}

function clearConsole() {
    document.getElementById('console').innerHTML = '';
}

function setStatus(txt) {
    document.getElementById('status-text').textContent = txt;
}

// ── API fetch helper ──────────────────────────────────────────────────────
// Thin wrapper over tpApi that preserves the original calling convention:
//   apiFetch(action)          → GET
//   apiFetch(action, body)    → POST with JSON body
// Throws on {ok:false} so callers can keep using try/catch.
async function apiFetch(action, body = null) {
    const res = body
        ? await tpApi(action, { method: 'POST', body: body })
        : await tpApi(action);
    if (!res.ok) throw new Error(res.error || 'API error');
    return res.data;
}

// ── Boot ──────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', init);
</script>

<?php adminFooter(); ?>
