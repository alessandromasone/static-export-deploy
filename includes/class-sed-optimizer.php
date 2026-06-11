<?php
/**
 * Ottimizzatore: port in PHP delle logiche dello script Python originale.
 *
 *  - conversione PNG/JPG/JPEG -> WebP (Imagick se disponibile, fallback GD)
 *    con rispetto dell'orientamento EXIF e della trasparenza
 *  - riscrittura dei riferimenti immagine verso .webp (boundary-aware) in
 *    HTML, CSS, JSON, XML, sitemap e srcset
 *  - sostituzione del sottodominio di staging con quello di produzione
 *    (admin.<dominio>.<ext> -> www.<dominio>.<ext>) preservando dominio e TLD
 *  - pulizia HTML basata su parser DOM: commenti, <script> (tranne JSON-LD),
 *    preload/prefetch di JS, handler on*, href javascript:, promozione
 *    lazy-load, unwrap <noscript>, minificazione conservativa
 *  - pulizia sitemap (/feed/) e riscrittura robots.txt
 *  - gestione 404_not_found -> 404.html, rimozione cartelle feed/wp-json
 *  - iniezione GA4 (gtag.js) e Google AdSense nel <head>
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SED_Optimizer {

	const EXT_IMAGES = array( 'png', 'jpg', 'jpeg' );
	const EXT_TEXT   = array( 'html', 'htm', 'css', 'json', 'xml', 'txt', 'md' );

	/** @var string Cartella sorgente/destinazione (lavora in-place su /opt). */
	private $dir;

	/** @var array Impostazioni del job (snapshot al momento dell'avvio). */
	private $opts;

	/** @var string Regex host di staging. */
	private $staging_regex;

	/** @var string|null Regex per forzare https sugli URL interni. */
	private $https_regex = null;

	/** @var array|null Mappatura host: ['url_regex','bare_regex','host','scheme']. */
	private $host_map = null;

	public function __construct( $opt_dir, $opts ) {
		$this->dir  = untrailingslashit( $opt_dir );
		$this->opts = $opts;

		// Port di _compila_regex_staging(): cattura il dominio registrabile
		// (>=2 etichette) dopo il sottodominio di staging; il lookbehind evita
		// falsi positivi tipo 'subadmin.example.com'.
		$this->staging_regex = '/(?<![\w.\-])' . preg_quote( $this->opts['sub_staging'], '/' ) . '\.([a-z0-9\-]+(?:\.[a-z0-9\-]+)+)/i';

		// Mappatura diretta host attuale -> URL di produzione (se impostato):
		// sostituisce il meccanismo a sottodomini e cambia anche il protocollo.
		if ( ! empty( $opts['target_host'] ) && ! empty( $opts['source_host'] ) && $opts['target_host'] !== $opts['source_host'] ) {
			$this->host_map = array(
				'host'       => $opts['target_host'],
				'scheme'     => ! empty( $opts['target_scheme'] ) ? $opts['target_scheme'] : 'https',
				// URL completi: http(s)://host e protocol-relative //host,
				// incluse le forme escapate dei JSON (\/\/).
				'url_regex'  => '#(https?:)?(\\\\?/\\\\?/)' . preg_quote( $opts['source_host'], '#' ) . '(?![a-z0-9\-.])#i',
				// Host "nudo" nel testo (JSON-LD, meta, email sul dominio...).
				'bare_regex' => '/(?<![\w.\-])' . preg_quote( $opts['source_host'], '/' ) . '(?![a-z0-9\-.])/i',
			);
		}

		// http -> https per qualsiasi sottodominio del dominio del sito,
		// incluse le forme escapate dei JSON (http:\/\/...). Il lookahead
		// finale evita i domini-esca (es. dominio.com.evil.com).
		if ( ! empty( $this->opts['force_https'] ) && ! empty( $this->opts['site_domain'] ) ) {
			$this->https_regex = '#http:(\\\\?/\\\\?/)((?:[a-z0-9\-]+\.)*' . preg_quote( $this->opts['site_domain'], '#' ) . ')(?![a-z0-9\-.])#i';
		}
	}

	/**
	 * Catena di sostituzioni testuali comune a tutti i formati:
	 * 1. host attuale -> URL di produzione (se impostato, protocollo incluso)
	 *    oppure staging -> produzione a sottodomini (admin.* -> www.*)
	 * 2. http -> https sugli URL interni (mixed content)
	 * 3. estensioni immagine -> .webp
	 */
	public function apply_common_replacements( $content ) {
		if ( $this->host_map ) {
			$content = $this->replace_host( $content );
		} else {
			$content = $this->replace_staging_domain( $content );
		}
		$content = $this->force_https( $content );
		return $this->replace_image_extensions( $content );
	}

	/**
	 * Mappa ogni riferimento all'host attuale verso l'host di produzione:
	 *  - https://attuale/... e http://attuale/... -> <scheme-prod>://prod/...
	 *  - //attuale/...  -> //prod/...   (resta protocol-relative)
	 *  - https:\/\/attuale\/... (JSON escapato) -> idem
	 *  - "attuale" nudo -> "prod"
	 */
	public function replace_host( $content ) {
		if ( ! $this->host_map ) {
			return $content;
		}
		$map     = $this->host_map;
		$content = preg_replace_callback( $map['url_regex'], function ( $m ) use ( $map ) {
			if ( '' !== $m[1] ) {
				// Con schema esplicito: si impone quello di produzione.
				return $map['scheme'] . ':' . $m[2] . $map['host'];
			}
			return $m[2] . $map['host']; // Protocol-relative: resta tale.
		}, $content );
		return preg_replace( $map['bare_regex'], $map['host'], $content );
	}

	/**
	 * Forza https:// su tutti i riferimenti interni al dominio del sito.
	 * Senza questo, un sito statico servito in HTTPS che referenzia risorse
	 * http:// (font, immagini, CSS) viene bloccato dal browser (mixed content).
	 */
	public function force_https( $content ) {
		if ( ! $this->https_regex ) {
			return $content;
		}
		return preg_replace( $this->https_regex, 'https:$1$2', $content );
	}

	/* ------------------------------------------------------------------ */
	/* Preparazione struttura (una tantum, prima del batch)                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Gestione 404_not_found -> 404.html e rimozione cartelle feed/wp-json.
	 */
	public function prepare_structure() {
		// 404_not_found/index.html -> 404.html (convenzione GitHub Pages).
		$dir_404  = $this->dir . '/404_not_found';
		$file_404 = $dir_404 . '/index.html';
		if ( file_exists( $file_404 ) ) {
			@rename( $file_404, $this->dir . '/404.html' );
		}
		if ( is_dir( $dir_404 ) ) {
			self::rrmdir( $dir_404 );
		}

		// Rimozione ricorsiva delle cartelle inutili.
		$this->remove_dirs_named( array( 'feed', 'wp-json' ) );

		// ads.txt: se il sito non lo espone gia', viene generato dall'ID
		// AdSense configurato (formato standard IAB).
		$this->ensure_ads_txt();

		SED_Logger::log( 'Struttura preparata: 404.html generato, cartelle feed/wp-json rimosse.' );
	}

	/**
	 * Garantisce la presenza di ads.txt nella root dell'export.
	 *  - se il crawler lo ha gia' scaricato, viene lasciato (le sostituzioni
	 *    di dominio vengono comunque applicate dal normale flusso .txt);
	 *  - altrimenti, se e' configurato un ID AdSense e l'opzione e' attiva,
	 *    viene creato: "google.com, pub-XXXX, DIRECT, f08c47fec0942fa0".
	 */
	private function ensure_ads_txt() {
		$path = $this->dir . '/ads.txt';
		if ( file_exists( $path ) ) {
			SED_Logger::log( "ads.txt gia' presente nell'export: mantenuto." );
			return;
		}
		if ( empty( $this->opts['ads_txt'] ) ) {
			return;
		}
		$ads = trim( (string) $this->opts['adsense_id'] );
		if ( '' === $ads ) {
			return;
		}
		// ca-pub-XXXX -> pub-XXXX (formato richiesto da ads.txt).
		$pub = preg_replace( '/^ca-/i', '', $ads );
		if ( 0 !== stripos( $pub, 'pub-' ) ) {
			$pub = 'pub-' . ltrim( $pub, '-' );
		}
		file_put_contents( $path, 'google.com, ' . $pub . ', DIRECT, f08c47fec0942fa0' . "\n" );
		SED_Logger::log( 'ads.txt generato (' . $pub . ').' );
	}

	private function remove_dirs_named( $names ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			if ( $item->isDir() && in_array( $item->getBasename(), $names, true ) ) {
				self::rrmdir( $item->getPathname() );
			}
		}
	}

	/**
	 * Elenco di tutti i file da elaborare (path relativi).
	 */
	public function list_files() {
		$out      = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->dir, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $item ) {
			if ( $item->isFile() ) {
				$out[] = ltrim( str_replace( '\\', '/', substr( $item->getPathname(), strlen( $this->dir ) ) ), '/' );
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Rimuove le cartelle rimaste vuote dopo l'elaborazione.
	 */
	public function remove_empty_dirs() {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() ); // Fallisce silenziosamente se non vuota.
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Elaborazione singolo file (port di elabora_singolo_file)             */
	/* ------------------------------------------------------------------ */

	/**
	 * @param string $rel Path relativo del file.
	 * @return string|null Messaggio di errore o null se ok.
	 */
	public function process_file( $rel ) {
		$path = $this->dir . '/' . $rel;
		if ( ! file_exists( $path ) ) {
			return null;
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		// --- JavaScript: rimosso di default, mantenuto (e riscritto) se keep_js
		// o se il path e' in whitelist (es. script della ricerca Fuse).
		if ( 'js' === $ext ) {
			if ( empty( $this->opts['keep_js'] ) && ! $this->matches_allowlist( $rel ) ) {
				@unlink( $path );
				return null;
			}
			$content = @file_get_contents( $path );
			if ( false === $content ) {
				return null;
			}
			$new = $this->apply_common_replacements( $content );
			if ( $new !== $content ) {
				file_put_contents( $path, $new );
			}
			return null;
		}

		// --- Immagini -> WebP.
		if ( in_array( $ext, self::EXT_IMAGES, true ) ) {
			return $this->convert_to_webp( $path );
		}

		// --- File di testo.
		if ( in_array( $ext, self::EXT_TEXT, true ) ) {
			$content = @file_get_contents( $path );
			if ( false === $content ) {
				return "Errore lettura di {$rel}";
			}
			// Salta i file non-UTF8 (come lo script Python).
			if ( ! preg_match( '//u', $content ) ) {
				return "AVVISO: '{$rel}' non e' UTF-8: saltato.";
			}

			$name = strtolower( pathinfo( $path, PATHINFO_FILENAME ) );
			$new  = $content;

			if ( 'html' === $ext || 'htm' === $ext ) {
				$new = $this->clean_html( $content );
			} elseif ( 'xml' === $ext && SED_Crawler::looks_like_sitemap( $content ) ) {
				// Dal contenuto, non dal nome: copre wp-sitemap-*.xml di
				// WordPress e qualunque sitemap annidata con nome custom.
				$new = $this->clean_sitemap( $content );
			} elseif ( 'txt' === $ext && 'robots' === $name ) {
				$new = $this->clean_robots( $content );
			} else {
				// CSS, JSON, XML generici, MD, TXT: solo sostituzioni testuali sicure.
				$new = $this->apply_common_replacements( $content );
			}

			if ( $new !== $content ) {
				if ( false === file_put_contents( $path, $new ) ) {
					return "Errore scrittura di {$rel}";
				}
			}
			return null;
		}

		return null; // Altri formati: copiati cosi' come sono.
	}

	/* ------------------------------------------------------------------ */
	/* Sostituzioni testuali comuni                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * admin.<dominio>.<ext> -> www.<dominio>.<ext> (host, non schema: copre
	 * http/https, protocol-relative, URL escapati in JSON e occorrenze nude).
	 */
	public function replace_staging_domain( $content ) {
		$prod = $this->opts['sub_prod'];
		return preg_replace( $this->staging_regex, $prod . '.$1', $content );
	}

	/**
	 * .png/.jpg/.jpeg -> .webp SOLO quando l'estensione chiude un token
	 * URL/path (seguita da apice, parentesi, virgola, ?, #, tag o fine).
	 */
	public function replace_image_extensions( $content ) {
		return preg_replace( '/\.(png|jpe?g)(?=["\'\\),?#<>]|$)/i', '.webp', $content );
	}

	/**
	 * Riscrive le estensioni immagine dentro un srcset preservando i
	 * descrittori (1x / 2x / Nw).
	 */
	private function rewrite_srcset( $value ) {
		$parts = array();
		foreach ( explode( ',', $value ) as $part ) {
			$tokens = preg_split( '/\s+/', trim( $part ) );
			if ( empty( $tokens[0] ) ) {
				continue;
			}
			$tokens[0] = preg_replace( '/\.(png|jpe?g)$/i', '.webp', $tokens[0] );
			$parts[]   = implode( ' ', $tokens );
		}
		return implode( ', ', $parts );
	}

	/* ------------------------------------------------------------------ */
	/* Whitelist JavaScript                                                  */
	/* ------------------------------------------------------------------ */

	/** @var string[]|null */
	private $allowlist = null;

	private function allowlist() {
		if ( null === $this->allowlist ) {
			$this->allowlist = isset( $this->opts['js_allowlist'] ) && is_array( $this->opts['js_allowlist'] )
				? $this->opts['js_allowlist']
				: SED_Settings::js_allowlist();
		}
		return $this->allowlist;
	}

	private function matches_allowlist( $haystack ) {
		$haystack = (string) $haystack;
		if ( '' === $haystack ) {
			return false;
		}
		foreach ( $this->allowlist() as $needle ) {
			if ( false !== stripos( $haystack, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Uno <script> e' preservato se src, id o contenuto contengono uno dei
	 * pattern in whitelist (default: fuse.js / fuse-js / sfs- / new Fuse().
	 */
	private function script_is_allowed( $node ) {
		return $this->matches_allowlist( $node->getAttribute( 'src' ) )
			|| $this->matches_allowlist( $node->getAttribute( 'id' ) )
			|| $this->matches_allowlist( $node->textContent );
	}

	/* ------------------------------------------------------------------ */
	/* Pulizia HTML (port di pulisci_codice_html)                            */
	/* ------------------------------------------------------------------ */

	public function clean_html( $html ) {
		$remove_js = empty( $this->opts['keep_js'] );

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$loaded = $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		if ( ! $loaded ) {
			// Parser fallito: applica solo le sostituzioni testuali sicure.
			return $this->apply_common_replacements( $html );
		}
		$xpath = new DOMXPath( $dom );

		// 1) Commenti HTML.
		foreach ( iterator_to_array( $xpath->query( '//comment()' ) ) as $node ) {
			$node->parentNode->removeChild( $node );
		}

		// 2) Script: rimuovi tutto tranne i JSON-LD (dati strutturati SEO) e
		// gli script in whitelist (es. la ricerca Fuse di wp-static-fuse-search:
		// fuse.js da CDN + script inline con la logica del dropdown).
		if ( $remove_js ) {
			foreach ( iterator_to_array( $dom->getElementsByTagName( 'script' ) ) as $node ) {
				$type = strtolower( trim( $node->getAttribute( 'type' ) ) );
				if ( 'application/ld+json' === $type ) {
					continue;
				}
				if ( $this->script_is_allowed( $node ) ) {
					continue;
				}
				$node->parentNode->removeChild( $node );
			}
		}

		// 3) <link> verso risorse JS ormai inesistenti.
		if ( $remove_js ) {
			foreach ( iterator_to_array( $dom->getElementsByTagName( 'link' ) ) as $node ) {
				$rels  = array_map( 'strtolower', preg_split( '/\s+/', trim( $node->getAttribute( 'rel' ) ) ) );
				$as    = strtolower( trim( $node->getAttribute( 'as' ) ) );
				$href  = strtolower( trim( $node->getAttribute( 'href' ) ) );
				$clean = preg_replace( '/[?#].*$/', '', $href );

				$is_modulepreload = in_array( 'modulepreload', $rels, true );
				$is_script_pre    = ( in_array( 'preload', $rels, true ) || in_array( 'prefetch', $rels, true ) ) && 'script' === $as;
				$points_to_js     = '.js' === substr( $clean, -3 );

				if ( ( $is_modulepreload || $is_script_pre || $points_to_js ) && ! $this->matches_allowlist( $href ) ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}

		// 4) Pulizia attributi su tutti i tag.
		foreach ( iterator_to_array( $xpath->query( '//*' ) ) as $node ) {
			if ( ! $node->hasAttributes() ) {
				continue;
			}
			$names = array();
			foreach ( $node->attributes as $attr ) {
				$names[] = $attr->name;
			}

			if ( $remove_js ) {
				// 4a) handler inline on*.
				foreach ( $names as $name ) {
					if ( 0 === stripos( $name, 'on' ) ) {
						$node->removeAttribute( $name );
					}
				}
				// 4b) href/src javascript:.
				foreach ( array( 'href', 'src' ) as $name ) {
					$val = $node->getAttribute( $name );
					if ( $val && 0 === stripos( ltrim( $val ), 'javascript:' ) ) {
						$node->removeAttribute( $name );
					}
				}
			}

			// 4c) Promozione lazy-load -> attributi reali (senza JS le immagini
			// lazy non si caricherebbero piu' e il crawler non le vedrebbe).
			foreach ( array( 'data-src', 'data-lazy-src' ) as $name ) {
				if ( $node->hasAttribute( $name ) && ! $node->getAttribute( 'src' ) ) {
					$node->setAttribute( 'src', $node->getAttribute( $name ) );
				}
			}
			foreach ( array( 'data-srcset', 'data-lazy-srcset' ) as $name ) {
				if ( $node->hasAttribute( $name ) && ! $node->getAttribute( 'srcset' ) ) {
					$node->setAttribute( 'srcset', $node->getAttribute( $name ) );
				}
			}
			foreach ( array( 'data-src', 'data-lazy-src', 'data-srcset', 'data-lazy-srcset' ) as $name ) {
				if ( $node->hasAttribute( $name ) ) {
					$node->removeAttribute( $name );
				}
			}
		}

		// 5) Unwrap <noscript>: il sito ora e' privo di JS, il loro contenuto
		// e' il fallback corretto da mostrare sempre.
		if ( $remove_js ) {
			foreach ( iterator_to_array( $dom->getElementsByTagName( 'noscript' ) ) as $node ) {
				$parent = $node->parentNode;
				// Il contenuto di noscript puo' essere stato parsato come testo:
				// in tal caso lo ri-parsiamo come frammento HTML.
				$inner = '';
				foreach ( $node->childNodes as $child ) {
					$inner .= $dom->saveHTML( $child );
				}
				if ( '' !== trim( $inner ) ) {
					$frag = $dom->createDocumentFragment();
					// Decodifica eventuali entita' se il contenuto era testo puro.
					if ( false === strpos( $inner, '<' ) && false !== strpos( $inner, '&lt;' ) ) {
						$inner = html_entity_decode( $inner );
					}
					$tmp = new DOMDocument();
					libxml_use_internal_errors( true );
					$tmp->loadHTML( '<?xml encoding="UTF-8"><div>' . $inner . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );
					libxml_clear_errors();
					$container = $tmp->getElementsByTagName( 'div' )->item( 0 );
					if ( $container ) {
						foreach ( iterator_to_array( $container->childNodes ) as $child ) {
							$frag->appendChild( $dom->importNode( $child, true ) );
						}
					}
					$parent->insertBefore( $frag, $node );
				}
				$parent->removeChild( $node );
			}
		}

		// 5b) Riscrittura immagini -> .webp a livello DOM (attributi e srcset):
		// i riferimenti con spazio (srcset) sono gestiti senza toccare il testo.
		foreach ( iterator_to_array( $xpath->query( '//*' ) ) as $node ) {
			foreach ( array( 'src', 'href', 'poster', 'content' ) as $name ) {
				$val = $node->getAttribute( $name );
				if ( $val ) {
					$new = $this->replace_image_extensions( $val );
					if ( $new !== $val ) {
						$node->setAttribute( $name, $new );
					}
				}
			}
			if ( $node->hasAttribute( 'srcset' ) ) {
				$node->setAttribute( 'srcset', $this->rewrite_srcset( $node->getAttribute( 'srcset' ) ) );
			}
		}

		// 6) Minificazione conservativa: collassa gli spazi nei nodi testo,
		// preservando pre/textarea/style/script (JSON-LD).
		$text_nodes = $xpath->query( '//text()[not(ancestor::pre) and not(ancestor::textarea) and not(ancestor::style) and not(ancestor::script)]' );
		foreach ( iterator_to_array( $text_nodes ) as $node ) {
			$new = preg_replace( '/\s+/u', ' ', $node->nodeValue );
			if ( $new !== $node->nodeValue ) {
				$node->nodeValue = $new;
			}
		}

		// 6b) Iniezione GA4 + AdSense: DOPO la rimozione degli script, cosi'
		// sono gli unici a "sopravvivere" insieme ai JSON-LD.
		$this->inject_tracking( $dom );

		// 6c) Rete di sicurezza anti mixed-content: il browser aggiorna da
		// solo a https qualsiasi risorsa http residua (anche esterna).
		$this->inject_upgrade_insecure( $dom );

		$out = $dom->saveHTML();
		$out = str_replace( '<?xml encoding="UTF-8">', '', $out );

		// 7) Sostituzioni testuali finali (coprono anche il testo dei JSON-LD
		// e degli script in whitelist): staging -> prod, http -> https, .webp.
		$out = $this->apply_common_replacements( $out );

		// Rimuove le righe completamente vuote.
		$rows = array_filter( preg_split( '/\r\n|\r|\n/', $out ), function ( $row ) {
			return '' !== trim( $row );
		} );
		return implode( "\n", $rows ) . "\n";
	}

	/**
	 * Inietta <meta http-equiv="Content-Security-Policy"
	 * content="upgrade-insecure-requests"> in testa al <head>.
	 * Il browser converte automaticamente in https ogni sottorisorsa http
	 * residua (incluse quelle esterne che la riscrittura non tocca).
	 */
	private function inject_upgrade_insecure( DOMDocument $dom ) {
		if ( empty( $this->opts['force_https'] ) ) {
			return;
		}
		$head = $dom->getElementsByTagName( 'head' )->item( 0 );
		if ( ! $head ) {
			return;
		}
		foreach ( $head->getElementsByTagName( 'meta' ) as $meta ) {
			if ( 'content-security-policy' === strtolower( $meta->getAttribute( 'http-equiv' ) ) ) {
				return; // Gia' presente: non duplicare.
			}
		}
		$meta = $dom->createElement( 'meta' );
		$meta->setAttribute( 'http-equiv', 'Content-Security-Policy' );
		$meta->setAttribute( 'content', 'upgrade-insecure-requests' );
		$head->insertBefore( $meta, $head->firstChild );
	}

	/**
	 * Inietta GA4 (gtag.js) e AdSense in testa al <head>. Idempotente.
	 */
	private function inject_tracking( DOMDocument $dom ) {
		$ga  = trim( (string) $this->opts['ga_id'] );
		$ads = trim( (string) $this->opts['adsense_id'] );
		if ( '' === $ga && '' === $ads ) {
			return;
		}

		$head = $dom->getElementsByTagName( 'head' )->item( 0 );
		if ( ! $head ) {
			$head = $dom->createElement( 'head' );
			$html = $dom->getElementsByTagName( 'html' )->item( 0 );
			if ( $html ) {
				$html->insertBefore( $head, $html->firstChild );
			} else {
				$dom->appendChild( $head );
			}
		}

		$to_insert = array();

		// --- Google AdSense (Auto ads) ---
		if ( '' !== $ads ) {
			$already = false;
			foreach ( $head->getElementsByTagName( 'script' ) as $s ) {
				if ( false !== strpos( $s->getAttribute( 'src' ), 'adsbygoogle.js' ) ) {
					$already = true;
					break;
				}
			}
			if ( ! $already ) {
				$node = $dom->createElement( 'script' );
				$node->setAttribute( 'async', '' );
				$node->setAttribute( 'src', 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . $ads );
				$node->setAttribute( 'crossorigin', 'anonymous' );
				$to_insert[] = $node;
			}
		}

		// --- Google Analytics (GA4 / gtag.js) ---
		if ( '' !== $ga ) {
			$already = false;
			foreach ( $head->getElementsByTagName( 'script' ) as $s ) {
				if ( false !== strpos( $s->getAttribute( 'src' ), 'googletagmanager.com/gtag/js' ) ) {
					$already = true;
					break;
				}
			}
			if ( ! $already ) {
				$loader = $dom->createElement( 'script' );
				$loader->setAttribute( 'async', '' );
				$loader->setAttribute( 'src', 'https://www.googletagmanager.com/gtag/js?id=' . $ga );

				$inline = $dom->createElement( 'script' );
				$inline->appendChild( $dom->createTextNode(
					'window.dataLayer = window.dataLayer || [];'
					. 'function gtag(){dataLayer.push(arguments);}'
					. "gtag('js', new Date());"
					. "gtag('config', '" . esc_js( $ga ) . "');"
				) );
				// GA per primo, cosi' si carica il prima possibile.
				array_unshift( $to_insert, $inline );
				array_unshift( $to_insert, $loader );
			}
		}

		foreach ( array_reverse( $to_insert ) as $node ) {
			$head->insertBefore( $node, $head->firstChild );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Sitemap / robots (port di pulisci_sitemap_xml / pulisci_robots_txt)  */
	/* ------------------------------------------------------------------ */

	public function clean_sitemap( $content ) {
		$content = preg_replace( '#<url>\s*<loc>[^<]*/feed/(?:[^<]*)?</loc>.*?</url>#is', '', $content );
		$content = preg_replace( '#<sitemap>\s*<loc>[^<]*/feed/(?:[^<]*)?</loc>.*?</sitemap>#is', '', $content );
		$content = $this->apply_common_replacements( $content );
		$rows    = array_filter( preg_split( '/\r\n|\r|\n/', $content ), function ( $row ) {
			return '' !== trim( $row );
		} );
		return implode( "\n", $rows );
	}

	public function clean_robots( $content ) {
		$sitemaps = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $content ) as $row ) {
			if ( 0 === stripos( trim( $row ), 'sitemap:' ) ) {
				$sitemaps[] = $this->apply_common_replacements( trim( $row ) );
			}
		}
		$out = array_merge( array( 'User-agent: *', 'Disallow:' ), $sitemaps );
		return implode( "\n", $out ) . "\n";
	}

	/* ------------------------------------------------------------------ */
	/* Conversione WebP                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Converte un'immagine in WebP ed elimina l'originale.
	 * In caso di errore l'originale NON viene eliminato (l'audit segnalera'
	 * l'eventuale riferimento .webp orfano).
	 */
	private function convert_to_webp( $path ) {
		$quality   = (int) $this->opts['webp_quality'];
		$webp_path = preg_replace( '/\.(png|jpe?g)$/i', '.webp', $path );

		// --- Imagick (preferito) ---
		if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
			try {
				$img = new Imagick( $path );
				if ( method_exists( $img, 'autoOrient' ) ) {
					$img->autoOrient(); // Rispetta l'orientamento EXIF.
				}
				$img->setImageFormat( 'webp' );
				$img->setImageCompressionQuality( $quality );
				$img->writeImage( $webp_path );
				$img->clear();
				$img->destroy();
				@unlink( $path );
				return null;
			} catch ( Exception $e ) {
				// Si tenta il fallback GD qui sotto.
			}
		}

		// --- GD ---
		if ( ! function_exists( 'imagewebp' ) ) {
			return 'AVVISO: ne\' Imagick ne\' GD/WebP disponibili: ' . basename( $path ) . ' non convertito.';
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$im  = null;

		try {
			if ( 'png' === $ext ) {
				$im = @imagecreatefrompng( $path );
				if ( $im ) {
					// Preserva palette e trasparenza.
					if ( function_exists( 'imagepalettetotruecolor' ) ) {
						imagepalettetotruecolor( $im );
					}
					imagealphablending( $im, false );
					imagesavealpha( $im, true );
				}
			} else {
				$im = @imagecreatefromjpeg( $path );
				// Rispetta l'orientamento EXIF (foto da smartphone).
				if ( $im && function_exists( 'exif_read_data' ) ) {
					$exif = @exif_read_data( $path );
					if ( ! empty( $exif['Orientation'] ) ) {
						switch ( (int) $exif['Orientation'] ) {
							case 3:
								$im = imagerotate( $im, 180, 0 );
								break;
							case 6:
								$im = imagerotate( $im, -90, 0 );
								break;
							case 8:
								$im = imagerotate( $im, 90, 0 );
								break;
						}
					}
				}
			}

			if ( ! $im ) {
				return 'Errore durante la conversione di ' . basename( $path ) . ': immagine non leggibile.';
			}

			if ( ! @imagewebp( $im, $webp_path, $quality ) ) {
				imagedestroy( $im );
				return 'Errore durante la conversione di ' . basename( $path );
			}
			imagedestroy( $im );
			@unlink( $path );
			return null;

		} catch ( Throwable $e ) {
			if ( $im ) {
				imagedestroy( $im );
			}
			return 'Errore durante la conversione di ' . basename( $path ) . ': ' . $e->getMessage();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Utility filesystem                                                    */
	/* ------------------------------------------------------------------ */

	public static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
		}
		@rmdir( $dir );
	}

	/**
	 * Copia ricorsiva di una cartella (usata per raw -> opt).
	 *
	 * @return int Numero di file copiati.
	 */
	public static function rcopy( $src, $dst ) {
		$count = 0;
		wp_mkdir_p( $dst );
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $item ) {
			$target = $dst . '/' . $iterator->getSubPathName();
			if ( $item->isDir() ) {
				wp_mkdir_p( $target );
			} else {
				wp_mkdir_p( dirname( $target ) );
				copy( $item->getPathname(), $target );
				$count++;
			}
		}
		return $count;
	}
}
