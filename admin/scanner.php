<?php
define('TELEPAGE_ROOT', dirname(__DIR__));
require_once __DIR__ . '/_auth.php';
require_once TELEPAGE_ROOT . '/app/HistoryScanner.php';

$stats = HistoryScanner::getStats();

adminHeader('History Scanner', 'import');
?>

<div class="card">
    <div class="card-title">📜 History Scanner — Recupero Messaggi Storici</div>
    <p style="color:var(--muted);margin-bottom:20px;font-size:13px;">
        Recupera i messaggi passati del canale Telegram scansionando a ritroso dall'ID più recente.
        Il bot deve essere admin del canale (requisito standard per avere il token).
        Ogni batch recupera fino a 50 messaggi — clicca "Avvia Batch" quante volte vuoi per coprire tutta la storia.
    </p>

    <!-- Statistiche DB -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px">
        <div style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:16px;text-align:center">
            <div style="font-size:28px;font-weight:700;color:var(--accent)" id="stat-total"><?= $stats['total_contents'] ?></div>
            <div style="font-size:11px;color:var(--muted);margin-top:4px">Contenuti nel DB</div>
        </div>
        <div style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:16px;text-align:center">
            <div style="font-size:28px;font-weight:700;color:var(--success)" id="stat-imported">0</div>
            <div style="font-size:11px;color:var(--muted);margin-top:4px">Importati ora</div>
        </div>
        <div style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:16px;text-align:center">
            <div style="font-size:28px;font-weight:700;color:var(--muted)" id="stat-skipped">0</div>
            <div style="font-size:11px;color:var(--muted);margin-top:4px">Già presenti</div>
        </div>
        <div style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:16px;text-align:center">
            <div style="font-size:28px;font-weight:700;color:var(--text)" id="stat-current-id">—</div>
            <div style="font-size:11px;color:var(--muted);margin-top:4px">Message ID corrente</div>
        </div>
    </div>

    <!-- Range ID canale -->
    <?php if ($stats['min_message_id'] || $stats['max_message_id']): ?>
    <div style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:12px;color:var(--muted)">
        📊 Range nel DB: ID <strong style="color:var(--text)"><?= (int)$stats['min_message_id'] ?></strong>
        → <strong style="color:var(--text)"><?= (int)$stats['max_message_id'] ?></strong>
        (<?= $stats['with_telegram_id'] ?> messaggi con ID Telegram)
    </div>
    <?php endif; ?>

    <!-- Controlli -->
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:20px">
        <div style="display:flex;align-items:center;gap:8px;background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:6px 12px">
            <label style="font-size:11px;color:var(--muted);white-space:nowrap">Parti da ID:</label>
            <input type="number" id="manual-start-id" min="1"
                   style="width:100px;background:transparent;border:none;color:var(--text);font-size:13px;font-weight:600;outline:none"
                   placeholder="auto" oninput="if(this.value) currentStartId=parseInt(this.value)">
        </div>
        <button class="btn btn-primary" id="btn-start" onclick="startScan()">
            🚀 Avvia Batch (50 messaggi)
        </button>
        <button class="btn btn-outline" id="btn-auto" onclick="toggleAuto()">
            ▶️ Modalità Automatica
        </button>
        <button class="btn btn-outline" id="btn-stop" onclick="stopScan()" style="display:none">
            ⏸ Stop
        </button>
        <div style="margin-left:auto;font-size:12px;color:var(--muted)" id="status-text">
            Pronto. Clicca "Avvia Batch" per iniziare.
        </div>
    </div>

    <!-- Progress bar -->
    <div style="background:var(--bg2);border-radius:6px;height:6px;margin-bottom:20px;overflow:hidden">
        <div id="progress-bar" style="height:100%;width:0%;background:var(--accent);transition:width .3s"></div>
    </div>

    <!-- Messaggi importati nell'ultimo batch -->
    <div id="batch-results" style="display:none;margin-bottom:20px">
        <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em">Ultimi importati</div>
        <div id="batch-list" style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;max-height:160px;overflow-y:auto;font-size:12px"></div>
    </div>
</div>

