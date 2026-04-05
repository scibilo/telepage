<?php
/**
 * TELEPAGE — admin/contents.php
 * Content Management: manual editing of titles, descriptions and tags.
 */

require_once __DIR__ . '/_auth.php';
adminHeader('Contents', 'contents');
?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <div>
            <h2 class="card-title">Content Archive</h2>
            <p class="card-subtitle">Edit metadata and categorisation of imported posts.</p>
        </div>
        <div style="display:flex; gap:10px;">
            <input type="text" id="admin-search" class="form-control" placeholder="Search contents..." style="width:250px;" oninput="debounceLoad()">
        </div>
    </div>

    <table class="table" id="contents-table">
        <thead>
            <tr>
                <th>Title / Post</th>
                <th>Type</th>
                <th>Date</th>
                <th>Status</th>
                <th style="text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody id="contents-body">
            <tr><td colspan="5" style="text-align:center; padding:40px;">Loading...</td></tr>
        </tbody>
    </table>

    <div id="pagination" style="display:flex; justify-content:center; gap:10px; margin-top:20px;"></div>
</div>

<!-- Content Edit Modal -->
<div id="edit-modal" class="modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.8); z-index:1000; align-items:center; justify-content:center;">
    <div class="card" style="width:100%; max-width:800px; max-height:90vh; overflow-y:auto; margin:20px;">
        <h3 id="modal-title" style="margin-bottom:20px;">Edit Content</h3>
        <form id="edit-form">
            <input type="hidden" id="edit-id" value="0">
            
            <div class="form-group">
                <label class="form-label">Title</label>
                <input type="text" id="edit-title" class="form-control" required>
            </div>

            <div class="form-group">
                <label class="form-label">Description / AI Summary</label>
                <textarea id="edit-description" class="form-control" rows="6" style="resize:vertical;"></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Associated Tags</label>
                <div id="tags-selector" style="display:flex; flex-wrap:wrap; gap:10px; padding:15px; background:var(--bg2); border:1px solid var(--border); border-radius:8px;">
                    <!-- Tags will be loaded here -->
                </div>
                <small class="form-hint">Select tags to categorise this content.</small>
            </div>

            <div class="form-actions" style="margin-top:30px;">
                <button type="button" class="btn btn-outline" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
let currentPage = 1;
let allTags = [];
let searchTimer = null;

async function loadTagsData() {
    const res  = await fetch('../api/admin.php?action=tags_list');
    const json = await res.json();
    allTags = json.data || json;
}

