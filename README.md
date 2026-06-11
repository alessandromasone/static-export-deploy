# Static Export & Deploy

Plugin WordPress che esporta il sito in **HTML statico**, lo **ottimizza** (WebP, pulizia HTML, SEO, HTTPS) e lo **pubblica su GitHub** — tutto in background, senza bisogno di `git` sul server.

Pensato per il flusso *WordPress di staging → sito statico in produzione* (GitHub Pages, Cloudflare Pages, Netlify o qualsiasi CDN).

## Funzionalità

### Export (crawler integrato)
- Visita il sito partendo da home, `robots.txt`, sitemap e home delle lingue; scarica pagine, immagini, CSS, font e ogni risorsa interna.
- **Sitemap complete e annidate**: riconoscimento dal contenuto (`<sitemapindex>`/`<urlset>`), quindi supporta le sitemap di default di WordPress (`wp-sitemap.xml` e figlie paginate), i plugin SEO e qualunque nome custom, con ricorsione illimitata. Estrae anche `image:loc`, `video:loc`, hreflang `xhtml:link` e i fogli di stile XSL.
- **Multilingua generalizzato**: lingue rilevate da WPLingua, Polylang, WPML, TranslatePress oppure — per qualsiasi altro caso — dai tag `hreflang` e dai percorsi `/xx/` ricorrenti della home.
- Cattura la pagina 404 di WordPress (→ `404.html`) e include automaticamente gli indici di ricerca di [wp-static-fuse-search](https://github.com/currentjot/wp-static-fuse-search) (`uploads/static-search/*.json`).

### Ottimizzazione
- **PNG/JPG → WebP** (Imagick o GD, con orientamento EXIF e trasparenza) e riscrittura di tutti i riferimenti, `srcset` inclusi.
- **Pulizia HTML via parser DOM**: commenti, JavaScript (opzionale, di default rimosso), preload/prefetch di JS, handler `on*`, promozione del lazy-load, unwrap dei `<noscript>`, minificazione conservativa. I dati strutturati JSON-LD e gli **script in whitelist** (es. la ricerca Fuse) sopravvivono sempre.
- **Trasformazione del dominio**: inserisci l'URL di produzione (es. `https://www.esempio.com/`) e ogni riferimento all'host attuale viene riscritto — protocollo incluso — in link, canonical, og:url, hreflang, srcset, CSS, JSON-LD (anche escapati), sitemap e robots. Funziona anche tra domini completamente diversi.
- **HTTPS forzato** (anti mixed-content) + meta `upgrade-insecure-requests` come rete di sicurezza.
- Sitemap ripulite dai feed, `robots.txt` riscritto, `ads.txt` esportato o generato dall'ID AdSense.
- Iniezione di **Google Analytics 4** e **Google AdSense**.
- **Audit finale** scaricabile: link/risorse interne rotte + controlli SEO (title, meta description, canonical, H1, lang, alt, URL http o dell'host di origine residui).

### Deploy su GitHub
- **Motore "pacchetto git"** (default): gli oggetti git vengono costruiti in PHP e spediti in **un solo upload** sul protocollo nativo (`git-receive-pack`) — nessun rate limit dell'API REST, nessun binario `git` richiesto. Fallback automatico al motore API REST (parallelo, con deduplica SHA e gestione del rate limit).
- **Deduplica**: gli SHA git sono calcolati in locale; vengono trasferiti solo i file nuovi o modificati.
- Due branch: `raw` (export originale) e `main` (sito ottimizzato). `README.md`, `.gitignore` e `CNAME` del repository vengono preservati; push saltato se non ci sono modifiche; repository vuoti e branch mancanti gestiti.
- Repository **auto-mappato dal dominio** di produzione (es. `www.esempio.com`), con override.
- Token salvato **cifrato** (AES-256-CBC con i salt di WordPress).

### Esecuzione e gestione
- Pipeline **in background** a cicli con ripresa automatica e watchdog WP-Cron: si può chiudere la pagina.
- Export manuale o **pianificato** (giornaliero/settimanale).
- Dashboard nativa WordPress: stato, barra di avanzamento, log in tempo reale.
- Pagina **Artefatti**: download di ZIP raw/ottimizzato, report e log; eliminazione singola o totale; retention configurabile.

## Requisiti

- WordPress 5.8+ (consigliato 6.x)
- PHP 7.4+ con estensione `dom`; `imagick` o `gd` per la conversione WebP; `zip` per gli ZIP scaricabili
- Un repository GitHub e un token con permesso `repo` (classic) o `Contents: read & write` (fine-grained)

## Installazione

1. Scarica `static-export-deploy.zip` dall'ultima [release](../../releases) (oppure clona il repo e impacchettalo).
2. WordPress → *Plugin → Aggiungi nuovo → Carica plugin* → attiva.
3. *Static Export → Impostazioni*: inserisci **token** e **proprietario** GitHub.
4. (Consigliato) imposta l'**URL di produzione**, es. `https://www.esempio.com/`.
5. *Static Export → Dashboard* → **Avvia export & deploy**.

## Come funziona

```
crawl ──► zip raw ──► deploy branch raw ──► copia di lavoro
      ──► ottimizzazione (WebP, HTML, dominio, https, GA/Ads)
      ──► audit SEO/link ──► zip main ──► deploy branch main
```

Ogni fase lavora a lotti dentro un budget di tempo (default 20 s) e salva lo stato su disco: il processo riprende da dove si era fermato anche dopo timeout o riavvii. Gli artefatti vivono in `wp-content/uploads/sed-export/` (protetto da `.htaccess`).

## Sviluppo e release

Il workflow [`build-release.yml`](.github/workflows/build-release.yml) impacchetta automaticamente il plugin: alla pubblicazione di una **release** su GitHub, crea `static-export-deploy.zip` (cartella installabile con sorgenti, `readme.txt` e licenza) e lo allega alla release.

Per rilasciare una nuova versione:
1. aggiorna `Version` in `static-export-deploy.php`, `SED_VERSION` e `Stable tag` in `readme.txt`;
2. aggiorna [`CHANGELOG.md`](CHANGELOG.md);
3. crea un tag (es. `v1.7.1`) e pubblica la release: lo ZIP arriva da solo.

## Licenza

[GPL-2.0-or-later](LICENSE)
