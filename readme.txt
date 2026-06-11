=== Static Export & Deploy ===
Contributors: alessandromasone
Tags: static site, export, github, webp, seo
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.7.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Esporta il sito in HTML statico, lo ottimizza (WebP, pulizia HTML, SEO) e lo pubblica su GitHub — tutto in background.

== Description ==

Static Export & Deploy trasforma il tuo sito WordPress (anche multilingua con WPLingua) in un sito statico pronto per GitHub Pages o qualsiasi CDN:

* **Crawler integrato** — esporta pagine, immagini, CSS, font e sitemap partendo da home, sitemap.xml, robots.txt e dalle home delle lingue (`/en/`, `/fr/`, ...) rilevate da qualunque plugin multilingua (WPLingua, Polylang, WPML, TranslatePress) o dai tag hreflang/percorsi della home. Cattura anche la pagina 404 (`404.html`).
* **Ottimizzazione automatica**:
  * conversione PNG/JPG → **WebP** (Imagick o GD, con EXIF e trasparenza) e riscrittura di tutti i riferimenti, srcset inclusi;
  * **pulizia HTML** basata su parser: rimozione commenti, JavaScript (opzionale, di default rimosso — i dati strutturati JSON-LD e gli script in whitelist restano), preload/prefetch di JS, handler `on*`, promozione delle immagini lazy-load, unwrap dei `<noscript>`, minificazione conservativa;
  * **ricerca Fuse preservata** — gli script di wp-static-fuse-search (fuse.js + dropdown) sopravvivono alla rimozione del JS e gli indici `uploads/static-search/*.json` vengono inclusi nell'export;
  * **ads.txt** — esportato se presente, altrimenti generato automaticamente dall'ID AdSense;
  * sostituzione del **sottodominio di staging** con quello di produzione (es. `admin.sito.com` → `www.sito.com`) e **HTTPS forzato** su tutti gli URL interni (niente mixed content) in HTML, CSS, JSON, sitemap;
  * sitemap ripulite dai feed e `robots.txt` riscritto;
  * iniezione di **Google Analytics 4** e **Google AdSense**.
* **Audit finale** — report scaricabile con link/risorse interne rotte e controlli SEO (title, meta description, canonical, H1, lang, alt, URL di staging residui).
* **Deploy su GitHub via API, veloce** (nessun git richiesto sul server): branch `raw` con l'export originale e branch `main` con il sito ottimizzato. Gli SHA git vengono calcolati in locale e confrontati con il repository: **vengono caricati solo i file nuovi o modificati**, in parallelo. README, .gitignore e CNAME vengono preservati; push saltato se non ci sono modifiche; **rate limit API gestito automaticamente** (pausa e ripresa dopo il reset).
* **Tutto in background** — pipeline a lotti con ripresa automatica e watchdog WP-Cron: puoi chiudere la pagina, il server continua a lavorare. Export manuale o pianificato (giornaliero/settimanale).

Il repository di destinazione viene **mappato automaticamente dal dominio** (`www.tuodominio.com`), con possibilita' di override nelle impostazioni. Il token GitHub e' salvato cifrato.

== Installation ==

1. Carica la cartella `static-export-deploy` in `/wp-content/plugins/` (o installa lo ZIP da Plugin → Aggiungi nuovo).
2. Attiva il plugin.
3. Vai su **Static Export → Impostazioni**, inserisci il token GitHub (PAT con permesso `repo` o `Contents: read/write`) e il proprietario (owner).
4. Dalla **Dashboard** premi "Avvia export & deploy".

== Frequently Asked Questions ==

= Il plugin richiede git sul server? =
No: il deploy avviene interamente tramite le API REST di GitHub.

= Funziona con i siti multilingua? =
Si': le lingue di WPLingua vengono rilevate automaticamente (e sono comunque scoperte dai link hreflang); puoi anche specificare gli slug manualmente.

= Cosa succede al JavaScript? =
Di default viene rimosso completamente (restano solo i dati strutturati JSON-LD e i tag GA4/AdSense iniettati). Puoi mantenerlo con un'opzione.

== Changelog ==

= 1.7.1 =
* Placeholder ed esempi resi generici; aggiunti file di repository (README, CHANGELOG, LICENSE, workflow di release).

