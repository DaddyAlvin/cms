const API_URL = 'api.php';

// Körs när sidan laddas
document.addEventListener('DOMContentLoaded', () => {
    fetchPages();
    loadApiDocs();
});

// 1. Hämta sidor med villkor (GET med status & search filter)
async function fetchPages() {
    const status = document.getElementById('filterStatus').value;
    const search = document.getElementById('searchInput').value;

    let url = `${API_URL}?status=${encodeURIComponent(status)}&search=${encodeURIComponent(search)}`;

    try {
        const response = await fetch(url);
        const pages = await response.json();

        const listDiv = document.getElementById('pagesList');
        listDiv.innerHTML = '';

        if (!Array.isArray(pages) || pages.length === 0) {
            listDiv.innerHTML = '<p>Inga sidor hittades.</p>';
            return;
        }

        pages.forEach(page => {
            let imagesHtml = '';
            if (page.images && page.images.length > 0) {
                imagesHtml = page.images.map(img => `
                    <div class="image-card">
                        <img src="${img.img_path}" alt="Bild ${img.id}">
                        <span>${(img.file_size / 1024).toFixed(1)} KB</span>
                    </div>
                `).join('');
            } else {
                imagesHtml = '<p style="font-size: 12px; color: #888;">Inga bilder uppladdade ännu.</p>';
            }

            listDiv.innerHTML += `
                <div class="page-item">
                    <div class="page-header">
                        <h3>${escapeHtml(page.title)} <small style="font-size: 12px; color: #777;">(ID: ${page.id})</small></h3>
                        <span class="badge ${page.status}">${page.status === 'published' ? 'Publicerad' : 'Utkast'}</span>
                    </div>
                    <p>${escapeHtml(page.content)}</p>
                    
                    <strong>Bilder:</strong>
                    <div class="image-gallery">${imagesHtml}</div>

                    <!-- Form för att ladda upp bild till denna sida -->
                    <div style="margin: 10px 0; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                        <label style="font-size: 12px;">Ladda upp ny bild till denna sida:</label>
                        <input type="file" id="file-${page.id}" accept="image/*" style="font-size: 12px;">
                        <button onclick="uploadImage(${page.id})" style="padding: 4px 8px; font-size: 12px;">Ladda upp bild</button>
                    </div>

                    <div style="display: flex; gap: 8px; margin-top: 10px;">
                        <button class="secondary" onclick='openEditModal(${JSON.stringify(page)})'>Redigera</button>
                        <button class="danger" onclick="deletePage(${page.id})">Ta bort sida</button>
                    </div>
                </div>
            `;
        });
    } catch (err) {
        console.error("Fel vid hämtning av sidor:", err);
    }
}

// 2. Skapa ny sida (POST)
document.getElementById('createPageForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    const pageData = {
        title: document.getElementById('title').value,
        content: document.getElementById('content').value,
        status: document.getElementById('status').value
    };

    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(pageData)
        });

        const result = await response.json();
        alert(result.message);

        if (response.ok) {
            document.getElementById('createPageForm').reset();
            fetchPages();
        }
    } catch (err) {
        console.error("Kunde inte skapa sida:", err);
    }
});

// 3. Redigera sida (PUT)
function openEditModal(page) {
    document.getElementById('editId').value = page.id;
    document.getElementById('editTitle').value = page.title;
    document.getElementById('editContent').value = page.content;
    document.getElementById('editStatus').value = page.status;
    document.getElementById('editModal').classList.add('active');
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('active');
}

document.getElementById('editPageForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = document.getElementById('editId').value;

    const pageData = {
        title: document.getElementById('editTitle').value,
        content: document.getElementById('editContent').value,
        status: document.getElementById('editStatus').value
    };

    try {
        const response = await fetch(`${API_URL}?id=${id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(pageData)
        });

        const result = await response.json();
        alert(result.message);

        if (response.ok) {
            closeEditModal();
            fetchPages();
        }
    } catch (err) {
        console.error("Kunde inte uppdatera sida:", err);
    }
});

// 4. Ta bort sida (DELETE)
async function deletePage(id) {
    if (!confirm("Är du säker på att du vill ta bort sidan och dess bilder?")) return;

    try {
        const response = await fetch(`${API_URL}?id=${id}`, { method: 'DELETE' });
        const result = await response.json();
        alert(result.message);
        fetchPages();
    } catch (err) {
        console.error("Kunde inte radera sida:", err);
    }
}

// 5. Bilduppladdning (POST med FormData)
async function uploadImage(pageId) {
    const fileInput = document.getElementById(`file-${pageId}`);
    if (!fileInput.files || fileInput.files.length === 0) {
        alert("Välj en bildfil först.");
        return;
    }

    const formData = new FormData();
    formData.append('page_id', pageId);
    formData.append('image_file', fileInput.files[0]);

    try {
        const response = await fetch(`${API_URL}?action=upload_image`, {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        alert(result.message);

        if (response.ok) {
            fetchPages();
        }
    } catch (err) {
        console.error("Fel vid bilduppladdning:", err);
    }
}

// 6. Dokumentation (GET ?action=help)
async function loadApiDocs() {
    try {
        const response = await fetch(`${API_URL}?action=help`);
        const docs = await response.json();
        document.getElementById('docsContent').innerText = JSON.stringify(docs, null, 2);
    } catch (err) {
        console.error("Kunde inte hämta API-docs:", err);
    }
}

function toggleDocs() {
    document.getElementById('docsModal').classList.toggle('active');
}

function escapeHtml(str) {
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}