async function loadContents(page = 1) {
    currentPage = page;
    const q = document.getElementById('admin-search').value;
    try {
        const res = await fetch(`../api/admin.php?action=contents_list&page=${page}&q=${encodeURIComponent(q)}`);
        const json = await res.json();
        const data = json.data || json; // supports both {ok,data:{...}} and direct response
        const body = document.getElementById('contents-body');
        body.innerHTML = '';

        if (!data.items || data.items.length === 0) {
            body.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:40px;">No contents found.</td></tr>';
            return;
        }

        data.items.forEach(item => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>
                    <div style="font-weight:600; margin-bottom:4px;">${escapeHtml(item.title || '(Post Telegram)')}</div>
                    <a href="${item.url}" target="_blank" style="font-size:12px; color:var(--accent); text-decoration:none;">View source ↗</a>
                </td>
                <td><span class="badge badge-info">${item.content_type.toUpperCase()}</span></td>
                <td style="font-size:12px; color:var(--text-muted)">${new Date(item.created_at).toLocaleDateString()}</td>
                <td>
                    <span class="badge ${item.is_deleted ? 'badge-danger' : 'badge-success'}">
                        ${item.is_deleted ? 'DELETED' : 'ACTIVE'}
                    </span>
                </td>
                <td style="text-align: right; white-space:nowrap;">
                    <button class="btn btn-outline" style="padding:4px 8px; font-size:12px;" onclick="openEditModal(${item.id})">Edit</button>
                    ${item.is_deleted ? 
                        `<button class="btn btn-outline" style="padding:4px 8px; font-size:12px;" onclick="restoreItem(${item.id})">Restore</button>` :
                        `<button class="btn btn-outline" style="padding:4px 8px; font-size:12px; color:var(--error);" onclick="deleteItem(${item.id})">Delete</button>`
                    }
                </td>
            `;
            body.appendChild(tr);
        });

        renderPagination(data.pages || 1);
    } catch (e) {
        console.error(e);
    }
}

function renderPagination(totalPages) {
    const container = document.getElementById('pagination');
    container.innerHTML = '';
    if (totalPages <= 1) return;

    for (let i = 1; i <= totalPages; i++) {
        const btn = document.createElement('button');
        btn.className = `btn btn-outline ${i === currentPage ? 'active' : ''}`;
        btn.textContent = i;
        btn.onclick = () => loadContents(i);
        container.appendChild(btn);
    }
}

async function openEditModal(id) {
    try {
        const res  = await fetch(`../api/admin.php?action=get_content&id=${id}`);
        const json = await res.json();
        const content = json.data || json;
        
        document.getElementById('edit-id').value = content.id;
        document.getElementById('edit-title').value = content.title || '';
        document.getElementById('edit-description').value = content.ai_summary || content.description || '';
        
        const selector = document.getElementById('tags-selector');
        selector.innerHTML = '';
        
        const selectedIds = (content.tags || []).map(t => t.id);
        
        allTags.forEach(tag => {
            const label = document.createElement('label');
            label.style.cssText = `display:flex; align-items:center; gap:8px; padding:6px 10px; background:var(--surface); border-radius:6px; cursor:pointer; border:1px solid ${selectedIds.includes(tag.id) ? 'var(--accent)' : 'var(--border)'};`;
            
            const isChecked = selectedIds.includes(tag.id);
            label.innerHTML = `
                <input type="checkbox" name="tags[]" value="${tag.id}" ${isChecked ? 'checked' : ''} onchange="this.parentElement.style.borderColor = this.checked ? 'var(--accent)' : 'var(--border)'">
                <span style="width:10px; height:10px; border-radius:2px; background:${tag.color}"></span>
                <span style="font-size:13px;">${escapeHtml(tag.name)}</span>
            `;
            selector.appendChild(label);
        });

        document.getElementById('edit-modal').style.display = 'flex';
    } catch (e) {
        console.error(e);
    }
}

function closeEditModal() {
    document.getElementById('edit-modal').style.display = 'none';
}

document.getElementById('edit-form').onsubmit = async (e) => {
    e.preventDefault();
    const id = document.getElementById('edit-id').value;
    const selectedTags = Array.from(document.querySelectorAll('input[name="tags[]"]:checked')).map(cb => cb.value);
    
    const data = {
        id: parseInt(id),
        title: document.getElementById('edit-title').value,
        description: document.getElementById('edit-description').value,
        tags: selectedTags
    };

    const res = await fetch('../api/admin.php?action=save_content', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    });

    if (res.ok) {
        closeEditModal();
        loadContents(currentPage);
    } else {
        alert('Error while saving.');
    }
};

function deleteItem(id) {
    if (!confirm('Move this content to trash?')) return;
    fetch('../api/admin.php?action=delete_content', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + id
    }).then(() => loadContents(currentPage));
}

function restoreItem(id) {
    fetch('../api/admin.php?action=restore_content', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + id
    }).then(() => loadContents(currentPage));
}

function debounceLoad() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadContents(1), 300);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Init
loadTagsData().then(() => loadContents());
</script>

<style>
.badge-danger { background: rgba(239, 68, 68, 0.1); color: var(--error); border: 1px solid rgba(239, 68, 68, 0.3); }
.badge-info   { background: rgba(79, 126, 255, 0.1); color: var(--accent); border: 1px solid rgba(79, 126, 255, 0.3); }
.badge-success { background: rgba(34, 197, 94, 0.1); color: var(--success); border: 1px solid rgba(34, 197, 94, 0.3); }
.badge { padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; }
.btn.active { background: var(--accent); color: white; border-color: var(--accent); }
.table th { color: var(--text-muted); font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; padding: 12px; border-bottom: 2px solid var(--border); }
.table td { padding: 16px 12px; border-bottom: 1px solid var(--border); }
.table { width: 100%; border-collapse: collapse; }
</style>

<?php adminFooter(); ?>
