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

<!-- CARD 1: Stato Sistema -->
<div class="card">
    <div class="card-title">📊 Stato Sistema</div>
    <div style="margin-bottom:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)">
            <span style="color:var(--muted)">Database</span>
            <span class="badge badge-success">Online <?= $dbSizeMb ?>MB</span>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)">
            <span style="color:var(--muted)">Contenuti attivi</span>
            <strong><?= $stats['total'] ?></strong>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)">
            <span style="color:var(--muted)">Coda AI</span>
            <strong><?= $stats['ai_pending'] ?></strong>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)">
            <span style="color:var(--muted)">Senza immagine</span>
            <strong><?= $stats['no_image'] ?></strong>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0">
            <span style="color:var(--muted)">Tag totali</span>
            <strong><?= $stats['tags'] ?></strong>
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="settings.php" class="btn btn-outline btn-sm">⚙️ Impostazioni</a>
        <a href="../index.php" class="btn btn-outline btn-sm" target="_blank">🌐 Sito</a>
    </div>
</div>

<!-- CARD 2: Telegram -->
<div class="card">
    <div class="card-title">📡 Sincronizzazione Telegram</div>
    <div style="margin-bottom:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)">
            <span style="color:var(--muted)">Webhook</span>
            <span class="badge <?= $webhookOk ? 'badge-success' : 'badge-error' ?>">
                <?= $webhookOk ? '✓ Attivo' : '✕ Non configurato' ?>
            </span>
        </div>
        <?php if ($webhookOk): ?>
        <div style="padding:8px 0;border-bottom:1px solid var(--border);font-size:11px;color:var(--muted);word-break:break-all">
            <?= e($webhookInfo['result']['url'] ?? '') ?>
        </div>
        <?php else: ?>
        <div style="padding:8px 0;border-bottom:1px solid var(--border);font-size:11px;color:var(--warning)">
            ⚠️ Webhook non configurato — clicca "Imposta Webhook" e assicurati che l'URL del sito sia corretto nelle Impostazioni.
        </div>
        <?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-primary btn-sm" onclick="adminAction('set_webhook')" id="btn-webhook">
            <?= $webhookOk ? '🔄 Verifica' : '🔌 Imposta Webhook' ?>
        </button>
        <button class="btn btn-outline btn-sm" onclick="adminAction('sync_telegram')" id="btn-sync" title="Recupera gli ultimi messaggi non ancora ricevuti (max 100). Per lo storico usa History Scanner.">📥 Sync Ora</button>
        <a href="import.php" class="btn btn-outline btn-sm">📂 Importa JSON</a>
        <button class="btn btn-danger btn-sm" onclick="confirmReset()" id="btn-reset">🗑 Reset DB</button>
    </div>
</div>

<!-- CARD 3: Media & Strumenti -->
<div class="card">
    <div class="card-title">🔧 Media & Strumenti</div>
    <div style="margin-bottom:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)">
            <span style="color:var(--muted)">Senza immagine</span>
            <strong><?= $stats['no_image'] ?></strong>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)">
            <span style="color:var(--muted)">Coda AI da processare</span>
            <strong><?= $stats['ai_pending'] ?></strong>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0">
            <span style="color:var(--muted)">Eliminati (cestino)</span>
            <strong><?= $stats['deleted'] ?></strong>
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-outline btn-sm" onclick="adminAction('fix_images', {limit:5})" id="btn-fix">🖼 Fix Immagini</button>
        <button class="btn btn-outline btn-sm" onclick="processAiAll()" id="btn-ai">🤖 Process AI</button>
        <button class="btn btn-outline btn-sm" onclick="checkAiModels()" id="btn-aimodels">🔍 Verifica Modelli AI</button>
        <button class="btn btn-outline btn-sm" onclick="confirmRequeueAi()" id="btn-requeue" title="Rimette tutti i contenuti in coda AI (utile dopo aver abilitato l'AI)">🔄 Ri-accoda AI</button>
        <button class="btn btn-outline btn-sm" onclick="adminAction('optimize')" id="btn-opt">⚡ Ottimizza DB</button>
        <button class="btn btn-outline btn-sm" onclick="adminAction('backup')" id="btn-bak">💾 Backup</button>
        <button class="btn btn-danger btn-sm" onclick="adminAction('cleanup')" id="btn-cln">🧹 Cleanup</button>
    </div>
</div>

</div><!-- /grid-3 -->

