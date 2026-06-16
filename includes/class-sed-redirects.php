<?php
/**
 * Generazione del file "_redirects" per Cloudflare Pages (e Netlify, stesso
 * formato), per non perdere il posizionamento dei vecchi URL quando il sito
 * diventa statico.
 *
 * Raccoglie le regole da piu' fonti, nell'ordine di precedenza con cui
 * vengono scritte (Cloudflare applica la prima corrispondenza):
 *   1. regole manuali dell'utente (gia' nel formato Cloudflare)
 *   2. plugin di redirect: Redirection, Yoast, Rank Math
 *   3. fallback per i permalink "brutti": /?p=ID -> permalink dei post
 *
 * Il file viene scritto nella root dell'export ottimizzato. Una riga per
 * regola: "<da> <a> <codice>". I duplicati di origine e le regole inutili
 * (da == a) vengono scartati.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SED_Redirects {

	/** @var array Snapshot delle opzioni del job. */
	private $opts;

	/** @var array [from => 1] origini gia' inserite (dedup). */
	private $seen = array();

	/** @var string[] Righe del file, nell'ordine di scrittura. */
	private $lines = array();

	public function __construct( $opts ) {
		$this->opts = $opts;
	}

	/**
	 * Costruisce e scrive {dir}/_redirects. Restituisce il numero di regole.
	 *
	 * @param string $dir Cartella dell'export ottimizzato.
	 * @return int
	 */
	public function write( $dir ) {
		$this->seen  = array();
		$this->lines = array();

		$this->add_manual();
		$this->add_redirection_plugin();
		$this->add_yoast();
		$this->add_rankmath();
		$this->add_post_id_fallback();

		$rules = 0;
		foreach ( $this->lines as $line ) {
			if ( '' !== $line && '#' !== $line[0] ) {
				$rules++;
			}
		}

		// Niente file se non ci sono regole effettive (solo eventuali commenti).
		if ( 0 === $rules ) {
			return 0;
		}

		$header = array(
			'# Generato da Static Export & Deploy',
			'# Redirect per Cloudflare Pages / Netlify — non modificare a mano:',
			'# verra\' rigenerato al prossimo export. Usa il campo "regole manuali".',
			'',
		);
		$content = implode( "\n", array_merge( $header, $this->lines ) ) . "\n";
		file_put_contents( trailingslashit( $dir ) . '_redirects', $content );

		return $rules;
	}

	/* ------------------------------------------------------------------ */
	/* Sorgenti                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Regole inserite a mano dall'utente, gia' nel formato Cloudflare
	 * (una per riga: "/da /a [codice]"). I commenti vengono preservati.
	 */
	private function add_manual() {
		$raw = (string) ( $this->opts['redirects_manual'] ?? '' );
		if ( '' === trim( $raw ) ) {
			return;
		}
		$this->lines[] = '# Regole manuali';
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $row ) {
			$row = trim( $row );
			if ( '' === $row ) {
				continue;
			}
			if ( '#' === $row[0] ) {
				$this->lines[] = $row;
				continue;
			}
			$parts = preg_split( '/\s+/', $row );
			if ( count( $parts ) >= 2 ) {
				$code = ( isset( $parts[2] ) && ctype_digit( $parts[2] ) ) ? (int) $parts[2] : 301;
				$this->add_rule( $parts[0], $parts[1], $code );
			}
		}
		$this->lines[] = '';
	}

	/** Plugin "Redirection" (tabella {prefix}redirection_items). */
	private function add_redirection_plugin() {
		global $wpdb;
		$table = $wpdb->prefix . 'redirection_items';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT url, action_data, action_code, match_url FROM {$table} WHERE status = 'enabled' AND action_type = 'url'" );
		if ( ! $rows ) {
			return;
		}
		$added = false;
		foreach ( $rows as $row ) {
			$to = $row->action_data;
			// In alcune versioni action_data e' serializzato.
			if ( is_string( $to ) && 0 === strpos( $to, 'a:' ) ) {
				$un = maybe_unserialize( $to );
				$to = is_array( $un ) && ! empty( $un['url'] ) ? $un['url'] : '';
			}
			if ( '' === (string) $to ) {
				continue;
			}
			if ( ! $added ) {
				$this->lines[] = '# Plugin Redirection';
				$added         = true;
			}
			$this->add_rule( $row->url, $to, (int) $row->action_code ?: 301 );
		}
		if ( $added ) {
			$this->lines[] = '';
		}
	}

	/** Redirect manuali di Yoast SEO Premium (opzione wpseo-premium-redirects-base). */
	private function add_yoast() {
		$redirects = get_option( 'wpseo-premium-redirects-base' );
		if ( ! is_array( $redirects ) || empty( $redirects ) ) {
			return;
		}
		$this->lines[] = '# Yoast SEO';
		foreach ( $redirects as $r ) {
			if ( empty( $r['origin'] ) || empty( $r['url'] ) ) {
				continue;
			}
			$this->add_rule( $this->as_path( $r['origin'] ), $r['url'], (int) ( $r['type'] ?? 301 ) );
		}
		$this->lines[] = '';
	}

	/** Rank Math (tabella {prefix}rank_math_redirections). */
	private function add_rankmath() {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_redirections';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT sources, url_to, header_code FROM {$table} WHERE status = 'active'" );
		if ( ! $rows ) {
			return;
		}
		$added = false;
		foreach ( $rows as $row ) {
			$to      = $row->url_to;
			$sources = maybe_unserialize( $row->sources );
			if ( ! is_array( $sources ) || '' === (string) $to ) {
				continue;
			}
			foreach ( $sources as $src ) {
				$pattern = is_array( $src ) ? ( $src['pattern'] ?? '' ) : $src;
				$compare = is_array( $src ) ? ( $src['comparison'] ?? 'exact' ) : 'exact';
				if ( '' === $pattern || 'exact' !== $compare ) {
					continue; // Solo corrispondenze esatte: regex/contains non traducibili 1:1.
				}
				if ( ! $added ) {
					$this->lines[] = '# Rank Math';
					$added         = true;
				}
				$this->add_rule( $this->as_path( $pattern ), $to, (int) $row->header_code ?: 301 );
			}
		}
		if ( $added ) {
			$this->lines[] = '';
		}
	}

	/**
	 * Fallback per i permalink non "pretty": /?p=ID -> permalink corrente del
	 * post. Utile se in passato il sito girava con i link di default e ci sono
	 * link esterni o in cache verso /?p=123.
	 */
	private function add_post_id_fallback() {
		if ( empty( $this->opts['redirects_post_id'] ) ) {
			return;
		}
		$ids = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => 2000,
			'fields'         => 'ids',
		) );
		if ( empty( $ids ) ) {
			return;
		}
		$this->lines[] = '# Permalink legacy (?p=ID)';
		foreach ( $ids as $id ) {
			$path = $this->as_path( get_permalink( $id ) );
			if ( '' !== $path && '/' !== $path ) {
				$this->add_rule( '/?p=' . $id, $path, 301 );
			}
		}
		$this->lines[] = '';
	}

	/* ------------------------------------------------------------------ */
	/* Helper                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Aggiunge una regola con dedup sull'origine ed esclusione dei no-op.
	 */
	private function add_rule( $from, $to, $code = 301 ) {
		$from = $this->normalize_from( $from );
		$to   = $this->normalize_to( $to );

		if ( '' === $from || '' === $to || $from === $to ) {
			return;
		}
		if ( isset( $this->seen[ $from ] ) ) {
			return;
		}
		$this->seen[ $from ] = 1;

		$code = in_array( (int) $code, array( 301, 302, 307, 308 ), true ) ? (int) $code : 301;
		$this->lines[] = $from . ' ' . $to . ' ' . $code;
	}

	/** Origine: sempre path assoluto che inizia con '/'. */
	private function normalize_from( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		// Conserva la query (es. ?p=123); toglie host se presente.
		if ( preg_match( '#^https?://#i', $value ) ) {
			$parts = wp_parse_url( $value );
			$value = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		}
		return '/' . ltrim( $value, '/' );
	}

	/**
	 * Destinazione: path interno mappato sul dominio di produzione, oppure
	 * URL assoluto se esterno. Applica le stesse sostituzioni dell'export.
	 */
	private function normalize_to( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $value ) ) {
			// URL assoluto: se punta all'host del sito lo riduciamo a path,
			// cosi' il redirect resta interno e indipendente dal dominio.
			$parts = wp_parse_url( $value );
			$host  = strtolower( $parts['host'] ?? '' );
			$known = array_filter( array(
				strtolower( (string) ( $this->opts['source_host'] ?? '' ) ),
				strtolower( (string) ( $this->opts['target_host'] ?? '' ) ),
			) );
			if ( $host && in_array( $host, $known, true ) ) {
				$value = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
			} else {
				return $value; // Esterno: lasciato cosi'.
			}
		}
		return '/' . ltrim( $value, '/' );
	}

	/** Riduce un URL (eventualmente completo) al solo path con '/' iniziale. */
	private function as_path( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			$url = wp_parse_url( $url, PHP_URL_PATH ) ?: '/';
		}
		return '/' . ltrim( $url, '/' );
	}
}
