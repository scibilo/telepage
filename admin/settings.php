<?php
define('TELEPAGE_ROOT', dirname(__DIR__));
require_once __DIR__ . '/_auth.php';

$config = Config::get();
adminHeader('Settings', 'settings');
?>

<div style="max-width: 800px">
    <form id="settings-form" onsubmit="saveSettings(event)">
            <div class="grid-3" style="grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
                <div class="form-group">
                    <label class="form-label">Logo / Favicon</label>
                    <div style="display: flex; gap: 15px; align-items: center; background: #0a0f1e; padding: 10px; border-radius: 8px; border: 1px dashed var(--border);">
                        <img id="logo-preview" src="../<?= e($config['logo_path'] ?? 'assets/img/logo.png') ?>" alt="Logo" style="height: 40px; border-radius: 4px; background: #222;">
                        <input type="file" id="logo-file" accept="image/*" style="display:none" onchange="uploadLogo(event)">
                        <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('logo-file').click()">📷 Upload New</button>
                    </div>
                    <p class="form-hint">Will be resized and saved automatically.</p>
                </div>
                <div class="form-group">
                    <label class="form-label">Logo Path (URL)</label>
                    <input type="text" name="logo_path" class="form-control" value="<?= e($config['logo_path'] ?? 'assets/img/logo.png') ?>">
                    <p class="form-hint">Example: <code>assets/img/logo.png</code></p>
                </div>
            </div>
        </div>

        <div class="card" style="margin-bottom:20px">
            <div class="card-title">🎨 Visual Theme</div>

            <div class="form-group">
                <label class="form-label">Background</label>
                <?php
                $themes = [
                    'dark'   => ['label'=>'Dark (default)', 'bg'=>'#030712', 'accent'=>'#3b82f6'],
                    'ocean'  => ['label'=>'Ocean',          'bg'=>'#020f1a', 'accent'=>'#06b6d4'],
                    'forest' => ['label'=>'Forest',         'bg'=>'#020d06', 'accent'=>'#10b981'],
                    'sunset' => ['label'=>'Sunset',         'bg'=>'#130a02', 'accent'=>'#f97316'],
                    'rose'   => ['label'=>'Rose',           'bg'=>'#130208', 'accent'=>'#ec4899'],
                    'slate'  => ['label'=>'Slate',          'bg'=>'#0a0b0f', 'accent'=>'#64748b'],
                ];
                $currentTheme = $config['site_theme'] ?? 'dark';
                $currentColor = $config['theme_color'] ?? '#3b82f6';
                ?>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
                    <?php foreach($themes as $key => $t): ?>
                    <button type="button" onclick="selectTheme('<?= $key ?>','<?= $t['accent'] ?>')"
                            id="theme-btn-<?= $key ?>"
                            style="padding:8px 14px;border-radius:8px;border:2px solid <?= $currentTheme===$key ? $t['accent'] : 'rgba(255,255,255,.1)' ?>;background:<?= $t['bg'] ?>;color:#fff;cursor:pointer;font-size:12px;font-weight:600;transition:.2s">
                        <?= $t['label'] ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="site_theme" id="site_theme_input" value="<?= e($currentTheme) ?>">
            </div>

            <div class="form-group">
                <label class="form-label">Accent Colour</label>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                    <input type="color" name="theme_color" id="theme_color_picker"
                           value="<?= e($currentColor) ?>"
                           style="width:48px;height:40px;padding:2px;border:1px solid var(--border);border-radius:8px;cursor:pointer;background:transparent"
                           oninput="syncColor(this.value)">
                    <input type="text" id="theme_color_text" class="form-control" style="width:110px"
                           value="<?= e($currentColor) ?>"
                           oninput="if(this.value.match(/^#[0-9a-f]{6}$/i)) syncColor(this.value)">
                    <div id="color_preview" style="width:40px;height:40px;border-radius:8px;background:<?= e($currentColor) ?>"></div>
                </div>
                <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
                    <?php foreach(['#3b82f6','#06b6d4','#10b981','#f97316','#ec4899','#8b5cf6','#f59e0b','#ef4444','#64748b','#1d4ed8','#dc2626','#16a34a'] as $c): ?>
                    <button type="button" onclick="syncColor('<?= $c ?>')"
                            style="width:28px;height:28px;border-radius:50%;background:<?= $c ?>;border:2px solid rgba(255,255,255,.15);cursor:pointer;transition:.2s"
                            onmouseover="this.style.transform='scale(1.2)'" onmouseout="this.style.transform='scale(1)'"
                            title="<?= $c ?>"></button>
                    <?php endforeach; ?>
                </div>
                <p class="form-hint" style="margin-top:8px">Or choose any colour with the picker. Change it for holidays or rebranding.</p>
            </div>
        </div>

        <script>
        function syncColor(val) {
            document.getElementById('theme_color_picker').value = val;
            document.getElementById('theme_color_text').value = val;
            document.getElementById('color_preview').style.background = val;
        }
        function selectTheme(key, accent) {
            document.getElementById('site_theme_input').value = key;
            document.querySelectorAll('[id^="theme-btn-"]').forEach(b => b.style.borderColor = 'rgba(255,255,255,.1)');
            document.getElementById('theme-btn-' + key).style.borderColor = accent;
            syncColor(accent);
        }
        </script>

            <div class="form-group">
                <label class="form-label">Custom Webhook URL (Optional)</label>
                <?php
                // Pre-fill with detected URL if not yet saved.
                // Host must be sanitized: HTTP_HOST is client-controllable
                // and could contain injected characters. SERVER_NAME is
                // preferred when the server config sets it meaningfully.
                $is_https   = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
                $serverName = $_SERVER['SERVER_NAME'] ?? '';
                $hostSource = ($serverName !== '' && $serverName !== '_' && $serverName !== 'default')
                    ? $serverName
                    : ($_SERVER['HTTP_HOST'] ?? '');
                $safeHost    = Str::safeHost($hostSource, 'localhost');
                $detectedUrl = ($is_https ? 'https' : 'http') . '://' . $safeHost . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
                $webhookUrlValue = $config['custom_webhook_url'] ?? $detectedUrl;
                ?>
                <input type="text" name="custom_webhook_url" class="form-control" value="<?= e($webhookUrlValue) ?>" placeholder="https://yourdomain.com/telepage">
                <?php if (empty($config['custom_webhook_url'])): ?>
                <p style="font-size:11px;color:var(--warning);margin:4px 0">⚠️ Not yet saved. Verify the value and save settings.</p>
                <?php endif; ?>
                <?php if (!str_starts_with($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ($is_https ? 'https' : 'http'), 'https')): ?>
                    <p class="alert alert-error" style="margin: 5px 0; padding: 5px 10px; font-size: 11px;">⚠️ Warning: You are on HTTP. Telegram requires HTTPS for the Webhook.</p>
                <?php endif; ?>
                <p class="form-hint">Leave empty for automatic detection. Must be <strong>HTTPS</strong>.</p>
            </div>

            <div class="grid-3" style="grid-gap: 15px; margin-top: 20px;">
                <div class="form-group" style="background: rgba(var(--accent-rgb), 0.05); padding: 10px; border-radius: 8px;">
                    <label class="form-label" style="display:flex; justify-content:space-between">
                        🚀 Lite Mode (Bandwidth Saving)
                        <input type="checkbox" name="download_media" value="1" <?= ($config['download_media'] ?? true) ? 'checked' : '' ?>>
                    </label>
                    <p style="font-size:11px; color:var(--muted)">When active, does not download heavy photos/videos to the server (for text/link-only sites).</p>
                </div>
            </div>

            <div style="display: flex; gap: 10px; margin-top: 15px; border-top: 1px solid var(--border); padding-top: 15px;">
                <button type="button" class="btn btn-outline btn-sm" onclick="adminAction('set_webhook')">🔌 Register Webhook (HTTPS)</button>
                <button type="button" class="btn btn-outline btn-sm" onclick="adminAction('sync_telegram')">📥 Manual Sync (getUpdates)</button>
            </div>
        </div>

        <!-- AI section -->
        <div class="card">
            <div class="card-title">🤖 Artificial Intelligence (Gemini)</div>
            <div class="form-group">
                <label class="form-label">Gemini API Key</label>
                <input type="password" name="gemini_api_key" class="form-control" value="<?= e($config['gemini_api_key'] ?? '') ?>">
                <p class="form-hint">Get a free key at <a href="https://aistudio.google.com/" target="_blank" style="color: var(--accent);">Google AI Studio</a>.</p>
            </div>
            <div class="grid-3" style="grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-top: 15px;">
                <div class="form-group">
                    <label class="form-label">Enable AI</label>
                    <select name="ai_enabled" class="form-control">
                        <option value="1" <?= ($config['ai_enabled'] ?? false) ? 'selected' : '' ?>>Yes</option>
                        <option value="0" <?= !($config['ai_enabled'] ?? false) ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Auto-Tag</label>
                    <select name="ai_auto_tag" class="form-control">
                        <option value="1" <?= ($config['ai_auto_tag'] ?? false) ? 'selected' : '' ?>>Enabled</option>
                        <option value="0" <?= !($config['ai_auto_tag'] ?? false) ? 'selected' : '' ?>>Disabled</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Auto-Summary</label>
                    <select name="ai_auto_summary" class="form-control">
                        <option value="1" <?= ($config['ai_auto_summary'] ?? false) ? 'selected' : '' ?>>Enabled</option>
                        <option value="0" <?= !($config['ai_auto_summary'] ?? false) ? 'selected' : '' ?>>Disabled</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- UX section -->
        <div class="card">
            <div class="card-title">⚙️ User Experience (UX)</div>
            <div class="grid-3" style="grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                <div class="form-group">
                    <label class="form-label">Items per Page</label>
                    <select name="items_per_page" class="form-control">
                        <?php foreach([12, 24, 48, 96] as $val): ?>
                            <option value="<?= $val ?>" <?= ($config['items_per_page'] ?? 12) == $val ? 'selected' : '' ?>><?= $val ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Pagination</label>
                    <select name="pagination_type" class="form-control">
                        <option value="classic" <?= ($config['pagination_type'] ?? 'classic') === 'classic' ? 'selected' : '' ?>>Classic</option>
                        <option value="enhanced" <?= ($config['pagination_type'] ?? '') === 'enhanced' ? 'selected' : '' ?>>Enhanced</option>
                        <option value="loadmore" <?= ($config['pagination_type'] ?? '') === 'loadmore' ? 'selected' : '' ?>>Load More</option>
                        <option value="infinite" <?= ($config['pagination_type'] ?? '') === 'infinite' ? 'selected' : '' ?>>Infinite</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Lingua</label>
                    <select name="language" class="form-control">
                        <option value="it" <?= ($config['language'] ?? 'it') === 'it' ? 'selected' : '' ?>>Italiano</option>
                        <option value="en" <?= ($config['language'] ?? '') === 'en' ? 'selected' : '' ?>>English</option>
                        <option value="de" <?= ($config['language'] ?? '') === 'de' ? 'selected' : '' ?>>Deutsch</option>
                        <option value="fr" <?= ($config['language'] ?? '') === 'fr' ? 'selected' : '' ?>>Français</option>
                        <option value="es" <?= ($config['language'] ?? '') === 'es' ? 'selected' : '' ?>>Español</option>
                    </select>
                </div>
            </div>
        </div>

        <div style="position: sticky; bottom: 20px; text-align: right; z-index: 50;">
            <button type="submit" class="btn btn-primary" style="padding: 12px 32px; font-size: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">💾 Save Settings</button>
        </div>
    </form>

    <!-- Danger Zone -->
    <div class="card" style="margin-top: 60px; border-color: rgba(239, 68, 68, 0.3); background: rgba(239, 68, 68, 0.05);">
        <div class="card-title" style="color: var(--error);">⚠️ Danger Zone</div>
        <p style="font-size: 13px; color: var(--muted); margin-bottom: 20px;">
            These actions have permanent effects. <strong>Factory Reset</strong> will delete every post, configuration and downloaded image, returning the app to its initial installation state.
        </p>
        <div style="display: flex; gap: 12px; flex-wrap: wrap;">
            <button type="button" class="btn btn-outline btn-sm" onclick="adminAction('reset_database', {confirm: 'RESET'})">🗑️ Empty Database</button>
            <button type="button" class="btn btn-danger btn-sm" onclick="factoryReset()">🔥 Factory Reset (Totale)</button>
        </div>
    </div>
