<?php
/**
 * Crawler integrato: visita il sito partendo da home, sitemap, robots.txt e
 * dalle home delle lingue WPLingua (/en/, /fr/, ...), segue tutti i link e le
 * risorse interne (CSS, JS, immagini, font) e salva ogni cosa su filesystem
 * rispecchiando la struttura degli URL.
 *
 * Lavora "a lotti": process_chunk() elabora URL finche' il budget di tempo non
 * si esaurisce, salvando lo stato su disco. E' quindi adatto all'esecuzione in
 * background tramite la coda (SED_Queue).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SED_Crawler {

	/** @var string Cartella di destinazione dell'export "raw". */
	private $dest;

	/** @var string File JSON con lo stato del crawl. */
	private $state_file;

	/** @var array Stato: pending[], seen{url:1}, done, failed[]. */
	private $state;

	/** @var string Host del sito (solo questo host viene scaricato). */
	private $host;

	/** @var string Scheme + host base, es. https://admin.sito.com */
	private $base;

	/** @var string[] Path esclusi (prefissi). */
	private $excludes;

	public function __construct( $job_dir ) {
		$this->dest       = trailingslashit( $job_dir ) . 'raw';
		$this->state_file = trailingslashit( $job_dir ) . 'crawl-state.json';
		$this->host       = SED_Settings::site_host();
		$scheme           = wp_parse_url( home_url(), PHP_URL_SCHEME ) ?: 'https';
		$this->base       = $scheme . '://' . $this->host;
		$this->excludes   = SED_Settings::exclude_paths();
		$this->load_state();
	}

	/* ------------------------------------------------------------------ */
	/* Stato                                                                */
	/* ------------------------------------------------------------------ */

	private function load_state() {
		$this->state = array(
			'initialized' => false,
			'pending'     => array(),
			'seen'        => array(),
			'done'        => 0,
			'failed'      => array(),
		);
		if ( file_exists( $this->state_file ) ) {
			$decoded = json_decode( (string) file_get_contents( $this->state_file ), true );
			if ( is_array( $decoded ) ) {
				$this->state = wp_parse_args( $decoded, $this->state );
			}
		}
	}

	private function save_state() {
		file_put_contents( $this->state_file, wp_json_encode( $this->state ) );
	}

	public function progress() {
		return array(
			'current' => (int) $this->state['done'],
			'total'   => max( 1, count( $this->state['seen'] ) ),
		);
	}

	public function failed_urls() {
		return $this->state['failed'];
	}

	/* ------------------------------------------------------------------ */
	/* Inizializzazione: seed degli URL                                     */
	/* ------------------------------------------------------------------ */

	private function initialize() {
		wp_mkdir_p( $this->dest );

		$seeds = array(
			home_url( '/' ),
			home_url( '/robots.txt' ),
			home_url( '/ads.txt' ),
			home_url( '/sitemap.xml' ),
			home_url( '/sitemap_index.xml' ),
			home_url( '/sitemap-index.xml' ),
			home_url( '/wp-sitemap.xml' ),
			home_url( '/wp-sitemap.xsl' ),
			home_url( '/wp-sitemap-index.xsl' ),
		);

		// URL reale dell'indice sitemap di WordPress (WP 5.5+): copre anche le
		// configurazioni con permalink semplici, dove l'indice non risponde
		// su /wp-sitemap.xml.
		if ( function_exists( 'get_sitemap_url' ) ) {
			$wp_index = get_sitemap_url( 'index' );
			if ( $wp_index && false === strpos( $wp_index, '?' ) ) {
				$seeds[] = $wp_index;
			}
		}

		// Indici di ricerca statici (wp-static-fuse-search): gli URL dei JSON
		// sono costruiti dinamicamente dal JS, quindi non sarebbero mai
		// scoperti dal crawler. Li individuiamo direttamente sul filesystem.
		foreach ( $this->static_search_urls() as $url ) {
			$seeds[] = $url;
		}

		// Home delle lingue WPLingua: /en/, /fr/, ...
		foreach ( SED_Settings::language_slugs() as $slug ) {
			$seeds[] = home_url( '/' . $slug . '/' );
		}

		// URL aggiuntivi configurati dall'utente.
		foreach ( SED_Settings::extra_urls() as $extra ) {
			$seeds[] = ( 0 === strpos( $extra, 'http' ) ) ? $extra : home_url( '/' . ltrim( $extra, '/' ) );
		}

		foreach ( $seeds as $url ) {
			$this->enqueue( $url );
		}

		// Cattura la pagina 404 di WordPress: verra' salvata in
		// 404_not_found/index.html e l'ottimizzatore la trasformera' in 404.html
		// (stessa convenzione dello script Python originale).
		$this->capture_404();

		$this->state['initialized'] = true;
		SED_Logger::log( sprintf( 'Crawler inizializzato: %d URL seed, lingue: [%s]', count( $this->state['pending'] ), implode( ', ', SED_Settings::language_slugs() ) ) );
	}

	/**
	 * URL dei file generati da wp-static-fuse-search (e in generale di
	 * qualsiasi JSON dentro uploads/static-search), individuati sul
	 * filesystem locale. Fallback: tentativo per ogni lingua configurata.
	 */
	private function static_search_urls() {
		$urls    = array();
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'static-search';
		$base    = trailingslashit( $uploads['baseurl'] ) . 'static-search';

		if ( is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '/*.json' ) as $file ) {
				$urls[] = $base . '/' . basename( $file );
			}
		}

		if ( empty( $urls ) ) {
			// Il plugin di ricerca potrebbe non essere installato: in tal caso
			// questi URL falliranno con 404 e verranno semplicemente saltati.
			$slugs   = SED_Settings::language_slugs();
			$slugs[] = strtolower( substr( get_locale(), 0, 2 ) ); // Lingua sorgente.
			foreach ( array_unique( $slugs ) as $slug ) {
				$urls[] = $base . '/index-' . $slug . '.json';
			}
			$urls[] = $base . '/_meta.json';
		}
		return $urls;
	}

	private function capture_404() {
		$url      = home_url( '/sed-404-probe-' . wp_generate_password( 8, false, false ) . '/' );
		$response = $this->fetch( $url );
		if ( is_wp_error( $response ) ) {
			SED_Logger::log( 'Impossibile catturare la pagina 404: ' . $response->get_error_message(), 'warn' );
			return;
		}
		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return;
		}
		$path = $this->dest . '/404_not_found/index.html';
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, $body );
		SED_Logger::log( 'Pagina 404 catturata (404_not_found/index.html).' );
	}

	/* ------------------------------------------------------------------ */
	/* Loop principale a lotti                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Elabora URL finche' c'e' budget di tempo.
	 *
	 * @param float $deadline Timestamp (microtime) oltre il quale fermarsi.
	 * @return bool TRUE se il crawl e' completato.
	 */
	public function process_chunk( $deadline ) {
		if ( ! $this->state['initialized'] ) {
			$this->initialize();
		}

		while ( ! empty( $this->state['pending'] ) && microtime( true ) < $deadline ) {
			$url = array_shift( $this->state['pending'] );
			$this->process_url( $url );
			$this->state['done']++;

			// Salvataggio periodico dello stato (resilienza a crash/timeout).
			if ( 0 === $this->state['done'] % 10 ) {
				$this->save_state();
			}
		}

		$this->save_state();
		return empty( $this->state['pending'] );
	}

	private function process_url( $url ) {
		$response = $this->fetch( $url );
		if ( is_wp_error( $response ) ) {
			$this->state['failed'][] = $url . ' -> ' . $response->get_error_message();
			SED_Logger::log( 'Errore fetch ' . $url . ': ' . $response->get_error_message(), 'warn' );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );

		if ( $code >= 400 ) {
			$this->state['failed'][] = $url . ' -> HTTP ' . $code;
			return;
		}
		if ( '' === $body ) {
			return;
		}

		$rel = $this->url_to_relpath( $url, $type );
		if ( null === $rel ) {
			return;
		}

		$abs = $this->dest . '/' . $rel;
		wp_mkdir_p( dirname( $abs ) );
		file_put_contents( $abs, $body );

		// Scoperta di nuovi URL dal contenuto.
		$is_html   = ( false !== strpos( $type, 'text/html' ) ) || preg_match( '/\.html?$/i', $rel );
		$is_css    = ( false !== strpos( $type, 'text/css' ) ) || preg_match( '/\.css$/i', $rel );
		$is_xmlish = ( false !== strpos( $type, 'xml' ) ) || preg_match( '/\.xml$/i', $rel );
		$is_robots = ( 'robots.txt' === $rel );

		if ( $is_html ) {
			$this->discover_from_html( $body, $url );
		} elseif ( $is_css ) {
			$this->discover_from_css( $body, $url );
		} elseif ( $is_xmlish && self::looks_like_sitemap( $body ) ) {
			// Riconoscimento dal CONTENUTO (<sitemapindex>/<urlset>), non dal
			// nome: copre le sitemap di default di WordPress (wp-sitemap.xml
			// e tutte le figlie paginate), quelle dei plugin SEO e qualunque
			// nome custom. La ricorsione e' naturale: ogni sitemap scoperta
			// viene a sua volta scaricata e analizzata (indice -> figlie ->
			// nipoti, senza limite di profondita'; i duplicati sono filtrati).
			$this->discover_from_sitemap( $body, $url );
		} elseif ( $is_robots ) {
			$this->discover_from_robots( $body );
		}
	}

	/**
	 * Una sitemap si riconosce dal contenuto, qualunque sia il nome del file.
	 */
	public static function looks_like_sitemap( $body ) {
		$head = substr( $body, 0, 2048 );
		return false !== stripos( $head, '<sitemapindex' ) || false !== stripos( $head, '<urlset' );
	}

	private function fetch( $url ) {
		return wp_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 5,
				'sslverify'   => false,
				'user-agent'  => 'SED-Crawler/' . SED_VERSION . ' (+WordPress static export)',
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Scoperta URL                                                          */
	/* ------------------------------------------------------------------ */

	private function discover_from_html( $html, $base_url ) {
		// href / src / poster / data-src / data-lazy-src.
		if ( preg_match_all( '/(?:href|src|poster|data-src|data-lazy-src)\s*=\s*["\']([^"\']+)["\']/i', $html, $m ) ) {
			foreach ( $m[1] as $ref ) {
				$this->enqueue_relative( $ref, $base_url );
			}
		}
		// srcset / data-srcset (URL multipli con descrittori 1x/2x/Nw).
		if ( preg_match_all( '/(?:srcset|data-srcset|data-lazy-srcset)\s*=\s*["\']([^"\']+)["\']/i', $html, $m ) ) {
			foreach ( $m[1] as $set ) {
				foreach ( explode( ',', $set ) as $part ) {
					$tokens = preg_split( '/\s+/', trim( $part ) );
					if ( ! empty( $tokens[0] ) ) {
						$this->enqueue_relative( $tokens[0], $base_url );
					}
				}
			}
		}
		// meta content (og:image, twitter:image...): solo URL assoluti verso risorse.
		if ( preg_match_all( '/content\s*=\s*["\'](https?:\/\/[^"\']+\.(?:png|jpe?g|webp|gif|svg|ico))["\']/i', $html, $m ) ) {
			foreach ( $m[1] as $ref ) {
				$this->enqueue_relative( $ref, $base_url );
			}
		}
		// url(...) dentro <style> inline.
		$this->discover_from_css( $html, $base_url );
	}

	private function discover_from_css( $css, $base_url ) {
		if ( preg_match_all( '/url\(\s*[\'"]?([^\'")\s]+)[\'"]?\s*\)/i', $css, $m ) ) {
			foreach ( $m[1] as $ref ) {
				if ( 0 === strpos( $ref, 'data:' ) ) {
					continue;
				}
				$this->enqueue_relative( $ref, $base_url );
			}
		}
		// @import "file.css".
		if ( preg_match_all( '/@import\s+["\']([^"\']+)["\']/i', $css, $m ) ) {
			foreach ( $m[1] as $ref ) {
				$this->enqueue_relative( $ref, $base_url );
			}
		}
	}

	/**
	 * Estrae tutti gli URL da una sitemap (indice o urlset):
	 *  - <loc> (sia <sitemap><loc> degli indici sia <url><loc> delle foglie:
	 *    e' cosi' che la ricorsione scende di livello in livello)
	 *  - <image:loc> e <video:loc> (sitemap immagini/video)
	 *  - <xhtml:link rel="alternate" hreflang="..."> (versioni linguistiche,
	 *    usate dai siti multilingua come quelli con WPLingua)
	 *  - il foglio di stile XSL dichiarato nel prologo (<?xml-stylesheet?>),
	 *    es. /wp-sitemap.xsl di WordPress, cosi' la sitemap resta leggibile
	 *    anche nel browser.
	 */
	private function discover_from_sitemap( $xml, $base_url = '' ) {
		if ( preg_match_all( '/<loc>\s*([^<\s]+)\s*<\/loc>/i', $xml, $m ) ) {
			foreach ( $m[1] as $loc ) {
				$this->enqueue( html_entity_decode( $loc ) );
			}
		}
		if ( preg_match_all( '/<(?:image|video):loc>\s*([^<\s]+)\s*<\/(?:image|video):loc>/i', $xml, $m ) ) {
			foreach ( $m[1] as $loc ) {
				$this->enqueue( html_entity_decode( $loc ) );
			}
		}
		// Alternative linguistiche dentro le sitemap (xhtml:link).
		if ( preg_match_all( '/<xhtml:link[^>]+href\s*=\s*["\']([^"\']+)["\']/i', $xml, $m ) ) {
			foreach ( $m[1] as $href ) {
				$this->enqueue( html_entity_decode( $href ) );
			}
		}
		// Foglio di stile XSL (es. wp-sitemap.xsl, main-sitemap.xsl di Yoast).
		if ( $base_url && preg_match_all( '/<\?xml-stylesheet[^>]*href\s*=\s*["\']([^"\']+)["\']/i', $xml, $m ) ) {
			foreach ( $m[1] as $href ) {
				$this->enqueue_relative( html_entity_decode( $href ), $base_url );
			}
		}
	}

	private function discover_from_robots( $txt ) {
		foreach ( preg_split( '/\r\n|\r|\n/', $txt ) as $row ) {
			if ( 0 === stripos( trim( $row ), 'sitemap:' ) ) {
				$this->enqueue( trim( substr( trim( $row ), 8 ) ) );
			}
		}
	}

	private function enqueue_relative( $ref, $base_url ) {
		$abs = $this->resolve_url( $ref, $base_url );
		if ( $abs ) {
			$this->enqueue( $abs );
		}
	}

	/**
	 * Aggiunge un URL alla coda se interno, ammesso e non gia' visto.
	 */
	private function enqueue( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return;
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || strtolower( $parts['host'] ) !== $this->host ) {
			return; // Solo URL interni.
		}

		$path = $parts['path'] ?? '/';

		// Path non esportabili.
		$deny = array( '/wp-admin', '/wp-login.php', '/wp-json', '/xmlrpc.php', '/wp-cron.php', '/cart', '/checkout', '/my-account' );
		foreach ( array_merge( $deny, $this->excludes ) as $prefix ) {
			if ( 0 === strpos( $path, $prefix ) ) {
				return;
			}
		}
		// Feed e trackback non servono in un sito statico.
		if ( preg_match( '#/(feed|trackback)(/|$)#i', $path ) ) {
			return;
		}

		// Query string: per gli asset (path con estensione) la rimuoviamo e
		// scarichiamo il file base; per le pagine, saltiamo l'URL (evita loop
		// infiniti su filtri, paginazioni con parametri, replytocom, ecc.).
		$has_ext = (bool) preg_match( '/\.[a-z0-9]{2,5}$/i', $path );
		if ( ! empty( $parts['query'] ) && ! $has_ext ) {
			return;
		}

		$normalized = $this->base . $path;
		$key        = md5( $normalized );
		if ( isset( $this->state['seen'][ $key ] ) ) {
			return;
		}
		$this->state['seen'][ $key ] = 1;
		$this->state['pending'][]    = $normalized;
	}

	/* ------------------------------------------------------------------ */
	/* URL helpers                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Risolve un riferimento (relativo o assoluto) rispetto a un URL base.
	 */
	private function resolve_url( $ref, $base_url ) {
		$ref = trim( $ref );
		if ( '' === $ref || '#' === $ref[0] ) {
			return null;
		}
		if ( preg_match( '/^(mailto:|tel:|javascript:|data:|sms:|ftp:)/i', $ref ) ) {
			return null;
		}
		$ref = preg_replace( '/#.*$/', '', $ref );
		if ( '' === $ref ) {
			return null;
		}
		if ( 0 === strpos( $ref, '//' ) ) {
			return 'https:' . $ref;
		}
		if ( preg_match( '#^https?://#i', $ref ) ) {
			return $ref;
		}
		if ( '/' === $ref[0] ) {
			return $this->base . $ref;
		}

		// Relativo: risolto rispetto alla directory dell'URL base.
		$base_path = wp_parse_url( $base_url, PHP_URL_PATH ) ?: '/';
		if ( '/' !== substr( $base_path, -1 ) ) {
			$base_path = dirname( $base_path );
			$base_path = ( '.' === $base_path || '\\' === $base_path ) ? '/' : $base_path . '/';
		}
		$path = $base_path . $ref;

		// Normalizza ./ e ../
		$out = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '.' === $segment || '' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $out );
				continue;
			}
			$out[] = $segment;
		}
		$resolved = '/' . implode( '/', $out );
		if ( '/' === substr( $ref, -1 ) ) {
			$resolved .= '/';
		}
		return $this->base . $resolved;
	}

	/**
	 * Converte un URL nel percorso relativo del file statico:
	 *   /            -> index.html
	 *   /pagina/     -> pagina/index.html
	 *   /pagina      -> pagina/index.html
	 *   /img/a.png   -> img/a.png
	 */
	private function url_to_relpath( $url, $content_type = '' ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = rawurldecode( (string) $path );
		$path = ltrim( $path, '/' );

		// Anti path-traversal.
		if ( false !== strpos( $path, '..' ) ) {
			return null;
		}

		if ( '' === $path || '/' === substr( $path, -1 ) ) {
			$path = rtrim( $path, '/' );
			return ( '' === $path ? '' : $path . '/' ) . 'index.html';
		}

		$basename = basename( $path );
		if ( false !== strpos( $basename, '.' ) ) {
			return $path;
		}

		// Path senza estensione: se il server risponde HTML, e' una pagina.
		if ( false !== strpos( $content_type, 'text/html' ) || '' === $content_type ) {
			return $path . '/index.html';
		}
		return $path;
	}
}
