// LANSERING: Ändra denna till din subdomän-URL när du laddar upp på nätet, t.ex. 'https://api.mindoman.se/api.php'
const API_URL = 'api.php';

document.addEventListener('DOMContentLoaded', async () => {
    await loadLanguages();
    fetchPages();
    loadApiDocs();
});

// Hämtar språkalternativ från sprak-tabellen
async function loadLanguages() {
    try {
        const response = await fetch(`${API_URL}?action=languages`);
        const languages = await response.json();
        
        ['filterSprak', 'sprak', 'editSprak'].forEach(selectId => {
            const dropdown = document.getElementById(selectId);
            if (dropdown) {
                dropdown.querySelectorAll('option:not([value=""])').forEach(opt => opt.remove());
                languages.forEach(lang => {
                    const option = document.createElement('option');
                    option.value = lang.id;
                    option.textContent = lang.namn;
                    dropdown.appendChild(option);
                });
            }
        });
    } catch (err) {
        console.error("Kunde inte hämta språk:", err);
    }
}

async function fetchPages() {
    const status = document.getElementById('filterStatus').value;
    const sprak = document.getElementById('filterSprak').value;
    const year = document.getElementById('filterYear').value;
    const month = document.getElementById('filterMonth').value;
    const week = document.getElementById('filterWeek').value;
    const search = document.getElementById('searchInput').value;

    let url = `${API_URL}?status=${encodeURIComponent(status)}&sprak_id=${encodeURIComponent(sprak)}&year=${encodeURIComponent(year)}&month=${encodeURIComponent(month)}&week=${encodeURIComponent(week)}&search=${encodeURIComponent(search)}`;

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
            let imagesHtml = page.images && page.images.length > 0
                ? page.images.map(img => `<div class="image-card"><img src="${img.img_path}"><span>${(img.file_size / 1024).toFixed(1)} KB</span></div>`).join('')
                : '<p style="font-size: 12px; color: #888;">Inga bilder.</p>';

            let langsHtml = page.available_languages && page.available_languages.length > 0
                ? page.available_languages.map(l => `<span style="background:#e0e0e0; padding:2px 6px; border-radius:3px; font-size:11px; margin-right:4px;">${l.namn}</span>`).join('')
                : '';

            listDiv.innerHTML += `
                <div class="page-item">
                    <div class="page-header">
                        <h3>${escapeHtml(page.title)} <small style="font-size: 12px; color: #777;">(ID: ${page.id})</small></h3>
                        <span class="badge ${page.status}">${page.status === 'published' ? 'Publicerad' : 'Utkast'}</span>
                    </div>
                    <div style="margin-bottom:8px;"><strong>Språk i databasen:</strong> ${langsHtml}</div>
                    <p>${escapeHtml(page.content)}</p>
                    <strong>Bilder:</strong>
                    <div class="image-gallery">${imagesHtml}</div>
                    
                    <div style="margin: 10px 0; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                        <label style="font-size: 12px;">Ny bild:</label>
                        <input type="file" id="file-${page.id}" accept="image/*" style="font-size: 12px;">
                        <button onclick="uploadImage(${page.id})" style="padding: 4px 8px; font-size: 12px;">Ladda upp</button>
                    </div>

                    <div style="display: flex; gap: 8px; margin-top: 10px;">
                        <button class="secondary" onclick='openEditModal(${JSON.stringify(page)})'>Redigera / Lägg till översättning</button>
                        <button class="danger" onclick="deletePage(${page.id})">Ta bort sida</button>
                    </div>
                </div>
            `;
        });
    } catch (err) {
        console.error("Fel vid hämtning av sidor:", err);
    }
}

document.getElementById('createPageForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    const pageData = {
        title: document.getElementById('title').value,
        content: document.getElementById('content').value,
        status: document.getElementById('status').value,
        sprak_id: document.getElementById('sprak').value
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

function openEditModal(page) {
    document.getElementById('editId').value = page.id;
    document.getElementById('editTitle').value = page.title;
    document.getElementById('editContent').value = page.content;
    document.getElementById('editStatus').value = page.status;
    document.getElementById('editSprak').value = page.sprak_id;
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
        status: document.getElementById('editStatus').value,
        sprak_id: document.getElementById('editSprak').value
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

async function deletePage(id) {
    if (!confirm("Är du säker på att du vill ta bort sidan och alla dess språk/bilder?")) return;

    try {
        const response = await fetch(`${API_URL}?id=${id}`, { method: 'DELETE' });
        const result = await response.json();
        alert(result.message);
        fetchPages();
    } catch (err) {
        console.error("Kunde inte radera sida:", err);
    }
}

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