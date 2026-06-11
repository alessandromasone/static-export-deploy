<?php
/**
 * Coda in background: orchestratore della pipeline completa.
 *
 * Fasi del job:
 *   crawl        -> export del sito in /raw (crawler a lotti)
 *   deploy_raw   -> push dell'export originale sul branch "raw"
 *   prepare      -> copia raw -> opt + preparazione struttura (404, feed, ...)
 *   optimize     -> elaborazione file a lotti (WebP, pulizia HTML, ...)
 *   audit        -> verifica link interni + audit SEO -> report.txt
 *   deploy_main  -> push del sito ottimizzato sul branch "main"
 *   done
 *
 * Il lavoro procede "a tick": ogni tick ha un budget di tempo (default 20s),
 * al termine del quale lo stato viene salvato e viene auto-dispatchata una
 * richiesta asincrona per il tick successivo. Un cron di watchdog (ogni
 * minuto) riavvia la pipeline se il loopback HTTP non funziona sull'hosting.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SED_Queue {

	const JOB_OPTION  = 'sed_job';
	const LOCK       = 'sed_lock';

	public static function init() {
		add_action( 'wp_ajax_sed_process', array( __CLASS__, 'ajax_process' ) );
		add_action( 'wp_ajax_nopriv_sed_process', array( __CLASS__, 'ajax_process' ) );
		add_action( 'sed_watchdog', array( __CLASS__, 'tick' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Job lifecycle                                                         */
	/* ------------------------------------------------------------------ */

	public static function get_job() {
		$job = get_option( self::JOB_OPTION );
		return is_array( $job ) ? $job : null;
	}

	private static function save_job( $job ) {
		$job['updated'] = time();
		update_option( self::JOB_OPTION, $job, false );
	}

	/**
	 * Avvia un nuovo job (se non ce n'e' uno in corso).
	 *
	 * @param string $trigger manual|scheduled.
	 * @return array|WP_Error
	 */
	public static function start_job( $trigger = 'manual' ) {
		$current = self::get_job();
		if ( $current && 'running' === $current['status'] ) {
			return new WP_Error( 'sed_busy', 'Un export e\' gia\' in corso.' );
		}

		// Validazioni preliminari.
		$repo = SED_Settings::resolve_repo();
		if ( '' === $repo || false === strpos( $repo, '/' ) ) {
			return new WP_Error( 'sed_repo', 'Repository non configurato: imposta almeno il proprietario (owner) GitHub nelle impostazioni.' );
		}
		if ( ! SED_Settings::has_token() ) {
			return new WP_Error( 'sed_token', 'Token GitHub mancante: configuralo nelle impostazioni.' );
		}

		$uploads = wp_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] ) . 'sed-export';
		wp_mkdir_p( $base );
		self::protect_dir( $base );

		// Conserva solo gli ultimi N export per non riempire il disco.
		self::cleanup_old_jobs( $base, max( 1, (int) SED_Settings::get( 'keep_jobs' ) ) );

		$job_id  = gmdate( 'Ymd-His' );
		$job_dir = $base . '/job-' . $job_id;
		wp_mkdir_p( $job_dir );

		$settings = SED_Settings::all();

		$job = array(
			'id'       => $job_id,
			'dir'      => $job_dir,
			'status'   => 'running',
			'phase'    => 'crawl',
			'trigger'  => $trigger,
			'repo'     => $repo,
			'started'  => time(),
			'updated'  => time(),
			'error'    => '',
			'progress' => array(
				'label'   => 'Avvio crawler...',
				'current' => 0,
				'total'   => 1,
			),
			// Snapshot delle impostazioni rilevanti per l'elaborazione.
			'opts'     => array(
				'sub_staging'  => $settings['sub_staging'],
				'sub_prod'     => $settings['sub_prod'],
				'force_https'  => $settings['force_https'],
				'site_domain'  => SED_Settings::registrable_domain(),
				'source_host'  => SED_Settings::site_host(),
				'target_host'  => '',
				'target_scheme'=> 'https',
				'ga_id'        => $settings['ga_id'],
				'adsense_id'   => $settings['adsense_id'],
				'webp_quality' => $settings['webp_quality'],
				'perf_inline_css'   => $settings['perf_inline_css'],
				'perf_font_display' => $settings['perf_font_display'],
				'perf_img_attrs'    => $settings['perf_img_attrs'],
				'preload_fonts'     => SED_Settings::preload_fonts_list(),
				'keep_js'      => $settings['keep_js'],
				'js_allowlist' => SED_Settings::js_allowlist(),
				'ads_txt'      => $settings['ads_txt'],
				'make_zips'    => $settings['make_zips'],
				'deploy_raw'   => $settings['deploy_raw'],
				'branch_raw'   => $settings['branch_raw'],
				'branch_main'  => $settings['branch_main'],
			),
		);
		$prod_target = SED_Settings::prod_url_parts();
		if ( $prod_target ) {
			$job['opts']['target_host']   = $prod_target['host'];
			$job['opts']['target_scheme'] = $prod_target['scheme'];
		}
		self::save_job( $job );

		SED_Logger::log( '====== NUOVO EXPORT (' . $trigger . ') ======' );
		SED_Logger::log( 'Repository: ' . $repo . ' | Branch: ' . $settings['branch_raw'] . ' + ' . $settings['branch_main'] );
		$prod_parts = SED_Settings::prod_url_parts();
		if ( $prod_parts ) {
			SED_Logger::log( 'Trasformazione dominio: ' . SED_Settings::site_host() . ' -> ' . $prod_parts['scheme'] . '://' . $prod_parts['host'] . ' (tutte le referenze, protocollo incluso)' . ( $settings['force_https'] ? ' | HTTPS forzato sugli altri URL interni' : '' ) );
		} else {
			SED_Logger::log( 'Trasformazione host: ' . $settings['sub_staging'] . '.<dominio> -> ' . $settings['sub_prod'] . '.<dominio>' . ( $settings['force_https'] ? ' | HTTPS forzato sugli URL interni' : '' ) );
		}
		SED_Logger::log( 'JavaScript: ' . ( $settings['keep_js'] ? 'MANTENUTO' : 'rimosso (tranne JSON-LD, GA4/AdSense e whitelist: ' . implode( ', ', SED_Settings::js_allowlist() ) . ')' ) );

		// Verifica accesso GitHub subito, per fallire in fretta.
		$gh     = new SED_GitHub( SED_Settings::get_token(), $repo );
		$access = $gh->check_access();
		if ( is_wp_error( $access ) ) {
			self::fail( $access->get_error_message() );
			return $access;
		}
		SED_Logger::log( 'Accesso GitHub verificato (permessi di push ok).' );

		// Watchdog cron mentre il job e' attivo.
		if ( ! wp_next_scheduled( 'sed_watchdog' ) ) {
			wp_schedule_event( time() + 60, 'sed_minutely', 'sed_watchdog' );
		}

		self::dispatch();
		return self::get_job();
	}

	public static function cancel_job() {
		$job = self::get_job();
		if ( $job && 'running' === $job['status'] ) {
			$job['status'] = 'cancelled';
			self::save_job( $job );
			SED_Logger::log( 'Export annullato dall\'utente.', 'warn' );
		}
		wp_clear_scheduled_hook( 'sed_watchdog' );
		self::release_lock();
	}

	private static function fail( $message ) {
		$job = self::get_job();
		if ( $job ) {
			$job['status'] = 'error';
			$job['error']  = $message;
			self::save_job( $job );
		}
		SED_Logger::log( 'ERRORE: ' . $message, 'error' );
		wp_clear_scheduled_hook( 'sed_watchdog' );
	}

	private static function finish() {
		$job = self::get_job();
		if ( $job ) {
			$job['status']            = 'done';
			$job['phase']             = 'done';
			$job['progress']['label'] = 'Export concluso e pubblicato su GitHub.';
			self::save_job( $job );
		}
		SED_Logger::log( '====== PROCEDURA GLOBALE COMPLETATA CON SUCCESSO ======' );
		wp_clear_scheduled_hook( 'sed_watchdog' );
	}

	/* ------------------------------------------------------------------ */
	/* Esecuzione asincrona                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Auto-dispatch: richiesta HTTP non bloccante verso admin-ajax per
	 * proseguire l'elaborazione in background.
	 */
	public static function dispatch() {
		$url = add_query_arg(
			array(
				'action' => 'sed_process',
				'key'    => rawurlencode( (string) get_option( 'sed_process_key' ) ),
			),
			admin_url( 'admin-ajax.php' )
		);
		wp_remote_post(
			$url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => false,
			)
		);
	}

	public static function ajax_process() {
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! $key || ! hash_equals( (string) get_option( 'sed_process_key' ), $key ) ) {
			wp_die( 'Chiave non valida.', 403 );
		}
		// Risposta immediata al client, lavoro in background.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			echo 'OK';
			fastcgi_finish_request();
		}
		self::tick();
		wp_die();
	}

	/**
	 * Un "tick" di elaborazione: lavora finche' c'e' budget di tempo, poi
	 * salva lo stato e (se il job non e' finito) auto-dispatcha il prossimo tick.
	 */
	public static function tick() {
		$job = self::get_job();
		if ( ! $job || 'running' !== $job['status'] ) {
			wp_clear_scheduled_hook( 'sed_watchdog' );
			return;
		}

		// Pausa per rate limit GitHub: il watchdog (ogni minuto) riprovera'
		// finche' il momento di ripresa non e' passato.
		if ( ! empty( $job['wait_until'] ) ) {
			if ( time() < (int) $job['wait_until'] ) {
				return;
			}
			unset( $job['wait_until'] );
			self::save_job( $job );
			SED_Logger::log( 'Rate limit GitHub ripristinato: il deploy riprende.' );
		}

		// Lock anti-concorrenza ATOMICO: add_option e' un INSERT su chiave
		// unica, quindi un solo processo puo' vincerlo anche se loopback,
		// cron e polling AJAX arrivano nello stesso istante.
		if ( ! self::acquire_lock() ) {
			return;
		}

		@set_time_limit( 300 );
		@ini_set( 'memory_limit', '512M' );
		ignore_user_abort( true );

		$budget   = max( 5, (int) SED_Settings::get( 'batch_seconds' ) );
		$deadline = microtime( true ) + $budget;

		try {
			while ( microtime( true ) < $deadline ) {
				$job = self::get_job();
				if ( ! $job || 'running' !== $job['status'] ) {
					break;
				}
				$phase_done = self::run_phase( $job, $deadline );
				$job        = self::get_job();
				if ( ! $job || 'running' !== $job['status'] ) {
					break;
				}
				if ( $phase_done ) {
					self::advance_phase( $job );
					$job = self::get_job();
					if ( $job && 'done' === $job['phase'] ) {
						break;
					}
				}
			}
		} catch ( Throwable $e ) {
			self::fail( 'Eccezione: ' . $e->getMessage() );
		}

		self::release_lock();

		$job = self::get_job();
		if ( $job && 'running' === $job['status'] && empty( $job['wait_until'] ) ) {
			self::dispatch();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Macchina a stati                                                      */
	/* ------------------------------------------------------------------ */

	private static function advance_phase( $job ) {
		$flow = array( 'crawl', 'zip_raw', 'deploy_raw', 'prepare', 'optimize', 'audit', 'zip_main', 'deploy_main', 'done' );
		$idx  = array_search( $job['phase'], $flow, true );

		// Avanza saltando le fasi disabilitate dalle opzioni.
		do {
			$idx  = min( $idx + 1, count( $flow ) - 1 );
			$next = $flow[ $idx ];
			$skip = ( 'deploy_raw' === $next && empty( $job['opts']['deploy_raw'] ) )
				|| ( in_array( $next, array( 'zip_raw', 'zip_main' ), true ) && empty( $job['opts']['make_zips'] ) );
		} while ( $skip && 'done' !== $next );

		if ( 'done' === $next ) {
			self::finish();
			return;
		}

		$job['phase'] = $next;
		$labels       = array(
			'crawl'       => 'Export del sito (crawler)...',
			'zip_raw'     => 'Creazione ZIP del sito base (raw)...',
			'deploy_raw'  => 'FASE 1: caricamento sito base su branch "' . $job['opts']['branch_raw'] . '"...',
			'prepare'     => 'FASE 2: preparazione ottimizzazione...',
			'optimize'    => 'FASE 2: ottimizzazione del sito...',
			'audit'       => 'Verifica link interni e audit SEO...',
			'zip_main'    => 'Creazione ZIP del sito ottimizzato (main)...',
			'deploy_main' => 'FASE 3: caricamento sito ottimizzato su branch "' . $job['opts']['branch_main'] . '"...',
		);
		$job['progress'] = array(
			'label'   => $labels[ $next ] ?? $next,
			'current' => 0,
			'total'   => 1,
		);
		self::save_job( $job );
		SED_Logger::log( $labels[ $next ] ?? $next );
	}

	/**
	 * Esegue (un pezzo del)la fase corrente.
	 *
	 * @return bool TRUE se la fase e' completata.
	 */
	private static function run_phase( $job, $deadline ) {
		switch ( $job['phase'] ) {

			case 'crawl':
				$crawler = new SED_Crawler( $job['dir'] );
				$done    = $crawler->process_chunk( $deadline );
				$p       = $crawler->progress();
				self::update_progress( 'Export del sito: ' . $p['current'] . ' URL scaricati...', $p['current'], $p['total'] );
				if ( $done ) {
					$failed = $crawler->failed_urls();
					SED_Logger::log( 'Crawl completato: ' . $p['current'] . ' URL elaborati, ' . count( $failed ) . ' falliti.' );
					foreach ( array_slice( $failed, 0, 20 ) as $f ) {
						SED_Logger::log( 'Fallito: ' . $f, 'warn' );
					}
				}
				return $done;

			case 'zip_raw':
				self::make_zip( $job['dir'] . '/raw', $job['dir'] . '/raw.zip', 'raw' );
				return true;

			case 'deploy_raw':
				return self::run_deploy( $job, $deadline, $job['dir'] . '/raw', $job['opts']['branch_raw'], 'raw' );

			case 'prepare':
				$copied = SED_Optimizer::rcopy( $job['dir'] . '/raw', $job['dir'] . '/opt' );
				SED_Logger::log( 'Copia di lavoro creata (' . $copied . ' file).' );

				$optimizer = new SED_Optimizer( $job['dir'] . '/opt', $job['opts'] );
				$optimizer->prepare_structure();

				$files = $optimizer->list_files();
				file_put_contents( $job['dir'] . '/optimize-files.json', wp_json_encode( array(
					'files' => $files,
					'index' => 0,
				) ) );
				SED_Logger::log( 'Elaborazione di ' . count( $files ) . ' file in background...' );
				return true;

			case 'optimize':
				$state_file = $job['dir'] . '/optimize-files.json';
				$state      = json_decode( (string) file_get_contents( $state_file ), true );
				if ( ! is_array( $state ) || empty( $state['files'] ) ) {
					return true;
				}
				$optimizer = new SED_Optimizer( $job['dir'] . '/opt', $job['opts'] );
				$total     = count( $state['files'] );

				while ( $state['index'] < $total && microtime( true ) < $deadline ) {
					$error = $optimizer->process_file( $state['files'][ $state['index'] ] );
					if ( $error ) {
						SED_Logger::log( $error, 'warn' );
					}
					$state['index']++;
				}
				file_put_contents( $state_file, wp_json_encode( $state ) );
				self::update_progress( 'Ottimizzazione: ' . $state['index'] . '/' . $total . ' file...', $state['index'], $total );

				if ( $state['index'] >= $total ) {
					$optimizer->remove_empty_dirs();
					SED_Logger::log( 'Ottimizzazione completata (' . $total . ' file).' );
					return true;
				}
				return false;

			case 'audit':
				$audit  = new SED_Audit( $job['dir'] . '/opt', $job['opts'] );
				$report = $job['dir'] . '/report.txt';
				$result = $audit->run( $report );
				SED_Logger::log( 'Report generato: link/risorse rotte: ' . $result['broken'] . ' | avvisi SEO: ' . $result['seo'] );
				return true;

			case 'zip_main':
				self::make_zip( $job['dir'] . '/opt', $job['dir'] . '/main.zip', 'main' );
				return true;

			case 'deploy_main':
				return self::run_deploy( $job, $deadline, $job['dir'] . '/opt', $job['opts']['branch_main'], 'main' );
		}
		return true;
	}

	/**
	 * Crea uno ZIP scaricabile di una cartella (raw o main).
	 * Non blocca la pipeline in caso di errore: il deploy procede comunque.
	 */
	private static function make_zip( $source_dir, $zip_path, $tag ) {
		@set_time_limit( 300 );

		if ( ! class_exists( 'ZipArchive' ) ) {
			SED_Logger::log( 'ZipArchive non disponibile su questo hosting: ZIP "' . $tag . '" saltato.', 'warn' );
			return;
		}
		if ( ! is_dir( $source_dir ) ) {
			return;
		}

		@unlink( $zip_path );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			SED_Logger::log( 'Impossibile creare lo ZIP "' . $tag . '".', 'warn' );
			return;
		}

		$count    = 0;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $item ) {
			if ( ! $item->isFile() ) {
				continue;
			}
			$rel = ltrim( str_replace( '\\', '/', substr( $item->getPathname(), strlen( $source_dir ) ) ), '/' );
			// Le immagini sono gia' compresse: stored, molto piu' veloce.
			$zip->addFile( $item->getPathname(), $rel );
			if ( preg_match( '/\.(webp|png|jpe?g|gif|woff2?|mp4|zip)$/i', $rel ) && method_exists( $zip, 'setCompressionName' ) ) {
				$zip->setCompressionName( $rel, ZipArchive::CM_STORE );
			}
			$count++;
		}
		$zip->close();

		SED_Logger::log( 'ZIP "' . $tag . '" creato: ' . $count . ' file, ' . size_format( (int) @filesize( $zip_path ) ) . ' — scaricabile dalla dashboard.' );
	}

	/* ------------------------------------------------------------------ */
	/* Deploy veloce: deduplica SHA + upload paralleli + rate limit          */
	/* ------------------------------------------------------------------ */

	/**
	 * Mette il job in pausa fino al reset del rate limit GitHub.
	 * Idempotente: se una pausa e' gia' attiva non logga di nuovo.
	 */
	private static function pause_for_rate( $resume, $context ) {
		$job = self::get_job();
		if ( ! $job ) {
			return;
		}
		$resume = max( time() + 60, (int) $resume );

		// Pausa gia' attiva (tick concorrente): non duplicare log e stato.
		if ( ! empty( $job['wait_until'] ) && (int) $job['wait_until'] >= time() ) {
			return;
		}

		$job['wait_until']        = $resume;
		$job['progress']['label'] = $context . ': rate limit GitHub, ripresa automatica alle ' . gmdate( 'H:i', $resume ) . ' UTC...';
		self::save_job( $job );
		SED_Logger::log( 'Rate limit API GitHub raggiunto: job in pausa fino alle ' . gmdate( 'H:i:s', $resume ) . ' UTC (ripresa automatica).', 'warn' );
	}

	/**
	 * Deploy di una cartella su un branch. Due motori:
	 *
	 *  PACK (default) — l'equivalente di "zip e carica": tutti gli oggetti
	 *    git vengono costruiti in locale e spediti in UN SOLO upload sul
	 *    protocollo git nativo, che NON consuma il rate limit dell'API REST.
	 *    Servono solo 2-3 chiamate REST iniziali per la deduplica.
	 *  API — fallback file-per-file via API REST (parallelo + dedup), usato
	 *    se il push del pacchetto fallisce due volte o se scelto in settings.
	 *
	 * @param string $tag 'raw'|'main' (usato per i file di stato).
	 * @return bool TRUE se il deploy della fase e' completato.
	 */
	private static function run_deploy( $job, $deadline, $source_dir, $branch, $tag ) {
		$state_file = $job['dir'] . '/deploy-' . $tag . '.json';
		$state      = file_exists( $state_file ) ? json_decode( (string) file_get_contents( $state_file ), true ) : null;
		$gh         = new SED_GitHub( SED_Settings::get_token(), $job['repo'] );

		// --- Stage 1: init (lista file, SHA locali, dedup, preserve) ---
		if ( ! is_array( $state ) ) {
			$files    = array();
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $item ) {
				if ( ! $item->isFile() ) {
					continue;
				}
				$rel = ltrim( str_replace( '\\', '/', substr( $item->getPathname(), strlen( $source_dir ) ) ), '/' );
				$sha = SED_GitHub::local_blob_sha( $item->getPathname() );
				if ( null === $sha ) {
					SED_Logger::log( 'File illeggibile saltato: ' . $rel, 'warn' );
					continue;
				}
				$files[ $rel ] = $sha;
			}
			ksort( $files );

			if ( empty( $files ) ) {
				SED_Logger::log( 'Nessun file da caricare per il branch ' . $branch . '.', 'warn' );
				return true;
			}

			$engine = ( 'api' === SED_Settings::get( 'deploy_engine' ) ) ? 'api' : 'pack';

			// Head attuale del branch (1 chiamata; 404 = branch nuovo, ok).
			$head_sha = null;
			$ref      = $gh->request( 'GET', '/repos/' . $job['repo'] . '/git/ref/heads/' . rawurlencode( $branch ) );
			if ( is_wp_error( $ref ) ) {
				if ( 'github_rate' === $ref->get_error_code() ) {
					self::pause_for_rate( $ref->get_error_data()['resume'] ?? 0, 'Deploy ' . $branch );
					return false;
				}
				// Branch inesistente o repo vuoto: il motore pack lo creera'
				// direttamente; quello API usera' ensure_branch piu' avanti.
			} else {
				$head_sha = $ref['object']['sha'] ?? null;
			}

			// Tree remoto (2 chiamate): deduplica + file di root da preservare.
			$remote = array(
				'tree_sha'  => null,
				'map'       => array(),
				'shas'      => array(),
				'truncated' => false,
			);
			if ( $head_sha ) {
				$remote = $gh->get_tree_map( $head_sha );
				if ( is_wp_error( $remote ) ) {
					self::pause_for_rate( $remote->get_error_data()['resume'] ?? 0, 'Deploy ' . $branch );
					return false;
				}
			}

			$needed = array();
			$reused = 0;
			foreach ( $files as $rel => $sha ) {
				if ( ! $remote['truncated'] && isset( $remote['shas'][ $sha ] ) ) {
					$reused++;
				} else {
					$needed[] = $rel;
				}
			}

			$preserve_entries = array();
			foreach ( SED_Settings::preserve_list() as $name ) {
				foreach ( $remote['map'] as $path => $info ) {
					if ( false === strpos( $path, '/' ) && strtolower( $path ) === $name && ! isset( $files[ $path ] ) ) {
						$preserve_entries[] = array(
							'path' => $path,
							'mode' => $info['mode'],
							'type' => 'blob',
							'sha'  => $info['sha'],
						);
					}
				}
			}

			$state = array(
				'engine'           => $engine,
				'files'            => $files,
				'needed'           => $needed,
				'index'            => 0,
				'head_sha'         => $head_sha,
				'head_tree_sha'    => $remote['tree_sha'],
				'preserve_entries' => $preserve_entries,
				'branch_ready'     => (bool) $head_sha,
				'pack_attempts'    => 0,
			);
			file_put_contents( $state_file, wp_json_encode( $state ) );

			SED_Logger::log( sprintf(
				'Deploy branch "%s" [motore: %s]: %d file totali — %d invariati riutilizzati (deduplica SHA), %d da caricare.',
				$branch,
				'pack' === $engine ? 'pacchetto git, 1 upload' : 'API REST',
				count( $files ),
				$reused,
				count( $needed )
			) );
		}

		$message = 'Deploy automatico branch ' . $branch . ' — ' . gmdate( 'Y-m-d H:i' ) . ' UTC (Static Export & Deploy)';

		/* ---------------- Motore PACK: un solo upload ---------------- */
		if ( 'pack' === $state['engine'] ) {
			@set_time_limit( 0 ); // L'upload unico puo' superare il budget del tick.
			self::update_progress( 'Deploy ' . $branch . ': costruzione e upload del pacchetto git (upload unico)...', 0, 1 );

			$files_arg = array();
			foreach ( $state['files'] as $rel => $sha ) {
				$files_arg[ $rel ] = array(
					'abs' => $source_dir . '/' . $rel,
					'sha' => $sha,
				);
			}
			// SHA gia' presenti sul server = tutti tranne i "needed".
			$needed_set = array_flip( $state['needed'] );
			$known      = array();
			foreach ( $state['files'] as $rel => $sha ) {
				if ( ! isset( $needed_set[ $rel ] ) ) {
					$known[ $sha ] = 1;
				}
			}

			$pusher = new SED_GitPack( SED_Settings::get_token(), $job['repo'] );
			$result = $pusher->push( $branch, $files_arg, $known, $state['preserve_entries'], $state['head_tree_sha'], $message, $job['dir'] );

			if ( is_wp_error( $result ) ) {
				$state['pack_attempts']++;
				if ( $state['pack_attempts'] >= 2 ) {
					$state['engine'] = 'api';
					SED_Logger::log( 'Push del pacchetto fallito di nuovo (' . $result->get_error_message() . '): passo al motore API REST.', 'warn' );
				} else {
					SED_Logger::log( 'Push del pacchetto fallito (' . $result->get_error_message() . '): nuovo tentativo tra poco.', 'warn' );
				}
				file_put_contents( $state_file, wp_json_encode( $state ) );
				return false;
			}

			if ( ! empty( $result['skipped'] ) ) {
				SED_Logger::log( 'Branch "' . $branch . '": nessuna modifica rilevata, push saltato (zero upload).' );
			} else {
				SED_Logger::log( sprintf(
					'Branch "%s": push completato in 1 upload — %d oggetti, %s (commit %s).',
					$branch,
					$result['objects'],
					size_format( (int) $result['bytes'] ),
					substr( (string) $result['sha'], 0, 7 )
				) );
			}
			return true;
		}

		/* --------------- Motore API REST (fallback) ------------------ */

		// Il branch potrebbe non esistere ancora (in modalita' pack non
		// serviva crearlo): l'API REST invece deve poter aggiornare il ref.
		if ( empty( $state['branch_ready'] ) ) {
			$ref = $gh->ensure_branch( $branch );
			if ( is_wp_error( $ref ) ) {
				if ( 'github_rate' === $ref->get_error_code() ) {
					self::pause_for_rate( $ref->get_error_data()['resume'] ?? 0, 'Deploy ' . $branch );
					return false;
				}
				self::fail( 'Deploy ' . $branch . ': ' . $ref->get_error_message() );
				return false;
			}
			$state['head_sha']     = $ref['sha'];
			$state['branch_ready'] = true;
			file_put_contents( $state_file, wp_json_encode( $state ) );
		}

		// --- Stage 2: creazione blob a ondate parallele ---
		$total    = count( $state['needed'] );
		$parallel = min( 20, max( 1, (int) SED_Settings::get( 'parallel_uploads' ) ) );

		while ( $state['index'] < $total && microtime( true ) < $deadline ) {
			$chunk = array();
			foreach ( array_slice( $state['needed'], $state['index'], $parallel ) as $rel ) {
				$chunk[ $rel ] = $source_dir . '/' . $rel;
			}

			$results = $gh->create_blobs_parallel( $chunk );

			foreach ( $chunk as $rel => $abs ) {
				$sha = isset( $results[ $rel ] ) ? $results[ $rel ] : new WP_Error( 'sed_blob', 'Nessuna risposta per ' . $rel );
				if ( is_wp_error( $sha ) ) {
					file_put_contents( $state_file, wp_json_encode( $state ) );
					if ( 'github_rate' === $sha->get_error_code() ) {
						self::pause_for_rate( $sha->get_error_data()['resume'] ?? 0, 'Deploy ' . $branch );
						return false;
					}
					self::fail( 'Deploy ' . $branch . ' (blob ' . $rel . '): ' . $sha->get_error_message() );
					return false;
				}
				// Sanity check: lo SHA remoto deve coincidere con quello locale.
				if ( $sha !== $state['files'][ $rel ] ) {
					SED_Logger::log( 'SHA inatteso per ' . $rel . ': uso quello remoto.', 'warn' );
					$state['files'][ $rel ] = $sha;
				}
				$state['index']++;
			}
			file_put_contents( $state_file, wp_json_encode( $state ) );
			self::update_progress( 'Upload su GitHub (' . $branch . '): ' . $state['index'] . '/' . $total . ' file nuovi/modificati...', $state['index'], max( 1, $total ) );

			// Budget API quasi esaurito? Pausa preventiva fino al reset.
			$rate = $gh->rate_limit();
			if ( $rate && $rate['remaining'] <= 30 && $state['index'] < $total ) {
				self::pause_for_rate( $rate['reset'] + 5, 'Deploy ' . $branch );
				return false;
			}
		}

		if ( $state['index'] < $total ) {
			return false; // Si continua al prossimo tick.
		}

		// --- Stage 3: tree + commit + aggiornamento ref ---
		$entries = $state['preserve_entries'];
		foreach ( $state['files'] as $rel => $sha ) {
			$entries[] = array(
				'path' => $rel,
				'mode' => '100644',
				'type' => 'blob',
				'sha'  => $sha,
			);
		}

		$result = $gh->commit_entries(
			$branch,
			$state['head_sha'],
			$state['head_tree_sha'],
			$entries,
			$message
		);
		if ( is_wp_error( $result ) ) {
			if ( 'github_rate' === $result->get_error_code() ) {
				self::pause_for_rate( $result->get_error_data()['resume'] ?? 0, 'Deploy ' . $branch );
				return false;
			}
			self::fail( 'Deploy ' . $branch . ' (commit): ' . $result->get_error_message() );
			return false;
		}
		if ( ! empty( $result['skipped'] ) ) {
			SED_Logger::log( 'Branch "' . $branch . '": nessuna modifica rilevata, push saltato (zero upload).' );
		} else {
			SED_Logger::log( 'Branch "' . $branch . '": push completato (commit ' . substr( (string) $result['sha'], 0, 7 ) . ').' );
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Helper                                                                */
	/* ------------------------------------------------------------------ */

	private static function update_progress( $label, $current, $total ) {
		$job = self::get_job();
		if ( ! $job ) {
			return;
		}
		$job['progress'] = array(
			'label'   => $label,
			'current' => (int) $current,
			'total'   => max( 1, (int) $total ),
		);
		self::save_job( $job );
	}

	/**
	 * Acquisizione atomica del lock di elaborazione.
	 */
	private static function acquire_lock() {
		if ( add_option( self::LOCK, time(), '', 'no' ) ) {
			return true;
		}
		// Lock orfano (processo morto): scaduto dopo 120 secondi.
		$ts = (int) get_option( self::LOCK );
		if ( $ts && time() - $ts > 120 ) {
			delete_option( self::LOCK );
			return add_option( self::LOCK, time(), '', 'no' );
		}
		return false;
	}

	private static function release_lock() {
		delete_option( self::LOCK );
	}

	/* ------------------------------------------------------------------ */
	/* Gestione artefatti (cartelle job su disco)                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Cartella base degli export: wp-content/uploads/sed-export.
	 */
	public static function base_dir() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'sed-export';
	}

	/**
	 * Elenco degli export presenti su disco, dal piu' recente.
	 *
	 * @return array[] [id, dir, time, total_size, is_current, status,
	 *                  files => [nome => byte]]
	 */
	public static function list_jobs() {
		$out     = array();
		$current = self::get_job();
		$dirs    = glob( self::base_dir() . '/job-*', GLOB_ONLYDIR );
		if ( ! is_array( $dirs ) ) {
			return $out;
		}
		rsort( $dirs );

		foreach ( $dirs as $dir ) {
			$id = substr( basename( $dir ), 4 );
			if ( ! preg_match( '/^\d{8}-\d{6}$/', $id ) ) {
				continue;
			}
			$files = array();
			foreach ( array( 'raw.zip', 'main.zip', 'report.txt', 'log.txt' ) as $name ) {
				if ( file_exists( $dir . '/' . $name ) ) {
					$files[ $name ] = (int) filesize( $dir . '/' . $name );
				}
			}
			$is_current = $current && $current['id'] === $id;
			$out[]      = array(
				'id'         => $id,
				'dir'        => $dir,
				'time'       => self::job_id_to_time( $id ),
				'total_size' => self::dir_size( $dir ),
				'is_current' => $is_current,
				'status'     => $is_current ? $current['status'] : 'archived',
				'files'      => $files,
			);
		}
		return $out;
	}

	/**
	 * Elimina la cartella di un export. Rifiuta il job in esecuzione.
	 *
	 * @return true|WP_Error
	 */
	public static function delete_job_dir( $id ) {
		if ( ! preg_match( '/^\d{8}-\d{6}$/', $id ) ) {
			return new WP_Error( 'sed_bad_id', 'Identificativo export non valido.' );
		}
		$current = self::get_job();
		if ( $current && $current['id'] === $id && 'running' === $current['status'] ) {
			return new WP_Error( 'sed_running', 'L\'export e\' in esecuzione: annullalo prima di eliminarlo.' );
		}
		$dir = self::base_dir() . '/job-' . $id;
		if ( ! is_dir( $dir ) ) {
			return new WP_Error( 'sed_missing', 'Export non trovato.' );
		}
		SED_Optimizer::rrmdir( $dir );

		// Se era l'export riferito dalla dashboard, azzera lo stato.
		if ( $current && $current['id'] === $id ) {
			delete_option( self::JOB_OPTION );
		}
		return true;
	}

	/**
	 * Percorso assoluto di un artefatto scaricabile, con validazioni.
	 *
	 * @return string|WP_Error
	 */
	public static function artifact_path( $id, $file ) {
		if ( ! preg_match( '/^\d{8}-\d{6}$/', $id ) ) {
			return new WP_Error( 'sed_bad_id', 'Identificativo export non valido.' );
		}
		$allowed = array( 'raw.zip', 'main.zip', 'report.txt', 'log.txt' );
		if ( ! in_array( $file, $allowed, true ) ) {
			return new WP_Error( 'sed_bad_file', 'Artefatto non valido.' );
		}
		$path = self::base_dir() . '/job-' . $id . '/' . $file;
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'sed_missing', 'File non trovato.' );
		}
		return $path;
	}

	public static function dir_size( $dir ) {
		$size = 0;
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $item ) {
			if ( $item->isFile() ) {
				$size += $item->getSize();
			}
		}
		return $size;
	}

	private static function job_id_to_time( $id ) {
		$dt = DateTime::createFromFormat( 'Ymd-His', $id, new DateTimeZone( 'UTC' ) );
		return $dt ? $dt->getTimestamp() : 0;
	}

	private static function protect_dir( $dir ) {
		if ( ! file_exists( $dir . '/index.html' ) ) {
			@file_put_contents( $dir . '/index.html', '' );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Require all denied\n" );
		}
	}

	private static function cleanup_old_jobs( $base, $keep ) {
		$dirs = glob( $base . '/job-*', GLOB_ONLYDIR );
		if ( ! is_array( $dirs ) || count( $dirs ) <= $keep ) {
			return;
		}
		sort( $dirs );
		foreach ( array_slice( $dirs, 0, count( $dirs ) - $keep ) as $dir ) {
			SED_Optimizer::rrmdir( $dir );
		}
	}
}
