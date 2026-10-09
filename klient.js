const API_URL = 'https://cmsapi.ntigskovde.se/alvin/api_public.php';

document.addEventListener('DOMContentLoaded', fetchPublishedPages);

// 1. Hämtar alla publicerade sidor och listar dem i översikten
async function fetchPublishedPages() {
    try {
        const response = await fetch(`${API_URL}?status=published`);
        const pages = await response.json();

        const listContainer = document.getElementById('pageList');
        listContainer.innerHTML = '';

        if (!Array.isArray(pages) || pages.length === 0) {
            listContainer.innerHTML = '<p>Det finns inga publicerade sidor just nu.</p>';
            return;
        }

        // Bygg en rad för varje sida
        pages.forEach(p => {
            let langsHtml = p.available_languages && p.available_languages.length > 0
                ? p.available_languages.map(l => `<span class="badge">${escapeHtml(l.namn)}</span>`).join('')
                : '';

            listContainer.innerHTML += `
                <div class="page-card" onclick="openPageModal(${p.id})">
                    <div class="page-info">
                        <h3>${escapeHtml(p.title)}</h3>
                        <p>ID: ${p.id} &nbsp;|&nbsp; Tillgängliga språk: ${langsHtml}</p>
                    </div>
                    <button class="open-btn" onclick="event.stopPropagation(); openPageModal(${p.id})">Läs artikel</button>
                </div>
            `;
        });

    } catch (err) {
        console.error("Fel vid hämtning av sidor:", err);
        document.getElementById('pageList').innerHTML = '<p>Kunde inte ansluta till API:et.</p>';
    }
}

// 2. Öppnar fönstret (modalen) och hämtar innehållet för vald sida
async function openPageModal(pageId, sprakId = null) {
    const modal = document.getElementById('pageModal');
    modal.classList.add('active');

    let url = `${API_URL}?id=${pageId}`;
    if (sprakId) url += `&sprak_id=${sprakId}`;

    try {
        const response = await fetch(url);
        const page = await response.json();

        if (!response.ok) {
            document.getElementById('modalArticle').innerHTML = `<h2>Sidan hittades inte</h2>`;
            return;
        }

        // Generera språknappar inuti fönstret
        const langContainer = document.getElementById('modalLangButtons');
        langContainer.innerHTML = '';

        if (page.translations && page.translations.length > 0) {
            page.translations.forEach(t => {
                const activeClass = (t.sprak_id == page.sprak_id) ? 'active' : '';
                langContainer.innerHTML += `
                    <button class="lang-btn ${activeClass}" onclick="openPageModal(${page.id}, ${t.sprak_id})">
                        ${escapeHtml(t.sprak_namn)}
                    </button>
                `;
            });
        }

        // Bildgalleri
        // Bildgalleri
        let imagesHtml = '';
        if (page.images && page.images.length > 0) {
            page.images.forEach(img => {
                const srcPath = img.img_path.replace(/^\.\.\//, '');
                imagesHtml += `<img src="${srcPath}" alt="Bild">`;
            });
        }

        // Rendera titel, brödtext och bilder i fönstret
        document.getElementById('modalArticle').innerHTML = `
            <h1 style="margin-top:0; font-size: 26px;">${escapeHtml(page.title)}</h1>
            <p style="line-height:1.6; white-space: pre-line; font-size: 15px;">${escapeHtml(page.content)}</p>
            <div class="gallery">${imagesHtml}</div>
        `;

    } catch (err) {
        console.error("Kunde inte ladda fönstret:", err);
        document.getElementById('modalArticle').innerHTML = `<p>Kunde inte ladda innehåll.</p>`;
    }
}

// 3. Stänga fönstret
function closeModal() {
    document.getElementById('pageModal').classList.remove('active');
}

function closeModalOnOutsideClick(e) {
    if (e.target.id === 'pageModal') {
        closeModal();
    }
}

function escapeHtml(str) {
    return str ? str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;") : '';
}