= 1.7.0 =
* Nuova pagina "Artefatti" nel menu laterale: elenco di tutti gli export su disco con data, stato, spazio occupato; download di ZIP raw/ottimizzato, report SEO e log; eliminazione singola o di tutti gli archiviati (il job in esecuzione e' protetto).
* Numero di export conservati su disco configurabile (1-10, prima fisso a 2) nelle impostazioni Avanzate.

= 1.6.2 =
* Fix titoli dei blocchi in dashboard: gli stili compatti dei postbox ora sono inclusi nel plugin (il CSS di core che li definisce non viene caricato sulle pagine admin custom).

= 1.6.1 =
* Fix dashboard: la riga Lingue mostrava i tag <code> come testo; eliminata la doppia scritta "Completato" accanto al badge di stato (anche per job salvati da versioni precedenti).

= 1.6.0 =
* Navigazione spostata nelle sottovoci del menu laterale: Static Export -> Dashboard / Impostazioni (niente piu' tab interne alla pagina).
* Rilevamento lingue generalizzato: WPLingua, Polylang, WPML, TranslatePress e — per qualsiasi altro caso — analisi dei tag hreflang e dei percorsi /xx/ ricorrenti della home (con cache 12h, aggiornata al salvataggio impostazioni).
* Rifiniture interfaccia: badge e barra di stato corretti al primo caricamento, righe branch/sottodomini riformattate con etichette, descrizioni alleggerite.

= 1.5.1 =
* Interfaccia ridisegnata in stile nativo WordPress: dashboard con postbox standard, badge di stato, errori come notice, log richiudibile; impostazioni riorganizzate in sezioni chiare (GitHub, Dominio, Ottimizzazione, Crawler e lingue, Esecuzione, Avanzate) con descrizioni concise.

= 1.5.0 =
* Nuovo campo "URL di produzione": inserisci l'indirizzo completo (es. https://www.sito.com/) e il dominio attuale — rilevato automaticamente — viene trasformato in quello indicato in TUTTE le referenze, protocollo incluso: link, canonical, og:url, hreflang, srcset, CSS, JSON-LD (anche escapati), sitemap, robots, email sul dominio.
* Funziona anche tra domini completamente diversi (es. staging.host.it -> www.sito.com), cosa impossibile col meccanismo a sottodomini (che resta come fallback).
* Con l'URL di produzione impostato il repository auto-mappato diventa esattamente l'host di destinazione (es. www.sito.com) e l'audit segnala i riferimenti residui all'host di origine ([DOMINIO]).

= 1.4.1 =
* Sitemap di default di WordPress completamente supportate: wp-sitemap.xml, tutte le figlie paginate (posts, pages, taxonomies, users) e i fogli di stile XSL.
* Riconoscimento delle sitemap dal CONTENUTO (<sitemapindex>/<urlset>) anziche' dal nome del file: la ricorsione indice -> figlie -> nipoti funziona con qualunque profondita' e qualunque nome.
* Dalle sitemap vengono estratti anche <image:loc>, <video:loc> e gli hreflang <xhtml:link> (versioni linguistiche).
* Anche la pulizia delle sitemap (rimozione /feed/, dominio, https) ora riconosce i file dal contenuto.

= 1.4.0 =
* HTTPS forzato (anti mixed-content): tutti i riferimenti http:// verso il dominio del sito — qualunque sottodominio, anche nelle forme escapate dei JSON — vengono riscritti in https:// in HTML, CSS, JSON, sitemap e robots.txt.
* Meta CSP "upgrade-insecure-requests" iniettato nel head come rete di sicurezza per le risorse esterne ancora in http.
* Nuovo controllo [HTTPS] nel report di audit: segnala le pagine con URL interni http residui.

= 1.3.0 =
* Nuovo motore di deploy "pacchetto git" (default): tutti gli oggetti git vengono costruiti in PHP e spediti in UN SOLO upload sul protocollo git nativo (git-receive-pack) — niente piu' rate limit dell'API REST, niente 502 sui tree grandi. Fallback automatico al motore API REST.
* ZIP scaricabili dalla dashboard sia per il sito base (raw.zip) sia per il sito ottimizzato (main.zip).
* Retry automatico con backoff sugli errori 5xx dell'API REST (fix del 502 su /git/trees).
* Lock di elaborazione atomico (niente piu' log duplicati da tick concorrenti) e pausa rate-limit idempotente.

= 1.2.0 =
* Deploy molto piu' veloce: deduplica tramite SHA git calcolato in locale (vengono caricati solo i file nuovi o modificati; al secondo deploy spesso zero upload).
* Upload dei blob in parallelo (configurabile, default 8 richieste simultanee).
* Gestione automatica del rate limit API GitHub: pausa preventiva e ripresa automatica dopo il reset, senza far fallire il job.

= 1.1.0 =
* Whitelist JavaScript: la ricerca di wp-static-fuse-search (fuse.js + script inline) viene preservata anche con la rimozione del JS attiva.
* Gli indici di ricerca `wp-content/uploads/static-search/*.json` vengono inclusi automaticamente nell'export.
* ads.txt: esportato se gia' presente sul sito, altrimenti generato dall'ID AdSense (google.com, pub-XXXX, DIRECT, f08c47fec0942fa0).

= 1.0.0 =
* Prima release: crawler integrato, ottimizzazione WebP/HTML/SEO, deploy GitHub raw+main in background.
