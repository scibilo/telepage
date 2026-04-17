<?php
/**
 * TELEPAGE — admin/tags.php
 * Tag Management: view, create, edit and delete tags.
 */

require_once __DIR__ . '/_auth.php';
adminHeader('Tags', 'tags');
?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <div>
            <h2 class="card-title">All Tags</h2>
            <p class="card-subtitle">Manage labels for content categorisation.</p>
        </div>
        <button class="btn btn-primary" onclick="openTagModal()">+ New Tag</button>
    </div>

    <table class="table" id="tags-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Slug</th>
                <th>Colour</th>
                <th>Source</th>
                <th>Uses</th>
                <th style="text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody id="tags-body">
            <tr><td colspan="6" style="text-align:center; padding:40px;">Loading...</td></tr>
        </tbody>
    </table>
</div>

<!-- Tag Edit Modal -->
<div id="tag-modal" class="modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:1000; align-items:center; justify-content:center;">
    <div class="card" style="width:100%; max-width:450px; margin:20px;">
        <h3 id="modal-title" style="margin-bottom:20px;">Edit Tag</h3>
        <form id="tag-form">
            <input type="hidden" id="tag-id" value="0">
            <div class="form-group">
                <label class="form-label">Tag Name</label>
                <input type="text" id="tag-name" class="form-control" placeholder="Example: Technology" required oninput="autoSlug()">
            </div>
            <div class="form-group">
                <label class="form-label">Slug (URL)</label>
                <input type="text" id="tag-slug" class="form-control" placeholder="technology" required>
            </div>
            <div class="form-group">
                <label class="form-label">Accent Colour</label>
                <div style="display:flex; gap:10px; align-items:center;">
                    <input type="color" id="tag-color" class="form-control" style="width:60px; height:40px; padding:2px;" value="#3b82f6">
                    <input type="text" id="tag-color-text" class="form-control" placeholder="#3b82f6" oninput="document.getElementById('tag-color').value = this.value">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Source</label>
                <select id="tag-source" class="form-control">
                    <option value="manual">Manual</option>
                    <option value="ai">AI (Generated)</option>
                </select>
            </div>
            <div class="form-actions" style="margin-top:30px;">
                <button type="button" class="btn btn-outline" onclick="closeTagModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Tag</button>
            </div>
        </form>
    </div>
</div>

<script>
async function loadTags() {
    try {
        const res = await tpApi('tags_list');
        if (!res.ok) {
            console.error('Failed to load tags:', res.error);
            document.getElementById('tags-body').innerHTML =
                '<tr><td colspan="6" style="text-align:center; padding:40px; color:var(--error);">Error loading tags: '
                + escapeHtml(res.error || 'unknown') + '</td></tr>';
            return;
        }
        const tags = res.data || [];
        const body = document.getElementById('tags-body');
        body.innerHTML = '';

        if (!Array.isArray(tags) || tags.length === 0) {
            body.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:40px;">No tags found.</td></tr>';
            return;
        }

        tags.forEach(tag => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><strong>${escapeHtml(tag.name)}</strong></td>
                <td><code>${escapeHtml(tag.slug)}</code></td>
                <td>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="width:16px; height:16px; border-radius:4px; background:${tag.color}"></span>
                        ${tag.color}
                    </div>
                </td>
                <td><span class="badge ${tag.source === 'ai' ? 'badge-info' : 'badge-success'}">${tag.source.toUpperCase()}</span></td>
                <td>${tag.usage_count}</td>
                <td style="text-align: right;">
                    <button class="btn btn-outline" style="padding:4px 8px; font-size:12px;" onclick='editTag(${JSON.stringify(tag).replace(/'/g, "&apos;")})'>Edit</button>
                    <button class="btn btn-outline" style="padding:4px 8px; font-size:12px; color:var(--error);" onclick="deleteTag(${tag.id})">Delete</button>
                </td>
            `;
            body.appendChild(tr);
        });
    } catch (e) {
        console.error(e);
    }
}

function autoSlug() {
    const name = document.getElementById('tag-name').value;
    document.getElementById('tag-slug').value = name.toLowerCase().replace(/[^a-z0-9]/g, '-').replace(/-+/g, '-').trim('-');
}

function openTagModal() {
    document.getElementById('modal-title').innerText = 'New Tag';
    document.getElementById('tag-id').value = 0;
    document.getElementById('tag-form').reset();
    document.getElementById('tag-modal').style.display = 'flex';
}

function editTag(tag) {
    document.getElementById('modal-title').innerText = 'Edit Tag';
    document.getElementById('tag-id').value = tag.id;
    document.getElementById('tag-name').value = tag.name;
    document.getElementById('tag-slug').value = tag.slug;
    document.getElementById('tag-color').value = tag.color;
    document.getElementById('tag-color-text').value = tag.color;
    document.getElementById('tag-source').value = tag.source;
    document.getElementById('tag-modal').style.display = 'flex';
}

function closeTagModal() {
    document.getElementById('tag-modal').style.display = 'none';
}

async function deleteTag(id) {
    if (!confirm('Are you sure you want to delete this tag? It will be removed from all contents.')) return;

    const res = await tpApi('delete_tag', {
        method: 'POST',
        body: { id: id }
    });

    if (!res.ok) {
        alert('Error: ' + res.error);
        return;
    }
    loadTags();
}

document.getElementById('tag-form').onsubmit = async (e) => {
    e.preventDefault();
    const data = {
        id: parseInt(document.getElementById('tag-id').value),
        name: document.getElementById('tag-name').value,
        slug: document.getElementById('tag-slug').value,
        color: document.getElementById('tag-color').value,
        source: document.getElementById('tag-source').value
    };

    const res = await tpApi('save_tag', {
        method: 'POST',
        body: data
    });

    if (!res.ok) {
        alert('Error: ' + res.error);
        return;
    }
    closeTagModal();
    loadTags();
};

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

document.getElementById('tag-color').oninput = (e) => {
    document.getElementById('tag-color-text').value = e.target.value;
};

loadTags();
</script>

<?php adminFooter(); ?>