<!-- Console operazioni -->
<div class="card">
    <div class="card-title" style="justify-content:space-between">
        <span>💻 Console Operazioni</span>
        <button class="btn btn-outline btn-sm" onclick="clearConsole()">Pulisci</button>
    </div>
    <div class="console" id="console">
        <div class="console-line info">[<?= date('H:i:s') ?>] Dashboard caricata. Contenuti: <?= $stats['total'] ?> — Coda AI: <?= $stats['ai_pending'] ?></div>
    </div>
</div>

<?php adminFooter(); ?>

<script>
const API = '../api/admin.php';
const CSRF = document.querySelector('meta[name=csrf]').content;

function log(msg, type = 'info') {
    const c = document.getElementById('console');
    const t = new Date().toLocaleTimeString('it-IT', {hour12: false});
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
    log(`Avvio: ${action}...`);
    const btn = document.getElementById('btn-' + action.replace('_', '-').split('_')[0]);
    if (btn) btn.disabled = true;

    try {
        const body = JSON.stringify({ action, ...extra });
        const res = await fetch(API + '?action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body
        });
        const data = await res.json();
        if (data.ok) {
            log(JSON.stringify(data.data, null, 0), 'ok');
        } else {
            log('Errore: ' + data.error, 'err');
        }
    } catch(e) {
        log('Errore di rete: ' + e.message, 'err');
    } finally {
        if (btn) btn.disabled = false;
    }
}

async function checkAiModels() {
    log('Verifica modelli AI disponibili...', 'info');
    try {
        const res = await fetch(API + '?action=list_ai_models', {headers: {'X-CSRF-Token': CSRF}});
        const data = await res.json();
        if (data.ok) {
            for (const [ver, info] of Object.entries(data.data)) {
                const models = info.models_supporting_generateContent;
                if (models.length > 0) {
                    log(ver + ': ' + models.join(', '), 'ok');
                } else {
                    log(ver + ': HTTP ' + info.http_code + ' — ' + (info.error || 'nessun modello trovato'), 'err');
                }
            }
        }
    } catch(e) { log('Errore: ' + e.message, 'err'); }
}

async function processAiAll() {
    const btn = document.getElementById('btn-ai');
    btn.disabled = true;
    btn.textContent = '⏳ AI in corso...';
    log('Avvio elaborazione AI automatica...', 'info');

    let iteration = 0;
    const maxIterations = 50; // sicurezza

    while (iteration < maxIterations) {
        iteration++;
        try {
            const res = await fetch(API + '?action=process_ai_queue', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: '{}'
            });
            const data = await res.json();
            if (!data.ok) { log('Errore: ' + data.error, 'err'); break; }

            const d = data.data;
            log(`Batch ${iteration}: ${d.processed} elaborati, ${d.remaining} rimanenti`, d.processed > 0 ? 'ok' : 'info');

            if (d.status === 'done' || d.remaining === 0) {
                log('✅ Elaborazione AI completata!', 'ok');
                break;
            }
            if (d.processed === 0 && d.remaining > 0) {
                log('⚠️ Nessun progresso — alcuni contenuti potrebbero avere errori.', 'warn');
                break;
            }

            await new Promise(r => setTimeout(r, 1000)); // 1s tra batch
        } catch(e) {
            log('Errore di rete: ' + e.message, 'err');
            break;
        }
    }

    btn.disabled = false;
    btn.textContent = '🤖 Process AI';
}

async function confirmRequeueAi() {
    if (!confirm('Rimettere TUTTI i contenuti in coda AI? Utile dopo aver abilitato le API key AI.')) return;
    log('🔄 Ri-accodamento AI in corso...', 'warn');
    try {
        const res = await fetch(API + '?action=requeue_ai', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: '{}'
        });
        const data = await res.json();
        if (data.ok) {
            log(`✅ ${data.data.requeued} contenuti messi in coda AI. Ora usa "Process AI" per elaborarli.`, 'ok');
        } else log('Errore: ' + data.error, 'err');
    } catch(e) { log('Errore: ' + e.message, 'err'); }
}

async function confirmReset() {
    const conf = prompt('Digita RESET per confermare la distruzione di tutti i dati:');
    if (conf !== 'RESET') { log('Reset annullato.', 'warn'); return; }
    log('⚠️ RESET DATABASE IN CORSO...', 'warn');
    try {
        const res = await fetch(API + '?action=reset_database', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ confirm: 'RESET' })
        });
        const data = await res.json();
        if (data.ok) { log('Database resettato.', 'ok'); location.reload(); }
        else log('Errore: ' + data.error, 'err');
    } catch(e) { log('Errore: ' + e.message, 'err'); }
}
</script>