</div>

<div id="toast" style="position: fixed; bottom: 40px; left: 50%; transform: translateX(-50%); background: var(--success); color: white; padding: 12px 24px; border-radius: 8px; font-weight: 600; box-shadow: 0 10px 30px rgba(0,0,0,0.3); display: none; z-index: 1000;">
    Settings saved successfully!
</div>

<script>
async function saveSettings(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    const data = {};
    formData.forEach((value, key) => {
        // Handle checkboxes (only if present in the form)
        if (key === 'download_media' || key.startsWith('ai_')) {
            data[key] = true;
        } else {
            data[key] = value;
        }
    });

    // If checkboxes are absent from FormData (unchecked), set them to false
    if (!formData.has('download_media')) data['download_media'] = false;
    ['ai_enabled', 'ai_auto_tag', 'ai_auto_summary'].forEach(k => {
        if (!formData.has(k)) data[k] = false;
    });

    const result = await tpApi('save_settings', {
        method: 'POST',
        body: data
    });
    if (result.ok) {
        showToast();
    } else {
        alert('Error while saving: ' + result.error);
    }
}

function showToast() {
    const toast = document.getElementById('toast');
    toast.style.display = 'block';
    setTimeout(() => {
        toast.style.display = 'none';
        location.reload();
    }, 2000);
}

