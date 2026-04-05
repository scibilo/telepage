<?php
define('TELEPAGE_ROOT', dirname(__DIR__));
require_once __DIR__ . '/_auth.php';

adminHeader('Import', 'import');
?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

<!-- SECTION A: JSON Import -->
<div class="card">
    <div class="card-title">📂 Import from JSON File</div>
    <p style="color:var(--muted);font-size:13px;margin-bottom:20px">
        Export messages from Telegram Desktop: <strong>Settings → Export chat data → JSON</strong>.
        Then upload the <code>result.json</code> file below.
    </p>

    <!-- Drop zone -->
    <div id="drop-zone" style="
        border: 2px dashed var(--border); border-radius: var(--radius);
        padding: 40px 20px; text-align: center; cursor: pointer; margin-bottom: 20px;
        transition: border-color .2s;
    " onclick="document.getElementById('json-file-input').click()">
        <div style="font-size: 36px; margin-bottom: 12px">📄</div>
        <div style="font-weight: 600; margin-bottom: 6px">Drag result.json here</div>
        <div style="color: var(--muted); font-size: 13px">or click to select</div>
        <input type="file" id="json-file-input" accept=".json" style="display:none">
    </div>

    <!-- Preview -->
    <div id="preview" style="display:none;margin-bottom:20px">
        <div style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:16px">
            <div style="font-weight:600;margin-bottom:12px">📊 File preview</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px">
                <div><span style="color:var(--muted)">Total messages:</span> <strong id="prev-total">—</strong></div>
                <div><span style="color:var(--muted)">First message date:</span> <strong id="prev-from">—</strong></div>
                <div><span style="color:var(--muted)">Messages with URL:</span> <strong id="prev-urls">—</strong></div>
                <div><span style="color:var(--muted)">Last message date:</span> <strong id="prev-to">—</strong></div>
            </div>
        </div>
    </div>

    <!-- Date filters -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px" id="date-filters" style="display:none">
        <div>
            <label style="display:block;font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px">From date</label>
            <input type="date" id="date-from" style="width:100%;padding:10px 12px;background:var(--bg2);border:1px solid var(--border);border-radius:7px;color:var(--text);font-family:inherit;font-size:14px;outline:none">
        </div>
        <div>
            <label style="display:block;font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px">To date</label>
            <input type="date" id="date-to" style="width:100%;padding:10px 12px;background:var(--bg2);border:1px solid var(--border);border-radius:7px;color:var(--text);font-family:inherit;font-size:14px;outline:none">
        </div>
    </div>

    <button class="btn btn-primary" id="btn-start-import" onclick="startImport()" disabled style="width:100%;justify-content:center">
        🚀 Start Import
    </button>
</div>

<!-- SECTION B: Quick Sync + Log -->
<div style="display:flex;flex-direction:column;gap:20px">
    <div class="card">
        <div class="card-title">⚡ Quick Sync</div>
        <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:8px;padding:12px 16px;font-size:13px;color:#fcd34d;margin-bottom:16px">
            ⚠️ Only retrieves the latest unsynchronised messages — not the full history.
        </div>
        <button class="btn btn-outline" style="width:100%;justify-content:center" onclick="syncTelegram()">
            📡 Sync via getUpdates
        </button>
    </div>

    <!-- Progress bar -->
    <div class="card" id="progress-card" style="display:none">
        <div class="card-title">📊 Import Progress</div>
        <div style="margin-bottom:12px">
            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px">
                <span id="prog-label">0 / 0 imported</span>
                <span id="prog-pct">0%</span>
            </div>
            <div style="background:var(--border);border-radius:4px;height:8px;overflow:hidden">
                <div id="prog-bar" style="height:100%;background:var(--accent);border-radius:4px;width:0%;transition:width .3s"></div>
            </div>
        </div>
    </div>

    <!-- Console log -->
    <div class="card" style="flex:1">
        <div class="card-title" style="justify-content:space-between">
            <span>💻 Import Log</span>
            <button class="btn btn-outline btn-sm" onclick="clearLog()">Clear</button>
        </div>
        <div class="console" id="import-log" style="height:220px">
            <div class="console-line info">Waiting...</div>
        </div>
    </div>
</div>

</div><!-- /grid -->

<?php adminFooter(); ?>

<script>
let parsedMessages = null;
let importPollTimer = null;

const dropZone = document.getElementById('drop-zone');
const fileInput = document.getElementById('json-file-input');
const API = '../api/admin.php';

// Drag & drop
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.style.borderColor = 'var(--accent)'; });
dropZone.addEventListener('dragleave', () => { dropZone.style.borderColor = 'var(--border)'; });
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.style.borderColor = 'var(--border)';
    const file = e.dataTransfer.files[0];
    if (file) handleFile(file);
});