<!-- Console -->
<div class="card">
    <div class="card-title" style="justify-content:space-between">
        <span>💻 Console</span>
        <button class="btn btn-outline btn-sm" onclick="clearConsole()">Pulisci</button>
    </div>
    <div class="console" id="console">
        <div class="console-line info">[<?= date('H:i:s') ?>] History Scanner pronto. Contenuti nel DB: <?= $stats['total_contents'] ?></div>
    </div>
</div>

<script>
const API  = '../api/admin.php';
const CSRF = document.querySelector('meta[name=csrf]').content;

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
        log(`Canale rilevato: ID più recente ~${currentStartId}`, 'info');
        if (res.gap > 0) {
            log(`Gap da coprire: ~${res.gap} message ID da scansionare`, 'warn');
        }
    } catch (e) {
        log('Errore init: ' + e.message, 'err');
    }
}

// ── Singolo Batch ─────────────────────────────────────────────────────────
async function startScan() {
    if (isScanning || !currentStartId || currentStartId <= 0) return;

    isScanning = true;
    setStatus('Scansione in corso…');
    document.getElementById('btn-start').disabled = true;
    document.getElementById('progress-bar').style.width = '30%';

    log(`Avvio batch da ID ${currentStartId}…`, 'info');

    try {
        const res = await apiFetch('scan_batch', { start_id: currentStartId, batch_size: 50 });

        totalImported += res.imported;
        totalSkipped  += res.skipped;
        totalErrors   += res.errors;

        document.getElementById('stat-imported').textContent  = totalImported;
        document.getElementById('stat-skipped').textContent   = totalSkipped;
        document.getElementById('stat-current-id').textContent = res.next_start_id;

        // Aggiorna totale DB
        const statsRes = await apiFetch('scan_stats');
        document.getElementById('stat-total').textContent = statsRes.total_contents;

        // Log risultato
        log(
            `✅ Batch: ${res.imported} importati, ${res.skipped} già presenti, ${res.errors} errori — ${res.attempts} tentativi`,
            res.imported > 0 ? 'ok' : 'info'
        );

        // Mostra messaggi importati
        if (res.messages?.length > 0) {
            showBatchResults(res.messages);
        }

        currentStartId = res.next_start_id;
        document.getElementById('progress-bar').style.width = '100%';

        if (!res.has_more || currentStartId <= 0) {
            log('🎉 Scansione completa. Nessun altro messaggio da recuperare.', 'ok');
            setStatus('Completato.');
            stopAuto();
        } else {
            setStatus(`Pronto per il prossimo batch. Prossimo ID: ${currentStartId}`);
            if (autoMode) scheduleNext();
        }

    } catch (e) {
        log('❌ Errore: ' + e.message, 'err');
        setStatus('Errore — riprova.');
        stopAuto();
    } finally {
        isScanning = false;
        document.getElementById('btn-start').disabled = false;
        document.getElementById('progress-bar').style.width = '0%';
    }
}

// ── Modalità Automatica ───────────────────────────────────────────────────
function toggleAuto() {
    if (autoMode) {
        stopAuto();
    } else {
        autoMode = true;
        document.getElementById('btn-auto').textContent  = '⏸ Stop Auto';
        document.getElementById('btn-auto').className    = 'btn btn-danger';
        document.getElementById('btn-stop').style.display = 'inline-flex';
        log('Modalità automatica attivata — batch continui ogni 2s.', 'warn');
        startScan();
    }
}

function stopAuto() {
    autoMode = false;
    clearTimeout(autoTimer);
    document.getElementById('btn-auto').textContent = '▶️ Modalità Automatica';
    document.getElementById('btn-auto').className   = 'btn btn-outline';
    document.getElementById('btn-stop').style.display = 'none';
}

function stopScan() {
    stopAuto();
    log('⏸ Scansione interrotta dall\'utente.', 'warn');
    setStatus('Interrotto.');
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
    const t = new Date().toLocaleTimeString('it-IT', { hour12: false });
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
async function apiFetch(action, body = null) {
    const url = API + '?action=' + action;
    const opts = {
        method:  body ? 'POST' : 'GET',
        headers: { 'X-CSRF-Token': CSRF },
    };
    if (body) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
    }
    const res  = await fetch(url, opts);
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'API error');
    return data.data;
}

// ── Boot ──────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', init);
</script>

<?php adminFooter(); ?>
