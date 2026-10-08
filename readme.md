# Använd CMS-innehåll på din webbplats

Den här guiden visar hur du hämtar och visar innehåll från CMS:et på en egen
webbplats med JavaScript i webbläsaren. API:et returnerar JSON.

## Ange API-adressen

När API:et har publicerats byter du ut `<DIN-DOMÄN>` mot domänen där CMS:et
ligger:

```js
const API_URL = 'https://<DIN-DOMÄN>/api.php';
```

Exempel: `https://cms.example.se/api.php`. Om API:et ligger i en undermapp
anger du hela sökvägen, till exempel
`https://example.se/cms/api.php`.

Lokalt används den relativa adressen:

```js
const API_URL = 'api.php';
```

## Visa publicerade sidor

Lägg till en behållare där sidorna ska visas:

```html
<div id="cms-pages"></div>
```

Hämta publicerade sidor och skapa ett element för var och en:

```html
<script>
const API_URL = 'https://<DIN-DOMÄN>/api.php';
const pagesContainer = document.getElementById('cms-pages');

async function loadPages() {
    const response = await fetch(`${API_URL}?status=published`);
    const pages = await response.json();

    pagesContainer.replaceChildren();

    pages.forEach(page => {
        const article = document.createElement('article');
        const title = document.createElement('h2');
        const content = document.createElement('p');
        const openButton = document.createElement('button');

        title.textContent = page.title;
        content.textContent = page.content;
        openButton.textContent = 'Läs mer';
        openButton.addEventListener('click', () => loadPage(page.id));

        article.append(title, content, openButton);
        pagesContainer.append(article);
    });
}

loadPages();
</script>
```

Använd `status=published` för publika sidor. Utan statusfilter returnerar
listan även utkast. Listan använder sidans översättning med lägst `sprak_id`
om inget språkfilter anges. Knappen anropar `loadPage`, som definieras i nästa
exempel; lägg båda funktionerna i samma JavaScript på din webbplats.

Du kan filtrera listan med query-parametrar:

```js
const params = new URLSearchParams({
    status: 'published',
    sprak_id: '1',
    month: '10',
    search: 'nyhet'
});

const response = await fetch(`${API_URL}?${params}`);
const pages = await response.json();
```

Tillgängliga parametrar för listan:

| Parameter | Användning |
| --- | --- |
| `status` | Filtrera på `published` eller `draft`. |
| `sprak_id` | Välj språkets ID. |
| `month` | Filtrera på skapandemånad (`1`–`12`). |
| `search` | Sök i titel och innehåll. |

## Visa en sida och byta språk

Hämta en specifik sida med dess ID. Svaret innehåller `title`, `content`,
`images` och en lista över `translations`.

Hämta bara ID:n från listan med `status=published` när sidan är publik.

```html
<section id="cms-detail"></section>
```

```js
const detailContainer = document.getElementById('cms-detail');

async function loadPage(pageId, sprakId) {
    const params = new URLSearchParams();
    if (sprakId) params.set('sprak_id', sprakId);

    const query = params.toString();
    const url = `${API_URL}?id=${encodeURIComponent(pageId)}${query ? `&${query}` : ''}`;
    const response = await fetch(url);
    const page = await response.json();

    if (!response.ok) {
        detailContainer.textContent = page.message || 'Sidan kunde inte hämtas.';
        return;
    }

    detailContainer.replaceChildren();

    const title = document.createElement('h1');
    const content = document.createElement('p');
    title.textContent = page.title;
    content.textContent = page.content;
    detailContainer.append(title, content);

    page.translations.forEach(translation => {
        const languageButton = document.createElement('button');
        languageButton.textContent = translation.sprak_namn;
        languageButton.addEventListener('click', () => {
            loadPage(page.id, translation.sprak_id);
        });
        detailContainer.append(languageButton);
    });

    page.images.forEach(image => {
        const img = document.createElement('img');
        img.src = new URL(image.img_path, new URL(API_URL, window.location.href));
        img.alt = '';
        detailContainer.append(img);
    });
}
```

Anropa exempelvis `loadPage(12)` när besökaren väljer en sida. Språk kan väljas
med språkets ID, som i `loadPage(12, 1)`, eller genom att anropa API:et med
språkkod: `?id=12&sprak=sv`. Om efterfrågat språk inte finns används sidans
första tillgängliga översättning.

## Hämta språk

Om du vill bygga en språklista innan en sida har valts hämtar du språken så här:

```js
const response = await fetch(`${API_URL}?action=languages`);
const languages = await response.json();
```

Varje språk har `id`, `sprak` (språkkod) och `namn`. Använd `id` som
`sprak_id` när du hämtar sidlistan eller en specifik översättning.

## API-anrop

| Anrop | Vad webbplatsen får/gör |
| --- | --- |
| `GET ?status=published` | Hämtar publicerade sidor för en översikt. |
| `GET ?id={id}` | Hämtar en sidas innehåll, översättningar och bilder. |
| `GET ?id={id}&sprak_id={sprak_id}` | Hämtar sidan med vald översättning. |
| `GET ?action=languages` | Hämtar språklistan. |
| `POST` | Skapar en sida. Skicka JSON med `title`, `content`, `sprak_id` och valfri `status`. |
| `PUT ?id={id}` | Uppdaterar sidstatus och/eller sidans översättning. Skicka JSON. |
| `DELETE ?id={id}` | Tar bort sidan. |
| `POST ?action=upload_image` | Laddar upp bild som `multipart/form-data`, med `page_id` och `image_file`. |

## Skriva data från en webbplats

`POST`, `PUT`, `DELETE` och bilduppladdning ändrar CMS-innehåll. API:et har
ingen inbyggd autentisering. Anropa därför inte dessa operationer från en
publik webbplats som är tillgänglig för alla: besökare kan då ändra eller ta
bort innehåll. För skrivåtkomst krävs autentisering och åtkomstkontroll på
servern; hemliga nycklar ska inte läggas i JavaScript som skickas till
webbläsaren.

## Anslutning från en annan domän

API:et tillåter för närvarande anrop från alla domäner via CORS. Webbläsaren
kan därför anropa API:et från en separat webbplats. Både webbplatsen och API:et
bör använda HTTPS när de är publicerade.

## Fel i webbläsaren

Ett anrop kan misslyckas på grund av nätverksfel eller ett HTTP-fel. Kontrollera
`response.ok` efter `fetch`; felsvar innehåller vanligen ett `message`-fält.
Exempelvis betyder `404` att sidan saknas och `400` att begäran har felaktiga
eller saknade uppgifter.
