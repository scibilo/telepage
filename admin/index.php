<?php
define('TELEPAGE_ROOT', dirname(__DIR__));
require_once __DIR__ . '/_auth.php';
require_once TELEPAGE_ROOT . '/app/TelegramBot.php';

$stats = [];
try {
    $stats = [
        'total'      => (int) DB::fetchScalar('SELECT COUNT(*) FROM contents WHERE is_deleted=0'),
        'deleted'    => (int) DB::fetchScalar('SELECT COUNT(*) FROM contents WHERE is_deleted=1'),
        'ai_pending' => (int) DB::fetchScalar('SELECT COUNT(*) FROM contents WHERE ai_processed=0 AND is_deleted=0'),
        'no_image'   => (int) DB::fetchScalar("SELECT COUNT(*) FROM contents WHERE (image IS NULL OR image='') AND is_deleted=0"),
        'tags'       => (int) DB::fetchScalar('SELECT COUNT(*) FROM tags'),
        'db_bytes'   => (int) (@filesize(Config::getKey('db_path', '')) ?: 0),
    ];
    $webhookInfo = TelegramBot::getWebhookInfo();
} catch (Throwable $e) {
    error_log('[TELEPAGE][DASHBOARD] ' . $e->getMessage());
    $stats = array_fill_keys(['total','deleted','ai_pending','no_image','tags','db_bytes'], 0);
    $webhookInfo = ['ok' => false];
}

$webhookOk = !empty($webhookInfo['result']['url']);
$dbSizeMb  = round($stats['db_bytes'] / 1024 / 1024, 2);

adminHeader('Dashboard', 'dashboard');
?>

<div class="grid-3">

<!-- CARD 1: System Status -->
<div class="card">
    <div class="card-title">📊 System Status</div>
    <div style="margin-bottom:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)">
            <span style="color:var(--muted)">Database</span>
            <span class="badge badge-success">Online <?= $dbSizeMb ?>MB</span>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)">
            <span style="color:var(--muted)">Active contents</span>
            <strong><?= $stats['total'] ?></strong>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)">
            <span style="color:var(--muted)">AI queue</span>
            <strong><?= $stats['ai_pending'] ?></strong>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)">
            <span style="color:var(--muted)">Without image</span>
            <strong><?= $stats['no_image'] ?></strong>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0">
            <span style="color:var(--muted)">Total tags</span>
            <strong><?= $stats['tags'] ?></strong>
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="settings.php" class="btn btn-outline btn-sm">⚙️ Settings</a>
        <a href="../index.php" class="btn btn-outline btn-sm" target="_blank">🌐 Site</a>
    </div>
</div>

<!-- CARD 2: Telegram -->
<div class="card">
    <div class="card-title">📡 Telegram Sync</div>
    <div style="margin-bottom:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)">
            <span style="color:var(--muted)">Webhook</span>
            <span class="badge <?= $webhookOk ? 'badge-success' : 'badge-error' ?>">
                <?= $webhookOk ? '✓ Active' : '✕ Not configured' ?>
            </span>
        </div>
        <?php if ($webhookOk): ?>
        <div style="padding:8px 0;border-bottom:1px solid var(--border);font-size:11px;color:var(--muted);word-break:break-all">
            <?= e($webhookInfo['result']['url'] ?? '') ?>
        </div>
        <?php else: ?>
        <div style="padding:8px 0;border-bottom:1px solid var(--border);font-size:11px;color:var(--warning)">
            ⚠️ Webhook not configured — click "Set Webhook" and make sure the site URL is correct in Settings.
        </div>
        <?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-primary btn-sm" onclick="adminAction('set_webhook')" id="btn-webhook">
            <?= $webhookOk ? '🔄 Verify' : '🔌 Set Webhook' ?>
        </button>
        <button class="btn btn-outline btn-sm" onclick="adminAction('sync_telegram')" id="btn-sync" title="Retrieves the latest unprocessed messages (max 100). For full history use History Scanner.">📥 Sync Now</button>
        <a href="import.php" class="btn btn-outline btn-sm">📂 Import JSON</a>
        <button class="btn btn-danger btn-sm" onclick="confirmReset()" id="btn-reset">🗑 Reset DB</button>
    </div>
</div>

<!-- CARD 3: Media & Tools -->
<div class="card">
    <div class="card-title">🔧 Media & Tools</div>
    <div style="margin-bottom:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)">
            <span style="color:var(--muted)">Without image</span>
            <strong><?= $stats['no_image'] ?></strong>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)">
            <span style="color:var(--muted)">AI queue pending</span>
            <strong><?= $stats['ai_pending'] ?></strong>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0">
            <span style="color:var(--muted)">Deleted (trash)</span>
            <strong><?= $stats['deleted'] ?></strong>
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-outline btn-sm" onclick="adminAction('fix_images', {limit:5})" id="btn-fix">🖼 Fix Images</button>
        <button class="btn btn-outline btn-sm" onclick="processAiAll()" id="btn-ai">🤖 Process AI</button>
        <button class="btn btn-outline btn-sm" onclick="checkAiModels()" id="btn-aimodels">🔍 Verify AI Models</button>
        <button class="btn btn-outline btn-sm" onclick="confirmRequeueAi()" id="btn-requeue" title="Re-queues all contents for AI processing (useful after enabling the AI key)">🔄 Re-queue AI</button>
        <button class="btn btn-outline btn-sm" onclick="adminAction('optimize')" id="btn-opt">⚡ Optimise DB</button>
        <button class="btn btn-outline btn-sm" onclick="adminAction('backup')" id="btn-bak">💾 Backup</button>
        <button class="btn btn-danger btn-sm" onclick="adminAction('cleanup')" id="btn-cln">🧹 Cleanup</button>
    </div>