async function adminAction(action, data = null) {
    if (!confirm('Are you sure you want to perform this action?')) return;

    const result = await tpApi(action, {
        method: 'POST',
        body: data || {}
    });
    alert(result.ok ? 'Operation complete!' : 'Error: ' + result.error);
    if (result.ok) location.reload();
}

async function factoryReset() {
    const conf = prompt('WARNING: This action will destroy everything. Type FACTORY RESET to proceed:');
    if (conf !== 'FACTORY RESET') return;

    if (!confirm('FINAL WARNING: Confirm the full reset of Telepage?')) return;

    const result = await tpApi('factory_reset', {
        method: 'POST',
        body: { confirm: 'FACTORY RESET' }
    });
    if (result.ok) {
        alert('System reset successfully. You will be redirected to the installer.');
        window.location.href = '../install/index.php';
    } else {
        alert('Error: ' + result.error);
    }
}

async function uploadLogo(e) {
    const file = e.target.files[0];
    if (!file) return;

    const formData = new FormData();
    formData.append('logo', file);

    const result = await tpApi('upload_logo', {
        method: 'POST',
        formData: formData
    });
    if (result.ok) {
        document.getElementById('logo-preview').src = '../' + result.data.path + '?t=' + new Date().getTime();
        document.querySelector('[name="logo_path"]').value = result.data.path;
        showToast('Logo uploaded and optimised!');
    } else {
        alert('Upload error: ' + result.error);
    }
}

function showToast(msg = 'Settings saved!') {
    const toast = document.getElementById('toast');
    toast.innerText = msg;
    toast.style.display = 'block';
    setTimeout(() => { toast.style.display = 'none'; }, 3000);
}
</script>

<?php adminFooter(); ?>
