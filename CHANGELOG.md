# Changelog

Tutte le modifiche rilevanti di questo progetto sono documentate in questo file.
Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.1.0/) e il progetto adotta il [Semantic Versioning](https://semver.org/lang/it/).

## [1.8.0] - 2026-06-11
### Aggiunto
- Modulo **prestazioni** (Core Web Vitals): CSS interni piccoli (≤15 KiB) incorporati nelle pagine con riscrittura degli `url()` relativi; `font-display: swap` nei `@font-face` che non lo dichiarano; `width`/`height` automatici sulle immagini, `loading=lazy`/`decoding=async` con `fetchpriority=high` sulla prima immagine; preload dei font critici configurabile e preconnect automatico a Google Fonts.

## [1.7.1] - 2026-06-11
### Modificato
- Suggerimenti, placeholder ed esempi resi completamente generici (nessun riferimento a domini o account reali).
- Aggiunti file di repository: README, CHANGELOG, LICENSE (GPL-2.0), workflow di packaging automatico delle release.

## [1.7.0] - 2026-06-11
### Aggiunto
- Pagina **Artefatti** nel menu laterale: elenco degli export su disco con data, stato e spazio occupato; download di ZIP raw/ottimizzato, report SEO e log; eliminazione singola o di tutti gli archiviati (il job in esecuzione è protetto).
- Numero di export conservati su disco configurabile (1–10) nelle impostazioni Avanzate.

## [1.6.2] - 2026-06-11
### Corretto
- Titoli dei blocchi in dashboard: gli stili compatti dei postbox sono ora inclusi nel plugin (il CSS di core che li definisce non viene caricato sulle pagine admin custom).

## [1.6.1] - 2026-06-11
### Corretto
- Riga Lingue della dashboard che mostrava i tag `<code>` come testo.
- Doppia scritta "Completato" accanto al badge di stato (anche per job salvati da versioni precedenti).

## [1.6.0] - 2026-06-11
### Aggiunto
- Navigazione tramite sottovoci del menu laterale: Dashboard / Impostazioni.
- Rilevamento lingue generalizzato: WPLingua, Polylang, WPML, TranslatePress e — per qualsiasi altro caso — analisi dei tag `hreflang` e dei percorsi `/xx/` ricorrenti della home (cache 12 h).
### Modificato
- Rifiniture interfaccia: badge e barra di stato corretti al primo caricamento, righe branch/sottodomini riformattate, descrizioni alleggerite.

## [1.5.1] - 2026-06-11
### Modificato
- Interfaccia ridisegnata in stile nativo WordPress: postbox standard, badge di stato, errori come notice, log richiudibile; impostazioni riorganizzate in sezioni.

## [1.5.0] - 2026-06-11
### Aggiunto
- Campo **URL di produzione**: il dominio attuale (rilevato automaticamente) viene trasformato in quello indicato in tutte le referenze, protocollo incluso. Funziona anche tra domini completamente diversi; il meccanismo a sottodomini resta come fallback.
- Audit: segnalazione `[DOMINIO]` dei riferimenti residui all'host di origine.

## [1.4.1] - 2026-06-11
### Aggiunto
- Supporto completo alle sitemap di default di WordPress (`wp-sitemap.xml`, figlie paginate, fogli di stile XSL).
- Riconoscimento delle sitemap dal contenuto con ricorsione illimitata; estrazione di `image:loc`, `video:loc` e hreflang `xhtml:link`.

## [1.4.0] - 2026-06-11
### Aggiunto
- HTTPS forzato sugli URL interni (anti mixed-content), incluse le forme escapate dei JSON; meta CSP `upgrade-insecure-requests`; controllo `[HTTPS]` nell'audit.

## [1.3.0] - 2026-06-10
### Aggiunto
- Motore di deploy **pacchetto git** (default): un solo upload sul protocollo nativo `git-receive-pack`, senza rate limit dell'API REST; fallback automatico al motore API.
- ZIP scaricabili dell'export originale e di quello ottimizzato.
### Corretto
- Retry con backoff sugli errori 5xx dell'API REST; lock di elaborazione atomico; pausa rate-limit idempotente.

## [1.2.0] - 2026-06-10
### Aggiunto
- Deduplica tramite SHA git calcolato in locale (vengono caricati solo i file nuovi o modificati).
- Upload dei blob in parallelo (configurabile) e gestione automatica del rate limit API (pausa e ripresa).

## [1.1.0] - 2026-06-10
### Aggiunto
- Whitelist JavaScript: la ricerca di wp-static-fuse-search (fuse.js + script inline) viene preservata anche con la rimozione del JS attiva; indici `static-search/*.json` inclusi automaticamente.
- `ads.txt`: esportato se già presente, altrimenti generato dall'ID AdSense.

## [1.0.0] - 2026-06-10
### Aggiunto
- Prima release: crawler integrato (multilingua, sitemap, pagina 404), ottimizzazione WebP/HTML/SEO, sostituzione dominio staging→produzione, GA4/AdSense, audit con report, deploy GitHub su branch raw+main via API, pipeline in background con watchdog, export pianificati, token cifrato.