fileInput.addEventListener('change', () => {
    if (fileInput.files[0]) handleFile(fileInput.files[0]);
});

function handleFile(file) {
    log(`File received: ${file.name} (${(file.size/1024).toFixed(0)} KB)`);
    const reader = new FileReader();
    reader.onload = (e) => {
        try {
            const data = JSON.parse(e.target.result);
            if (!data.messages) { log('❌ Invalid file: missing messages field', 'err'); return; }

            parsedMessages = data.messages;

            // Preview
            const dates = parsedMessages.map(m => new Date((m.date_unixtime || 0) * 1000)).filter(d => d > new Date(0));
            dates.sort((a,b)=>a-b);
            const withUrl = parsedMessages.filter(m => {
                const t = Array.isArray(m.text) ? m.text.map(s=>typeof s==='string'?s:s.text||'').join('') : (m.text||'');
                return /https?:\/\//.test(t) || m.photo || m.media_type;
            }).length;

            document.getElementById('prev-total').textContent = parsedMessages.length;
            document.getElementById('prev-urls').textContent  = withUrl;
            document.getElementById('prev-from').textContent  = dates[0] ? dates[0].toLocaleDateString('en') : '—';
            document.getElementById('prev-to').textContent    = dates.at(-1) ? dates.at(-1).toLocaleDateString('en') : '—';

            if (dates[0]) document.getElementById('date-from').value = dates[0].toISOString().slice(0,10);
            if (dates.at(-1)) document.getElementById('date-to').value = dates.at(-1).toISOString().slice(0,10);

            document.getElementById('preview').style.display = 'block';
            document.getElementById('date-filters').style.display = 'grid';
            document.getElementById('btn-start-import').disabled = false;
            log(`✅ File read: ${parsedMessages.length} messages found`, 'ok');
        } catch(err) {
            log('❌ Invalid JSON: ' + err.message, 'err');
        }
    };
    reader.readAsText(file);
}

async function startImport() {
    if (!parsedMessages) return;
    document.getElementById('btn-start-import').disabled = true;
    document.getElementById('progress-card').style.display = 'block';

    const dateFrom = document.getElementById('date-from').value;
    const dateTo   = document.getElementById('date-to').value;

    const formData = new FormData();
    const blob = new Blob([JSON.stringify({ messages: parsedMessages })], {type:'application/json'});
    formData.append('json_file', blob, 'result.json');
    formData.append('date_from', dateFrom);
    formData.append('date_to', dateTo);

    log('🚀 Starting import...');
    try {
        const res = await fetch(API + '?action=import_json', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.ok) {
            log(`Import started: ${data.data.total_found} messages in period — ${data.data.total_imported} imported so far`, 'ok');
            startPolling();
        } else {
            log('❌ ' + data.error, 'err');
            document.getElementById('btn-start-import').disabled = false;
        }
    } catch(e) {
        log('❌ Error: ' + e.message, 'err');
        document.getElementById('btn-start-import').disabled = false;
    }
}

function startPolling() {
    importPollTimer = setInterval(async () => {
        const res = await fetch(API + '?action=import_status');
        const data = await res.json();
        if (!data.ok) return;
        const s = data.data;

        document.getElementById('prog-label').textContent = `${s.imported} / ${s.total} imported`;
        document.getElementById('prog-pct').textContent   = s.progress + '%';
        document.getElementById('prog-bar').style.width   = s.progress + '%';

        if (s.status === 'done') {
            clearInterval(importPollTimer);
            log(`✅ Import complete: ${s.imported}/${s.total} messages imported`, 'ok');
            document.getElementById('btn-start-import').disabled = false;
        }
    }, 2000);
}

async function syncTelegram() {
    log('⏳ Telegram sync started...');
    try {
        const res = await fetch(API + '?action=sync_telegram', { method: 'POST', headers: {'Content-Type':'application/json'}, body: '{}' });
        const data = await res.json();
        if (data.ok) log(`✅ Sync complete: ${data.data.processed} messages processed`, 'ok');
        else log('❌ ' + data.error, 'err');
    } catch(e) {
        log('❌ ' + e.message, 'err');
    }
}

function log(msg, type='info') {
    const c = document.getElementById('import-log');
    const t = new Date().toLocaleTimeString('en-GB', {hour12:false});
    const d = document.createElement('div');
    d.className = `console-line ${type}`;
    d.textContent = `[${t}] ${msg}`;
    c.appendChild(d);
    c.scrollTop = c.scrollHeight;
}

function clearLog() {
    document.getElementById('import-log').innerHTML = '';
}
</script>