</div>

</div><!-- /grid-3 -->

<!-- Operations console -->
<div class="card">
    <div class="card-title" style="justify-content:space-between">
        <span>💻 Operations Console</span>
        <button class="btn btn-outline btn-sm" onclick="clearConsole()">Clear</button>
    </div>
    <div class="console" id="console">
        <div class="console-line info">[<?= date('H:i:s') ?>] Dashboard loaded. Contents: <?= $stats['total'] ?> — AI queue: <?= $stats['ai_pending'] ?></div>
    </div>
</div>

<?php adminFooter(); ?>

<script>
function log(msg, type = 'info') {
    const c = document.getElementById('console');
    const t = new Date().toLocaleTimeString('en-GB', {hour12: false});
    const d = document.createElement('div');
    d.className = `console-line ${type}`;
    d.textContent = `[${t}] ${msg}`;
    c.appendChild(d);
    c.scrollTop = c.scrollHeight;
}

function clearConsole() {
    document.getElementById('console').innerHTML = '';
}

async function adminAction(action, extra = {}) {
    log(`Starting: ${action}...`);
    const btn = document.getElementById('btn-' + action.replace('_', '-').split('_')[0]);
    if (btn) btn.disabled = true;

    try {
        const res = await tpApi(action, {
            method: 'POST',
            body: { action, ...extra }
        });
        if (res.ok) {
            log(JSON.stringify(res.data, null, 0), 'ok');
        } else {
            log('Error: ' + res.error, 'err');
        }
    } catch(e) {
        log('Network error: ' + e.message, 'err');
    } finally {
        if (btn) btn.disabled = false;
    }
}

async function checkAiModels() {
    log('Checking available AI models...', 'info');
    const res = await tpApi('list_ai_models');
    if (!res.ok) {
        log('Error: ' + res.error, 'err');
        return;
    }
    for (const [ver, info] of Object.entries(res.data)) {
        const models = info.models_supporting_generateContent;
        if (models.length > 0) {
            log(ver + ': ' + models.join(', '), 'ok');
        } else {
            log(ver + ': HTTP ' + info.http_code + ' — ' + (info.error || 'no models found'), 'err');
        }
    }
}

async function processAiAll() {
    const btn = document.getElementById('btn-ai');
    btn.disabled = true;
    btn.textContent = '⏳ AI running...';
    log('Starting automatic AI processing...', 'info');

    let iteration = 0;
    const maxIterations = 50; // safety cap

    while (iteration < maxIterations) {
        iteration++;
        const res = await tpApi('process_ai_queue', {
            method: 'POST',
            body: {}
        });
        if (!res.ok) { log('Error: ' + res.error, 'err'); break; }

        const d = res.data;
        log(`Batch ${iteration}: ${d.processed} processed, ${d.remaining} remaining`, d.processed > 0 ? 'ok' : 'info');

        if (d.status === 'done' || d.remaining === 0) {
            log('✅ AI processing complete!', 'ok');
            break;
        }
        if (d.processed === 0 && d.remaining > 0) {
            log('⚠️ No progress — some contents may have errors.', 'warn');
            break;
        }

        await new Promise(r => setTimeout(r, 1000)); // 1s between batches
    }

    btn.disabled = false;
    btn.textContent = '🤖 Process AI';
}

async function confirmRequeueAi() {
    if (!confirm('Re-queue ALL contents for AI processing? Useful after enabling the AI API key.')) return;
    log('🔄 Re-queuing for AI...', 'warn');
    const res = await tpApi('requeue_ai', {
        method: 'POST',
        body: {}
    });
    if (res.ok) {
        log(`✅ ${res.data.requeued} contents queued for AI. Now use "Process AI" to process them.`, 'ok');
    } else {
        log('Error: ' + res.error, 'err');
    }
}

async function confirmReset() {
    const conf = prompt('Type RESET to confirm destruction of all data:');
    if (conf !== 'RESET') { log('Reset cancelled.', 'warn'); return; }
    log('⚠️ RESETTING DATABASE...', 'warn');
    const res = await tpApi('reset_database', {
        method: 'POST',
        body: { confirm: 'RESET' }
    });
    if (res.ok) {
        log('Database reset.', 'ok');
        location.reload();
    } else {
        log('Error: ' + res.error, 'err');
    }
}
</script>
