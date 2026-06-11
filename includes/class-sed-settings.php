<?php
/**
 * Impostazioni del plugin: valori predefiniti, salvataggio, cifratura del token
 * GitHub e mappatura automatica dominio -> repository.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SED_Settings {

	const OPTION = 'sed_settings';

	/**
	 * Valori predefiniti. Il nome del repository, se vuoto, viene derivato
	 * automaticamente dal dominio del sito (vedi resolve_repo()).
	 */
	public static function defaults() {
		return array(
			'github_token'    => '',          // Cifrato (AES-256-CBC con i salt di WP).
			'github_owner'    => '',
			'github_repo'     => '',          // Vuoto = auto dal dominio: <sub_prod>.<dominio-registrabile>.
			'branch_raw'      => 'raw',
			'branch_main'     => 'main',
			'deploy_raw'      => 1,           // Carica anche l'export originale sul branch raw.
			'prod_url'        => '',          // URL completo di produzione (es. https://www.sito.com/): se impostato, ogni riferimento all'host attuale viene mappato li', protocollo incluso.
			'sub_staging'     => self::guess_staging_sub(),
			'sub_prod'        => 'www',
			'force_https'     => 1,           // http -> https su tutti gli URL interni (anti mixed-content).
			'ga_id'           => '',
			'adsense_id'      => '',
			'webp_quality'    => 80,
			'keep_js'         => 0,           // Default: JS rimosso (tranne JSON-LD + GA4/AdSense + whitelist).
			'js_allowlist'    => "fuse.js\nfuse-js\nsfs-\nnew Fuse(", // Script preservati anche con keep_js=0.
			'ads_txt'         => 1,           // Genera ads.txt dall'ID AdSense se assente.
			'lang_slugs'      => '',          // Vuoto = auto da WPLingua.
			'extra_urls'      => '',          // URL aggiuntivi da includere nel crawl (uno per riga).
			'exclude_paths'   => '',          // Path da escludere (uno per riga, prefisso).
			'preserve_files'  => "README.md\n.gitignore\nCNAME",
			'deploy_engine'   => 'pack',      // pack (1 upload, niente rate limit) | api (REST file per file).
			'make_zips'       => 1,           // ZIP scaricabili di raw e main.
			'schedule'        => 'manual',    // manual | daily | weekly.
			'batch_seconds'   => 20,          // Budget di tempo per ogni ciclo in background.
			'parallel_uploads' => 8,          // Blob caricati in parallelo su GitHub (1-20).
			'keep_jobs'       => 2,           // Export conservati su disco (i piu' vecchi vengono eliminati).
		);
	}

	public static function ensure_defaults() {
		$current = get_option( self::OPTION );
		if ( ! is_array( $current ) ) {
			update_option( self::OPTION, self::defaults(), false );
		}
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Salva le impostazioni provenienti dal form admin (gia' verificato il nonce).
	 *
	 * @param array $input $_POST.
	 */
	public static function save_from_post( $input ) {
		$old = self::all();
		$new = $old;

		$new['github_owner']   = sanitize_text_field( $input['github_owner'] ?? '' );
		$new['github_repo']    = sanitize_text_field( $input['github_repo'] ?? '' );
		$new['branch_raw']     = sanitize_text_field( $input['branch_raw'] ?? 'raw' ) ?: 'raw';
		$new['branch_main']    = sanitize_text_field( $input['branch_main'] ?? 'main' ) ?: 'main';
		$new['deploy_raw']     = empty( $input['deploy_raw'] ) ? 0 : 1;
		$new['prod_url']       = self::normalize_prod_url( $input['prod_url'] ?? '' );
		$new['sub_staging']    = self::sanitize_sub( $input['sub_staging'] ?? '' );
		$new['sub_prod']       = self::sanitize_sub( $input['sub_prod'] ?? 'www' ) ?: 'www';
		$new['force_https']    = empty( $input['force_https'] ) ? 0 : 1;
		$new['ga_id']          = sanitize_text_field( $input['ga_id'] ?? '' );
		$new['adsense_id']     = self::normalize_adsense( sanitize_text_field( $input['adsense_id'] ?? '' ) );
		$new['webp_quality']   = min( 100, max( 1, absint( $input['webp_quality'] ?? 80 ) ) );
		$new['keep_js']        = empty( $input['keep_js'] ) ? 0 : 1;
		$new['js_allowlist']   = sanitize_textarea_field( $input['js_allowlist'] ?? '' );
		$new['ads_txt']        = empty( $input['ads_txt'] ) ? 0 : 1;
		$new['lang_slugs']     = sanitize_text_field( $input['lang_slugs'] ?? '' );
		$new['extra_urls']     = sanitize_textarea_field( $input['extra_urls'] ?? '' );
		$new['exclude_paths']  = sanitize_textarea_field( $input['exclude_paths'] ?? '' );
		$new['preserve_files'] = sanitize_textarea_field( $input['preserve_files'] ?? '' );
		$new['deploy_engine']  = ( 'api' === ( $input['deploy_engine'] ?? 'pack' ) ) ? 'api' : 'pack';
		$new['make_zips']      = empty( $input['make_zips'] ) ? 0 : 1;
		$new['schedule']       = in_array( $input['schedule'] ?? 'manual', array( 'manual', 'daily', 'weekly' ), true ) ? $input['schedule'] : 'manual';
		$new['batch_seconds']  = min( 50, max( 5, absint( $input['batch_seconds'] ?? 20 ) ) );
		$new['parallel_uploads'] = min( 20, max( 1, absint( $input['parallel_uploads'] ?? 8 ) ) );
		$new['keep_jobs']        = min( 10, max( 1, absint( $input['keep_jobs'] ?? 2 ) ) );

		// Il token viene aggiornato solo se l'utente ne inserisce uno nuovo.
		$token_input = trim( (string) ( $input['github_token'] ?? '' ) );
		if ( '' !== $token_input ) {
			$new['github_token'] = self::encrypt( $token_input );
		}
		if ( ! empty( $input['github_token_clear'] ) ) {
			$new['github_token'] = '';
		}

		update_option( self::OPTION, $new, false );

		// Riallinea l'eventuale pianificazione e aggiorna il rilevamento lingue.
		wp_clear_scheduled_hook( 'sed_scheduled_export' );
		delete_transient( 'sed_lang_sniff' );
	}

	/* ------------------------------------------------------------------ */
	/* Token GitHub (cifratura at-rest)                                     */
	/* ------------------------------------------------------------------ */

	public static function get_token() {
		return self::decrypt( self::get( 'github_token' ) );
	}

	public static function has_token() {
		return '' !== self::get_token();
	}

	public static function encrypt( $value ) {
		if ( '' === $value ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'b64:' . base64_encode( $value );
		}
		$key    = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return 'b64:' . base64_encode( $value );
		}
		return 'enc:' . base64_encode( $iv . $cipher );
	}

	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}
		if ( 0 === strpos( $stored, 'b64:' ) ) {
			return base64_decode( substr( $stored, 4 ) );
		}
		if ( 0 === strpos( $stored, 'enc:' ) && function_exists( 'openssl_decrypt' ) ) {
			$raw = base64_decode( substr( $stored, 4 ) );
			if ( false === $raw || strlen( $raw ) <= 16 ) {
				return '';
			}
			$key   = hash( 'sha256', wp_salt( 'auth' ), true );
			$plain = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
			return false === $plain ? '' : $plain;
		}
		return $stored; // Retrocompatibilita' con token salvati in chiaro.
	}

	/* ------------------------------------------------------------------ */
	/* Mappatura dominio -> repository                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Host del sito (es. admin.dominio.com).
	 */
	public static function site_host() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return strtolower( (string) $host );
	}

	/**
	 * Dominio "registrabile" ottenuto rimuovendo l'eventuale etichetta di
	 * staging dall'host (es. admin.dominio.co.uk -> dominio.co.uk).
	 */
	public static function registrable_domain() {
		// Con l'URL di produzione impostato, il dominio di riferimento e'
		// quello di DESTINAZIONE (serve a force_https e al nome repo).
		$prod = self::prod_url_parts();
		if ( $prod ) {
			$host  = $prod['host'];
			$parts = explode( '.', $host );
			if ( count( $parts ) >= 3 ) {
				array_shift( $parts );
				return implode( '.', $parts );
			}
			return $host;
		}
		$host = self::site_host();
		$sub  = strtolower( (string) self::get( 'sub_staging' ) );
		if ( $sub && 0 === strpos( $host, $sub . '.' ) ) {
			return substr( $host, strlen( $sub ) + 1 );
		}
		// Rimuove anche un eventuale www. residuo.
		if ( 0 === strpos( $host, 'www.' ) ) {
			return substr( $host, 4 );
		}
		// Host con almeno 3 etichette: scarta la prima (sottodominio generico).
		$parts = explode( '.', $host );
		if ( count( $parts ) >= 3 ) {
			array_shift( $parts );
			return implode( '.', $parts );
		}
		return $host;
	}

	/**
	 * Repository di destinazione in forma "owner/repo".
	 * Se il campo repo e' vuoto viene derivato dal dominio:
	 *   <sub_prod>.<dominio-registrabile>   (es. www.dominio.com)
	 */
	public static function resolve_repo() {
		$owner = trim( (string) self::get( 'github_owner' ), " /" );
		$repo  = trim( (string) self::get( 'github_repo' ), " /" );

		if ( '' === $repo ) {
			$repo = self::auto_repo_name();
		}
		// Consente anche "owner/repo" direttamente nel campo repo.
		if ( false !== strpos( $repo, '/' ) ) {
			return $repo;
		}
		if ( '' === $owner ) {
			return '';
		}
		return $owner . '/' . $repo;
	}

	public static function auto_repo_name() {
		$parts = self::prod_url_parts();
		if ( $parts ) {
			return $parts['host']; // es. www.esempio.com.
		}
		$prod = strtolower( (string) self::get( 'sub_prod' ) ) ?: 'www';
		return $prod . '.' . self::registrable_domain();
	}

	/**
	 * Prova a indovinare il sottodominio di staging dall'host corrente
	 * (prima etichetta se l'host ha almeno 3 livelli), altrimenti 'admin'.
	 */
	public static function guess_staging_sub() {
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$parts = explode( '.', strtolower( (string) $host ) );
		if ( count( $parts ) >= 3 && 'www' !== $parts[0] ) {
			return $parts[0];
		}
		return 'admin';
	}

	/* ------------------------------------------------------------------ */
	/* URL di produzione                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Normalizza l'URL di produzione in "scheme://host" (minuscolo, senza
	 * path). Accetta anche input senza schema ("www.sito.com" -> https).
	 */
	public static function normalize_prod_url( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $raw ) ) {
			$raw = 'https://' . ltrim( $raw, '/' );
		}
		$parts = wp_parse_url( $raw );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = ( isset( $parts['scheme'] ) && 'http' === strtolower( $parts['scheme'] ) ) ? 'http' : 'https';
		return $scheme . '://' . strtolower( $parts['host'] );
	}

	/**
	 * @return array|null ['scheme' => 'https', 'host' => 'www.sito.com']
	 */
	public static function prod_url_parts() {
		$url = (string) self::get( 'prod_url' );
		if ( '' === $url ) {
			return null;
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return null;
		}
		return array(
			'scheme' => ( isset( $parts['scheme'] ) && 'http' === strtolower( $parts['scheme'] ) ) ? 'http' : 'https',
			'host'   => strtolower( $parts['host'] ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Lingue (WPLingua)                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Slug lingua da includere come seed del crawler (es. ['en','fr']).
	 * Priorita': campo impostazioni -> rilevamento automatico generalizzato.
	 * Il crawler scopre comunque le lingue anche dai link hreflang e dallo
	 * switcher presenti nelle pagine: questo elenco serve solo da innesco.
	 */
	public static function language_slugs() {
		$manual = trim( (string) self::get( 'lang_slugs' ) );
		if ( '' !== $manual ) {
			return self::parse_slug_list( $manual );
		}
		return self::detect_language_slugs();
	}

	/**
	 * Rilevamento generalizzato, indipendente dal plugin multilingua:
	 *  1. API/opzioni dei plugin noti (WPLingua, Polylang, WPML, TranslatePress)
	 *  2. in ogni caso, "sniff" della home: tag <link hreflang> e percorsi
	 *     /xx/ ricorrenti nei link interni (con cache di 12 ore).
	 * I risultati vengono uniti e deduplicati.
	 */
	public static function detect_language_slugs() {
		$slugs = self::detect_wplingua_slugs();

		if ( empty( $slugs ) ) {
			$slugs = self::detect_polylang_slugs();
		}
		if ( empty( $slugs ) ) {
			$slugs = self::detect_wpml_slugs();
		}
		if ( empty( $slugs ) ) {
			$slugs = self::detect_translatepress_slugs();
		}

		// Lo sniff generico si somma sempre: copre plugin sconosciuti,
		// implementazioni custom e svisti di configurazione.
		$slugs = array_merge( $slugs, self::sniff_homepage_languages() );

		return array_values( array_unique( $slugs ) );
	}

	/** Polylang: pll_languages_list() restituisce gli slug. */
	public static function detect_polylang_slugs() {
		if ( function_exists( 'pll_languages_list' ) ) {
			$list = pll_languages_list( array( 'fields' => 'slug' ) );
			if ( is_array( $list ) ) {
				return self::parse_slug_list( implode( ',', $list ) );
			}
		}
		return array();
	}

	/** WPML: filtro wpml_active_languages. */
	public static function detect_wpml_slugs() {
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return array();
		}
		$langs = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		$out   = array();
		if ( is_array( $langs ) ) {
			foreach ( $langs as $lang ) {
				if ( ! empty( $lang['language_code'] ) ) {
					$out[] = $lang['language_code'];
				} elseif ( ! empty( $lang['code'] ) ) {
					$out[] = $lang['code'];
				}
			}
		}
		return self::parse_slug_list( implode( ',', $out ) );
	}

	/** TranslatePress: opzione trp_settings (url-slugs / translation-languages). */
	public static function detect_translatepress_slugs() {
		$opt = get_option( 'trp_settings' );
		if ( ! is_array( $opt ) ) {
			return array();
		}
		$out = array();
		if ( ! empty( $opt['url-slugs'] ) && is_array( $opt['url-slugs'] ) ) {
			$out = array_values( $opt['url-slugs'] );
		} elseif ( ! empty( $opt['translation-languages'] ) && is_array( $opt['translation-languages'] ) ) {
			foreach ( $opt['translation-languages'] as $locale ) {
				$out[] = strtolower( str_replace( '_', '-', (string) $locale ) );
			}
		}
		return self::parse_slug_list( implode( ',', $out ) );
	}

	/**
	 * Sniff generico della home (cache 12h): estrae gli slug lingua da
	 *  - <link rel="alternate" hreflang="..."> (dal primo segmento dell'href)
	 *  - percorsi /xx/ che compaiono almeno 2 volte nei link interni
	 *    (tipicamente lo switcher lingua e i menu).
	 */
	public static function sniff_homepage_languages( $force = false ) {
		$cached = get_transient( 'sed_lang_sniff' );
		if ( ! $force && is_array( $cached ) ) {
			return $cached;
		}

		$out      = array();
		$response = wp_remote_get( home_url( '/' ), array(
			'timeout'   => 8,
			'sslverify' => false,
			'user-agent' => 'SED-LangSniff/' . SED_VERSION,
		) );

		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$html = wp_remote_retrieve_body( $response );
			$host = self::site_host();

			// 1) Tag hreflang: lo slug e' il primo segmento del path dell'href.
			if ( preg_match_all( '/<link[^>]+hreflang\s*=\s*["\'][^"\']+["\'][^>]*>/i', $html, $tags ) ) {
				foreach ( $tags[0] as $tag ) {
					if ( preg_match( '/href\s*=\s*["\']([^"\']+)["\']/i', $tag, $hm ) ) {
						$slug = self::first_path_segment( $hm[1], $host );
						if ( $slug ) {
							$out[] = $slug;
						}
					}
				}
			}

			// 2) Percorsi /xx/ ricorrenti nei link interni (>= 2 occorrenze).
			$tally = array();
			if ( preg_match_all( '/href\s*=\s*["\']([^"\']+)["\']/i', $html, $links ) ) {
				foreach ( $links[1] as $href ) {
					$slug = self::first_path_segment( $href, $host );
					if ( $slug ) {
						$tally[ $slug ] = ( $tally[ $slug ] ?? 0 ) + 1;
					}
				}
			}
			foreach ( $tally as $slug => $count ) {
				if ( $count >= 2 ) {
					$out[] = $slug;
				}
			}

			$out = self::parse_slug_list( implode( ',', $out ) );
		}

		set_transient( 'sed_lang_sniff', $out, 12 * HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * Primo segmento del path se assomiglia a uno slug lingua ('en', 'pt-br')
	 * e l'URL e' interno (relativo o sullo stesso host).
	 */
	private static function first_path_segment( $url, $host ) {
		$parts = wp_parse_url( trim( (string) $url ) );
		if ( ! empty( $parts['host'] ) && strtolower( $parts['host'] ) !== $host ) {
			return null;
		}
		$path = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';
		if ( '' === $path ) {
			return null;
		}
		$first = strtolower( strtok( $path, '/' ) );
		return preg_match( '/^[a-z]{2}(?:-[a-z]{2})?$/', $first ) ? $first : null;
	}

	public static function detect_wplingua_slugs() {
		$slugs = array();

		// API pubblica di WPLingua, se disponibile.
		if ( function_exists( 'wplng_get_languages_target_ids' ) ) {
			$ids = wplng_get_languages_target_ids();
			if ( is_array( $ids ) ) {
				$slugs = $ids;
			}
		}

		// Fallback: opzioni note di WPLingua.
		if ( empty( $slugs ) ) {
			foreach ( array( 'wplng_target_languages', 'wplng_languages_target' ) as $opt_name ) {
				$opt = get_option( $opt_name );
				if ( empty( $opt ) ) {
					continue;
				}
				if ( is_string( $opt ) ) {
					$decoded = json_decode( $opt, true );
					$opt     = is_array( $decoded ) ? $decoded : explode( ',', $opt );
				}
				if ( is_array( $opt ) ) {
					foreach ( $opt as $item ) {
						if ( is_string( $item ) ) {
							$slugs[] = $item;
						} elseif ( is_array( $item ) ) {
							foreach ( array( 'id', 'slug', 'language_id', 'code' ) as $k ) {
								if ( ! empty( $item[ $k ] ) && is_string( $item[ $k ] ) ) {
									$slugs[] = $item[ $k ];
									break;
								}
							}
						}
					}
				}
				if ( $slugs ) {
					break;
				}
			}
		}

		return self::parse_slug_list( implode( ',', $slugs ) );
	}

	private static function parse_slug_list( $raw ) {
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', strtolower( $raw ) ) as $slug ) {
			$slug = trim( $slug, "/ \t" );
			if ( preg_match( '/^[a-z]{2}(?:-[a-z]{2})?$/', $slug ) ) {
				$out[] = $slug;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/* ------------------------------------------------------------------ */
	/* Helper vari                                                          */
	/* ------------------------------------------------------------------ */

	private static function sanitize_sub( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return trim( preg_replace( '/[^a-z0-9\-]/', '', $value ), '-' );
	}

	/**
	 * Normalizza il client AdSense in 'ca-pub-XXXX' (port di _normalizza_adsense_id).
	 */
	public static function normalize_adsense( $id ) {
		$id = trim( (string) $id );
		if ( '' === $id ) {
			return '';
		}
		if ( 0 === stripos( $id, 'ca-pub-' ) ) {
			return 'ca-pub-' . substr( $id, 7 );
		}
		if ( 0 === stripos( $id, 'pub-' ) ) {
			return 'ca-' . $id;
		}
		if ( ctype_digit( $id ) ) {
			return 'ca-pub-' . $id;
		}
		return $id;
	}

	public static function preserve_list() {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) self::get( 'preserve_files' ) ) as $row ) {
			$row = trim( $row );
			if ( '' !== $row ) {
				$out[] = strtolower( $row );
			}
		}
		$out[] = '.git';
		return array_values( array_unique( $out ) );
	}

	public static function exclude_paths() {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) self::get( 'exclude_paths' ) ) as $row ) {
			$row = '/' . ltrim( trim( $row ), '/' );
			if ( '/' !== $row ) {
				$out[] = untrailingslashit( $row );
			}
		}
		return $out;
	}

	public static function extra_urls() {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) self::get( 'extra_urls' ) ) as $row ) {
			$row = trim( $row );
			if ( '' !== $row ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Pattern (substring, case-insensitive) di script da preservare anche
	 * quando il JavaScript viene rimosso: confrontati con src, id e contenuto
	 * di ogni <script>. Default pensati per wp-static-fuse-search.
	 */
	public static function js_allowlist() {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) self::get( 'js_allowlist' ) ) as $row ) {
			$row = trim( $row );
			if ( '' !== $row ) {
				$out[] = $row;
			}
		}
		return $out;
	}
}
