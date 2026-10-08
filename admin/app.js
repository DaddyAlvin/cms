const API_URL = 'api_admin.php';

class CmsApi {
    async request({ method = 'GET', params = {}, body } = {}) {
        const query = new URLSearchParams(
            Object.entries(params).filter(([_, v]) => v !== undefined && v !== null && v !== '')
        );
        const url = query.toString() ? `${API_URL}?${query}` : API_URL;
        const options = { method };

        if (body instanceof FormData) {
            options.body = body;
        } else if (body) {
            options.headers = { 'Content-Type': 'application/json' };
            options.body = JSON.stringify(body);
        }

        const res = await fetch(url, options);
        return { ok: res.ok, data: await res.json().catch(() => ({})) };
    }
}

class CmsApp {
    constructor() {
        this.api = new CmsApi();
        this.pages = [];
    }

    async init() {
        await this.loadLanguages();
        this.fetchPages();
        this.loadDocs();

        document.getElementById('createPageForm').onsubmit = (e) => this.savePage(e);
        document.getElementById('editPageForm').onsubmit = (e) => this.savePage(e, true);
    }

    async loadLanguages() {
        const { data } = await this.api.request({ params: { action: 'languages' } });
        if (!Array.isArray(data)) return;

        ['filterSprak', 'sprak', 'editSprak'].forEach(id => {
            const select = document.getElementById(id);
            if (!select) return;
            select.querySelectorAll('option:not([value=""])').forEach(o => o.remove());
            data.forEach(l => select.add(new Option(l.namn, l.id)));
        });
    }

    async fetchPages() {
        const getVal = id => document.getElementById(id)?.value || '';
        const params = {
            status: getVal('filterStatus'),
            sprak_id: getVal('filterSprak'),
            year: getVal('filterYear'),
            month: getVal('filterMonth'),
            week: getVal('filterWeek'),
            search: getVal('searchInput'),
            min_id: getVal('filterMinId'),
            max_id: getVal('filterMaxId')
        };

        const { data } = await this.api.request({ params });
        this.pages = Array.isArray(data) ? data : [];

        const listDiv = document.getElementById('pagesList');
        if (!this.pages.length) return listDiv.innerHTML = '<p>Inga sidor hittades.</p>';

        listDiv.innerHTML = this.pages.map(p => `
            <div class="page-item">
                <div class="page-header">
                    <h3>${this.esc(p.title)} <small style="font-size: 12px; color: #777;">(ID: ${p.id})</small></h3>
                    <span class="badge ${p.status}">${p.status === 'published' ? 'Publicerad' : 'Utkast'}</span>
                </div>
                <div style="margin-bottom:8px;">
                    <strong>Språk i databasen:</strong> 
                    ${p.available_languages?.map(l => `<span style="background:#e0e0e0; padding:2px 6px; border-radius:3px; font-size:11px; margin-right:4px;">${this.esc(l.namn)}</span>`).join('') || ''}
                </div>
                <p>${this.esc(p.content)}</p>
                <strong>Bilder:</strong>
                <div class="image-gallery">
                    ${p.images?.map(i => `<div class="image-card"><img src="${i.img_path}"><span>${(i.file_size / 1024).toFixed(1)} KB</span></div>`).join('') || '<p style="font-size: 12px; color: #888;">Inga bilder.</p>'}
                </div>
                <div style="margin: 10px 0; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                    <label style="font-size: 12px;">Ny bild:</label>
                    <input type="file" id="file-${p.id}" accept="image/*" style="font-size: 12px;">
                    <button onclick="app.uploadImg(${p.id})" style="padding: 4px 8px; font-size: 12px;">Ladda upp</button>
                </div>
                <div style="display: flex; gap: 8px; margin-top: 10px;">
                    <button class="secondary" onclick="app.editModal(${p.id})">Redigera / Lägg till översättning</button>
                    <button class="danger" onclick="app.deletePage(${p.id})">Ta bort sida</button>
                </div>
            </div>
        `).join('');
    }

    async savePage(e, isEdit = false) {
        e.preventDefault();
        const prefix = isEdit ? 'edit' : '';
        const id = isEdit ? document.getElementById('editId').value : null;

        const body = {
            title: document.getElementById(prefix ? 'editTitle' : 'title').value.trim(),
            content: document.getElementById(prefix ? 'editContent' : 'content').value.trim(),
            status: document.getElementById(prefix ? 'editStatus' : 'status').value,
            sprak_id: document.getElementById(prefix ? 'editSprak' : 'sprak').value
        };

        const { ok, data } = await this.api.request({
            method: isEdit ? 'PUT' : 'POST',
            params: isEdit ? { id } : {},
            body
        });

        alert(data.message);
        if (ok) {
            if (isEdit) this.toggleModal('editModal', false);
            else e.target.reset();
            this.fetchPages();
        }
    }

    editModal(id) {
        const page = this.pages.find(p => p.id === id);
        if (!page) return;
        document.getElementById('editId').value = page.id;
        document.getElementById('editTitle').value = page.title || '';
        document.getElementById('editContent').value = page.content || '';
        document.getElementById('editStatus').value = page.status || 'draft';
        document.getElementById('editSprak').value = page.sprak_id || '';
        this.toggleModal('editModal', true);
    }

    toggleModal(id, show) {
        const modal = document.getElementById(id);
        if (modal) modal.classList.toggle('active', show ?? !modal.classList.contains('active'));
    }

    async deletePage(id) {
        if (!confirm("Är du säker på att du vill ta bort sidan och alla dess språk/bilder?")) return;
        const { data } = await this.api.request({ method: 'DELETE', params: { id } });
        alert(data.message);
        this.fetchPages();
    }

    async uploadImg(pageId) {
        const fileInput = document.getElementById(`file-${pageId}`);
        if (!fileInput?.files[0]) return alert("Välj en bildfil först.");

        const body = new FormData();
        body.append('page_id', pageId);
        body.append('image_file', fileInput.files[0]);

        const { ok, data } = await this.api.request({ method: 'POST', params: { action: 'upload_image' }, body });
        alert(data.message);
        if (ok) this.fetchPages();
    }

    async loadDocs() {
        const { data } = await this.api.request({ params: { action: 'help' } });
        const docsEl = document.getElementById('docsContent');
        if (docsEl) docsEl.innerText = JSON.stringify(data, null, 2);
    }

    esc(str) {
        return (str || '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
    }
}

let app;
document.addEventListener('DOMContentLoaded', () => { app = new CmsApp(); app.init(); });

// Bakåtkompatibilitet för index.html
window.fetchPages = () => app.fetchPages();
window.toggleDocs = () => app.toggleModal('docsModal');
window.closeEditModal = () => app.toggleModal('editModal', false);