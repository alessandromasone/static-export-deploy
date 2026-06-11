<?php
/**
 * Deploy su GitHub tramite API REST (Git Data API), ottimizzato per velocita'
 * e consumo di API:
 *
 *  - DEDUPLICA: lo SHA git di ogni file viene calcolato in locale
 *    ("blob {size}\0{contenuto}") e confrontato con il tree remoto; i file
 *    invariati NON vengono ricaricati (zero chiamate API) e vengono
 *    referenziati direttamente nel nuovo tree. Dal secondo deploy in poi
 *    si caricano solo i file realmente cambiati.
 *  - PARALLELISMO: i blob mancanti vengono creati a ondate concorrenti
 *    (curl_multi tramite la libreria Requests inclusa in WordPress).
 *  - RATE LIMIT: gli header X-RateLimit-* vengono letti da ogni risposta;
 *    quando il budget si esaurisce viene restituito un errore 'github_rate'
 *    con il timestamp di ripresa, che la coda usa per mettere in pausa il
 *    job e riprenderlo automaticamente dopo il reset.
 *
 * Come nello script Python originale: tree completo (sostituzione totale del
 * branch) con preservazione dei file di root configurati, push saltato se
 * non ci sono modifiche, gestione di branch mancanti e repository vuoti.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SED_GitHub {

	const API = 'https://api.github.com';

	/** @var string */
	private $token;

	/** @var string owner/repo */
	private $repo;

	/** @var array|null Ultimo stato del rate limit letto dagli header. */
	private $rate = null;

	public function __construct( $token, $repo ) {
		$this->token = $token;
		$this->repo  = trim( $repo, '/' );
	}

	/**
	 * ['remaining' => int, 'reset' => timestamp] oppure null se ignoto.
	 */
	public function rate_limit() {
		return $this->rate;
	}

	/* ------------------------------------------------------------------ */
	/* HTTP + rate limit                                                     */
	/* ------------------------------------------------------------------ */

	private function auth_headers() {
		return array(
			'Authorization'        => 'Bearer ' . $this->token,
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'SED-WordPress-Plugin/' . SED_VERSION,
		);
	}

	private function track_rate_headers( $headers ) {
		if ( isset( $headers['x-ratelimit-remaining'] ) ) {
			$this->rate = array(
				'remaining' => (int) $headers['x-ratelimit-remaining'],
				'reset'     => isset( $headers['x-ratelimit-reset'] ) ? (int) $headers['x-ratelimit-reset'] : ( time() + 300 ),
			);
		}
	}

	/**
	 * Se la risposta indica un rate limit (primario o secondario) restituisce
	 * un WP_Error 'github_rate' con ['resume' => timestamp]; altrimenti null.
	 */
	private function rate_error_from( $code, $message, $headers ) {
		$exhausted = isset( $headers['x-ratelimit-remaining'] ) && 0 === (int) $headers['x-ratelimit-remaining'];
		$is_rate   = ( 403 === $code || 429 === $code )
			&& ( $exhausted || isset( $headers['retry-after'] ) || false !== stripos( (string) $message, 'rate limit' ) );

		if ( ! $is_rate ) {
			return null;
		}

		$resume = time() + 120;
		if ( isset( $headers['retry-after'] ) ) {
			$resume = time() + max( 30, (int) $headers['retry-after'] );
		} elseif ( isset( $headers['x-ratelimit-reset'] ) ) {
			$resume = (int) $headers['x-ratelimit-reset'] + 5;
		}
		return new WP_Error( 'github_rate', 'Rate limit API GitHub raggiunto.', array( 'resume' => $resume ) );
	}

	/**
	 * @return array|WP_Error Corpo decodificato o errore.
	 * Gli errori 5xx (transitori, es. 502 sui tree grandi) vengono
	 * ritentati fino a 3 volte con breve attesa.
	 */
	public function request( $method, $path, $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 60,
			'headers' => $this->auth_headers(),
		);
		if ( null !== $body ) {
			$args['body']                    = wp_json_encode( $body );
			$args['headers']['Content-Type'] = 'application/json';
		}

		$last_error = null;
		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$response = wp_remote_request( self::API . $path, $args );
			if ( is_wp_error( $response ) ) {
				$last_error = $response;
				if ( $attempt < 3 ) {
					sleep( $attempt );
					continue;
				}
				return $response;
			}

			$code    = (int) wp_remote_retrieve_response_code( $response );
			$headers = array_change_key_case( (array) wp_remote_retrieve_headers( $response ), CASE_LOWER );
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

			$this->track_rate_headers( $headers );

			// Errore transitorio del server: nuovo tentativo.
			if ( $code >= 500 && $attempt < 3 ) {
				$last_error = new WP_Error( 'github_' . $code, 'GitHub API ' . $method . ' ' . $path . ' -> ' . $code );
				sleep( 2 * $attempt );
				continue;
			}

			if ( $code >= 400 ) {
				$message = is_array( $decoded ) && ! empty( $decoded['message'] ) ? $decoded['message'] : 'HTTP ' . $code;
				$rate    = $this->rate_error_from( $code, $message, $headers );
				if ( $rate ) {
					return $rate;
				}
				return new WP_Error( 'github_' . $code, sprintf( 'GitHub API %s %s -> %d: %s', $method, $path, $code, $message ), array( 'status' => $code ) );
			}
			return is_array( $decoded ) ? $decoded : array();
		}
		return $last_error ?: new WP_Error( 'github_unknown', 'Errore sconosciuto.' );
	}

	/**
	 * Verifica token e accesso al repository. Restituisce true o WP_Error.
	 */
	public function check_access() {
		$repo = $this->request( 'GET', '/repos/' . $this->repo );
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}
		if ( empty( $repo['permissions']['push'] ) ) {
			return new WP_Error( 'github_perm', 'Il token non ha permessi di scrittura (push) sul repository ' . $this->repo . '.' );
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Branch                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Garantisce l'esistenza del branch e ne restituisce lo stato.
	 *
	 * @return array|WP_Error ['sha' => commit sha | null]
	 */
	public function ensure_branch( $branch ) {
		$ref = $this->request( 'GET', '/repos/' . $this->repo . '/git/ref/heads/' . rawurlencode( $branch ) );
		if ( ! is_wp_error( $ref ) && ! empty( $ref['object']['sha'] ) ) {
			return array( 'sha' => $ref['object']['sha'] );
		}
		if ( is_wp_error( $ref ) && 'github_rate' === $ref->get_error_code() ) {
			return $ref;
		}

		// Il branch non esiste: serve un commit di partenza.
		$repo = $this->request( 'GET', '/repos/' . $this->repo );
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}
		$default = $repo['default_branch'] ?? 'main';

		$default_ref = $this->request( 'GET', '/repos/' . $this->repo . '/git/ref/heads/' . rawurlencode( $default ) );

		if ( is_wp_error( $default_ref ) || empty( $default_ref['object']['sha'] ) ) {
			if ( is_wp_error( $default_ref ) && 'github_rate' === $default_ref->get_error_code() ) {
				return $default_ref;
			}
			// Repository vuoto: lo inizializziamo creando un file direttamente
			// sul branch richiesto tramite la Contents API.
			$payload = array(
				'message' => 'Inizializzazione repository (Static Export & Deploy)',
				'content' => base64_encode( "Repository inizializzato dal plugin Static Export & Deploy.\n" ),
				'branch'  => $branch,
			);
			$init = $this->request( 'PUT', '/repos/' . $this->repo . '/contents/.sed-init', $payload );
			if ( is_wp_error( $init ) ) {
				// Secondo tentativo: inizializza il branch di default e poi crea il ref.
				unset( $payload['branch'] );
				$init = $this->request( 'PUT', '/repos/' . $this->repo . '/contents/.sed-init', $payload );
				if ( is_wp_error( $init ) ) {
					return $init;
				}
				$base_sha = $init['commit']['sha'] ?? null;
				if ( $branch !== $default && $base_sha ) {
					$created = $this->request( 'POST', '/repos/' . $this->repo . '/git/refs', array(
						'ref' => 'refs/heads/' . $branch,
						'sha' => $base_sha,
					) );
					if ( is_wp_error( $created ) ) {
						return $created;
					}
				}
				return array( 'sha' => $base_sha );
			}
			return array( 'sha' => $init['commit']['sha'] ?? null );
		}

		// Repo non vuoto: crea il branch dal default.
		$created = $this->request( 'POST', '/repos/' . $this->repo . '/git/refs', array(
			'ref' => 'refs/heads/' . $branch,
			'sha' => $default_ref['object']['sha'],
		) );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		return array( 'sha' => $default_ref['object']['sha'] );
	}

	/* ------------------------------------------------------------------ */
	/* SHA locale + tree remoto (deduplica)                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * SHA-1 git di un file calcolato in locale (in streaming, senza caricare
	 * tutto in memoria): identico a quello che GitHub assegnerebbe al blob.
	 * Permette di sapere se un file e' GIA' nel repository senza caricarlo.
	 *
	 * @return string|null
	 */
	public static function local_blob_sha( $abs_path ) {
		$size = @filesize( $abs_path );
		if ( false === $size ) {
			return null;
		}
		$fh = @fopen( $abs_path, 'rb' );
		if ( ! $fh ) {
			return null;
		}
		$ctx = hash_init( 'sha1' );
		hash_update( $ctx, 'blob ' . $size . "\0" );
		while ( ! feof( $fh ) ) {
			hash_update( $ctx, (string) fread( $fh, 1048576 ) );
		}
		fclose( $fh );
		return hash_final( $ctx );
	}

	/**
	 * Tree ricorsivo del commit indicato: 2 sole chiamate API per conoscere
	 * lo SHA di TUTTI i file gia' presenti nel branch.
	 *
	 * @return array|WP_Error ['tree_sha' => string|null,
	 *                         'map'      => [path => ['sha','mode']],
	 *                         'shas'     => [sha => 1],
	 *                         'truncated'=> bool]
	 */
	public function get_tree_map( $commit_sha ) {
		$out = array(
			'tree_sha'  => null,
			'map'       => array(),
			'shas'      => array(),
			'truncated' => false,
		);
		if ( ! $commit_sha ) {
			return $out;
		}

		$commit = $this->request( 'GET', '/repos/' . $this->repo . '/git/commits/' . $commit_sha );
		if ( is_wp_error( $commit ) ) {
			return $commit;
		}
		$out['tree_sha'] = $commit['tree']['sha'] ?? null;
		if ( ! $out['tree_sha'] ) {
			return $out;
		}

		$tree = $this->request( 'GET', '/repos/' . $this->repo . '/git/trees/' . $out['tree_sha'] . '?recursive=1' );
		if ( is_wp_error( $tree ) ) {
			if ( 'github_rate' === $tree->get_error_code() ) {
				return $tree;
			}
			return $out; // Non fatale: senza mappa si caricano tutti i blob.
		}

		$out['truncated'] = ! empty( $tree['truncated'] );
		foreach ( (array) ( $tree['tree'] ?? array() ) as $item ) {
			if ( 'blob' !== ( $item['type'] ?? '' ) || ! isset( $item['path'], $item['sha'] ) ) {
				continue;
			}
			$out['map'][ $item['path'] ] = array(
				'sha'  => $item['sha'],
				'mode' => $item['mode'] ?? '100644',
			);
			$out['shas'][ $item['sha'] ] = 1;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Creazione blob                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Crea un blob da un file locale (singola richiesta).
	 *
	 * @return string|WP_Error SHA del blob.
	 */
	public function create_blob_from_file( $abs_path ) {
		$content = @file_get_contents( $abs_path );
		if ( false === $content ) {
			return new WP_Error( 'sed_read', 'Impossibile leggere il file ' . $abs_path );
		}
		$result = $this->request( 'POST', '/repos/' . $this->repo . '/git/blobs', array(
			'content'  => base64_encode( $content ),
			'encoding' => 'base64',
		) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return isset( $result['sha'] ) ? $result['sha'] : new WP_Error( 'sed_blob', 'Risposta blob inattesa.' );
	}

	/**
	 * Crea piu' blob IN PARALLELO (un'ondata di richieste concorrenti via
	 * curl_multi, tramite la libreria Requests inclusa in WordPress).
	 *
	 * @param array $files Mappa path relativo -> path assoluto. Il chiamante
	 *                     dimensiona il lotto (= livello di parallelismo).
	 * @return array Mappa path relativo -> sha (string) oppure WP_Error.
	 */
	public function create_blobs_parallel( $files ) {
		if ( empty( $files ) ) {
			return array();
		}

		$class = null;
		if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
			$class = '\WpOrg\Requests\Requests'; // WP >= 6.2.
		} elseif ( class_exists( 'Requests' ) ) {
			$class = 'Requests'; // WP < 6.2.
		}

		// Fallback sequenziale se la libreria non e' disponibile.
		if ( ! $class || 1 === count( $files ) ) {
			$out = array();
			foreach ( $files as $rel => $abs ) {
				$out[ $rel ] = $this->create_blob_from_file( $abs );
			}
			return $out;
		}

		$headers                 = $this->auth_headers();
		$headers['Content-Type'] = 'application/json';

		$out      = array();
		$requests = array();
		foreach ( $files as $rel => $abs ) {
			$content = @file_get_contents( $abs );
			if ( false === $content ) {
				$out[ $rel ] = new WP_Error( 'sed_read', 'Impossibile leggere il file ' . $abs );
				continue;
			}
			$requests[ $rel ] = array(
				'url'     => self::API . '/repos/' . $this->repo . '/git/blobs',
				'type'    => 'POST',
				'headers' => $headers,
				'data'    => wp_json_encode( array(
					'content'  => base64_encode( $content ),
					'encoding' => 'base64',
				) ),
			);
		}
		if ( empty( $requests ) ) {
			return $out;
		}

		try {
			$responses = call_user_func( array( $class, 'request_multiple' ), $requests, array( 'timeout' => 60 ) );
		} catch ( Throwable $e ) {
			$responses = null;
		}

		foreach ( $requests as $rel => $unused ) {
			$response = is_array( $responses ) && isset( $responses[ $rel ] ) ? $responses[ $rel ] : null;

			if ( ! is_object( $response ) || ! isset( $response->status_code ) ) {
				// Eccezione su questa richiesta (o ondata fallita): retry singolo.
				$out[ $rel ] = $this->create_blob_from_file( $files[ $rel ] );
				continue;
			}

			$resp_headers = array();
			if ( isset( $response->headers ) ) {
				foreach ( $response->headers as $k => $v ) {
					$resp_headers[ strtolower( $k ) ] = is_array( $v ) ? reset( $v ) : (string) $v;
				}
			}
			$this->track_rate_headers( $resp_headers );

			$code    = (int) $response->status_code;
			$decoded = json_decode( (string) $response->body, true );

			if ( $code >= 400 ) {
				$message     = is_array( $decoded ) && ! empty( $decoded['message'] ) ? $decoded['message'] : 'HTTP ' . $code;
				$rate        = $this->rate_error_from( $code, $message, $resp_headers );
				$out[ $rel ] = $rate ?: new WP_Error( 'github_' . $code, 'Blob ' . $rel . ' -> ' . $code . ': ' . $message );
				continue;
			}
			$out[ $rel ] = isset( $decoded['sha'] ) ? $decoded['sha'] : new WP_Error( 'sed_blob', 'Risposta blob inattesa per ' . $rel );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Tree + commit + ref                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Crea il tree completo, il commit e aggiorna il branch.
	 *
	 * @param string      $branch        Branch di destinazione.
	 * @param string|null $head_sha      Commit attuale del branch.
	 * @param string|null $head_tree_sha Tree attuale (per lo skip "nessuna modifica").
	 * @param array       $entries       Entry complete del nuovo tree
	 *                                   ([['path','mode','type','sha'], ...]).
	 * @param string      $message       Messaggio di commit.
	 * @return array|WP_Error ['sha' => commit, 'skipped' => bool]
	 */
	public function commit_entries( $branch, $head_sha, $head_tree_sha, $entries, $message ) {
		if ( empty( $entries ) ) {
			return new WP_Error( 'sed_empty_tree', 'Nessun file da committare.' );
		}

		$new_tree = $this->request( 'POST', '/repos/' . $this->repo . '/git/trees', array( 'tree' => array_values( $entries ) ) );
		if ( is_wp_error( $new_tree ) ) {
			return $new_tree;
		}
		$new_tree_sha = isset( $new_tree['sha'] ) ? $new_tree['sha'] : '';

		// Nessuna modifica? Il repository e' gia' aggiornato: push saltato.
		if ( $head_tree_sha && $new_tree_sha === $head_tree_sha ) {
			return array(
				'sha'     => $head_sha,
				'skipped' => true,
			);
		}

		$commit_body = array(
			'message' => $message,
			'tree'    => $new_tree_sha,
		);
		if ( $head_sha ) {
			$commit_body['parents'] = array( $head_sha );
		}
		$commit = $this->request( 'POST', '/repos/' . $this->repo . '/git/commits', $commit_body );
		if ( is_wp_error( $commit ) ) {
			return $commit;
		}

		$update = $this->request( 'PATCH', '/repos/' . $this->repo . '/git/refs/heads/' . rawurlencode( $branch ), array(
			'sha'   => $commit['sha'],
			'force' => true,
		) );
		if ( is_wp_error( $update ) ) {
			return $update;
		}

		return array(
			'sha'     => $commit['sha'],
			'skipped' => false,
		);
	}
}
