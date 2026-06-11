<?php
/**
 * Deploy tramite protocollo git nativo (smart HTTP / git-receive-pack):
 * l'equivalente di "creare uno zip e caricarlo spacchettato".
 *
 * Tutti gli oggetti git (blob, tree, commit) vengono costruiti in PHP e
 * impacchettati in un PACKFILE compresso, caricato su GitHub con UN SOLO
 * upload HTTP — esattamente come fa `git push`, ma senza bisogno del binario
 * git sul server.
 *
 * Vantaggi rispetto all'API REST:
 *  - 1 upload invece di 1 chiamata per file;
 *  - il protocollo git NON e' soggetto al rate limit dell'API REST
 *    (5000 richieste/ora): si puo' caricare un sito intero senza consumarlo;
 *  - niente errori 502 sui tree giganti: il server riceve un pack standard.
 *
 * La deduplica resta attiva: nel pack finiscono solo i blob che il
 * repository non possiede gia' (confronto SHA locale vs tree remoto).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SED_GitPack {

	const ZERO_SHA = '0000000000000000000000000000000000000000';

	/** @var string */
	private $token;

	/** @var string owner/repo */
	private $repo;

	public function __construct( $token, $repo ) {
		$this->token = $token;
		$this->repo  = trim( $repo, '/' );
	}

	/* ------------------------------------------------------------------ */
	/* API pubblica                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Esegue il push completo di un set di file su un branch.
	 *
	 * @param string $branch        Branch di destinazione.
	 * @param array  $files         path relativo => ['abs' => path assoluto, 'sha' => sha blob locale].
	 * @param array  $known_shas    [sha => 1] blob gia' presenti nel repository (esclusi dal pack).
	 * @param array  $preserve      Entry di root da preservare: [['path','mode','sha'], ...].
	 * @param string $head_tree_sha SHA del tree attuale del branch (per lo skip), o null.
	 * @param string $message       Messaggio di commit.
	 * @param string $work_dir      Cartella dove costruire il pacchetto temporaneo.
	 * @return array|WP_Error ['sha' => commit, 'skipped' => bool, 'bytes' => int, 'objects' => int]
	 */
	public function push( $branch, $files, $known_shas, $preserve, $head_tree_sha, $message, $work_dir ) {
		// 1) Ref attuale dal server (info/refs): nessuna chiamata API REST.
		$refs = $this->advertise_refs();
		if ( is_wp_error( $refs ) ) {
			return $refs;
		}
		$old = isset( $refs[ 'refs/heads/' . $branch ] ) ? $refs[ 'refs/heads/' . $branch ] : self::ZERO_SHA;

		// 2) Tree ricorsivi costruiti in locale.
		$blob_entries = array();
		foreach ( $files as $rel => $info ) {
			$blob_entries[ $rel ] = array(
				'sha'  => $info['sha'],
				'mode' => '100644',
			);
		}
		foreach ( $preserve as $entry ) {
			if ( ! isset( $blob_entries[ $entry['path'] ] ) ) {
				$blob_entries[ $entry['path'] ] = array(
					'sha'  => $entry['sha'],
					'mode' => $entry['mode'],
				);
			}
		}
		$trees = $this->build_trees( $blob_entries );

		// Nessuna modifica? Stesso tree del commit attuale: push saltato.
		if ( $head_tree_sha && $trees['root'] === $head_tree_sha && self::ZERO_SHA !== $old ) {
			return array(
				'sha'     => $old,
				'skipped' => true,
				'bytes'   => 0,
				'objects' => 0,
			);
		}

		// 3) Oggetto commit.
		$parent = ( self::ZERO_SHA === $old ) ? null : $old;
		$commit = $this->build_commit( $trees['root'], $parent, $message );

		// 4) Packfile: commit + tutti i tree + solo i blob mancanti sul server.
		$body_path = trailingslashit( $work_dir ) . 'push-' . $branch . '-' . time() . '.pack';
		$built     = $this->build_request_body( $body_path, $old, $commit['sha'], $branch, $commit, $trees, $files, $known_shas );
		if ( is_wp_error( $built ) ) {
			@unlink( $body_path );
			return $built;
		}

		// 5) Upload unico (streaming, adatto anche a pack da centinaia di MB).
		$result = $this->send_pack( $body_path );
		@unlink( $body_path );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'sha'     => $commit['sha'],
			'skipped' => false,
			'bytes'   => $built['bytes'],
			'objects' => $built['objects'],
		);
	}

	/* ------------------------------------------------------------------ */
	/* Protocollo smart HTTP                                                 */
	/* ------------------------------------------------------------------ */

	private function auth_header() {
		return 'Basic ' . base64_encode( 'x-access-token:' . $this->token );
	}

	private function repo_url() {
		return 'https://github.com/' . $this->repo . '.git';
	}

	/**
	 * GET info/refs?service=git-receive-pack: ref attuali del repository.
	 *
	 * @return array|WP_Error [ref => sha]
	 */
	private function advertise_refs() {
		$response = wp_remote_get(
			$this->repo_url() . '/info/refs?service=git-receive-pack',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => $this->auth_header(),
					'User-Agent'    => 'SED-WordPress-Plugin/' . SED_VERSION,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'sed_pack_auth', 'Push git rifiutato (HTTP ' . $code . '): verifica che il token abbia permesso di scrittura sui contenuti del repository.' );
		}
		if ( $code >= 400 ) {
			return new WP_Error( 'sed_pack_refs', 'info/refs ha risposto HTTP ' . $code . ' per ' . $this->repo . '.' );
		}

		$refs = array();
		foreach ( $this->parse_pkt_lines( wp_remote_retrieve_body( $response ) ) as $line ) {
			if ( null === $line || '' === $line || '#' === $line[0] ) {
				continue;
			}
			$line  = rtrim( $line, "\n" );
			$parts = explode( "\0", $line, 2 ); // Le capability seguono il primo NUL.
			$bits  = explode( ' ', $parts[0], 2 );
			if ( 2 === count( $bits ) && 40 === strlen( $bits[0] ) && 'capabilities^{}' !== $bits[1] ) {
				$refs[ $bits[1] ] = $bits[0];
			}
		}
		return $refs;
	}

	/**
	 * Suddivide una risposta in pkt-line. Flush (0000) => elemento null.
	 */
	private function parse_pkt_lines( $body ) {
		$lines = array();
		$i     = 0;
		$n     = strlen( $body );
		while ( $i + 4 <= $n ) {
			$len = hexdec( substr( $body, $i, 4 ) );
			if ( $len < 4 ) {
				$lines[] = null;
				$i      += 4;
				continue;
			}
			$lines[] = substr( $body, $i + 4, $len - 4 );
			$i      += $len;
		}
		return $lines;
	}

	private function pkt_line( $data ) {
		return sprintf( '%04x', strlen( $data ) + 4 ) . $data;
	}

	/**
	 * POST git-receive-pack con streaming del corpo da file (curl), cosi' i
	 * pack grandi non passano dalla memoria PHP.
	 *
	 * @return true|WP_Error
	 */
	private function send_pack( $body_path ) {
		$size = filesize( $body_path );
		$url  = $this->repo_url() . '/git-receive-pack';

		if ( function_exists( 'curl_init' ) ) {
			$fh = fopen( $body_path, 'rb' );
			if ( ! $fh ) {
				return new WP_Error( 'sed_pack_read', 'Impossibile aprire il pacchetto da inviare.' );
			}
			$ch = curl_init( $url );
			curl_setopt_array( $ch, array(
				CURLOPT_CUSTOMREQUEST  => 'POST',
				CURLOPT_UPLOAD         => true,
				CURLOPT_INFILE         => $fh,
				CURLOPT_INFILESIZE     => $size,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => 30,
				CURLOPT_TIMEOUT        => 0,          // Upload potenzialmente lungo.
				CURLOPT_LOW_SPEED_LIMIT => 1,
				CURLOPT_LOW_SPEED_TIME  => 180,        // Abort se fermo per 3 minuti.
				CURLOPT_HTTPHEADER     => array(
					'Authorization: ' . $this->auth_header(),
					'User-Agent: SED-WordPress-Plugin/' . SED_VERSION,
					'Content-Type: application/x-git-receive-pack-request',
					'Accept: application/x-git-receive-pack-result',
					'Expect:',
				),
			) );
			$body = curl_exec( $ch );
			$err  = curl_error( $ch );
			$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
			curl_close( $ch );
			fclose( $fh );

			if ( false === $body ) {
				return new WP_Error( 'sed_pack_send', 'Upload del pacchetto fallito: ' . $err );
			}
		} else {
			// Fallback senza curl: il corpo passa dalla memoria.
			if ( $size > 100 * 1024 * 1024 ) {
				return new WP_Error( 'sed_pack_size', 'Pacchetto troppo grande per l\'invio senza cURL (' . size_format( $size ) . ').' );
			}
			$response = wp_remote_post( $url, array(
				'timeout' => 600,
				'body'    => file_get_contents( $body_path ),
				'headers' => array(
					'Authorization' => $this->auth_header(),
					'User-Agent'    => 'SED-WordPress-Plugin/' . SED_VERSION,
					'Content-Type'  => 'application/x-git-receive-pack-request',
					'Accept'        => 'application/x-git-receive-pack-result',
				),
			) );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );
		}

		if ( $code >= 400 ) {
			return new WP_Error( 'sed_pack_http', 'git-receive-pack ha risposto HTTP ' . $code . '.' );
		}

		// Report-status: ci aspettiamo "unpack ok" e "ok refs/heads/<branch>".
		$unpack_ok = false;
		$ref_error = '';
		foreach ( $this->parse_pkt_lines( (string) $body ) as $line ) {
			if ( null === $line ) {
				continue;
			}
			$line = rtrim( $line, "\n" );
			if ( 'unpack ok' === $line ) {
				$unpack_ok = true;
			} elseif ( 0 === strpos( $line, 'ng ' ) ) {
				$ref_error = $line;
			} elseif ( 0 === strpos( $line, 'unpack ' ) ) {
				$ref_error = $line;
			}
		}
		if ( ! $unpack_ok || $ref_error ) {
			return new WP_Error( 'sed_pack_report', 'Push rifiutato dal server: ' . ( $ref_error ?: 'unpack non confermato' ) . '.' );
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Costruzione oggetti git                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Costruisce ricorsivamente gli oggetti tree (uno per cartella).
	 *
	 * @param array $blob_entries path => ['sha','mode'].
	 * @return array ['root' => sha del tree radice, 'objects' => [sha => contenuto raw]]
	 */
	private function build_trees( $blob_entries ) {
		// children[dir] = [nome => ['type','sha','mode']]
		$children = array( '' => array() );

		foreach ( $blob_entries as $path => $info ) {
			$parts = explode( '/', $path );
			$name  = array_pop( $parts );
			$dir   = implode( '/', $parts );

			// Registra tutte le cartelle antenate.
			$walk = '';
			foreach ( $parts as $segment ) {
				$parent = $walk;
				$walk   = ( '' === $walk ) ? $segment : $walk . '/' . $segment;
				if ( ! isset( $children[ $walk ] ) ) {
					$children[ $walk ] = array();
				}
				$children[ $parent ][ $segment ] = array( 'type' => 'tree' ); // Lo SHA arriva dopo.
			}
			$children[ $dir ][ $name ] = array(
				'type' => 'blob',
				'sha'  => $info['sha'],
				'mode' => $info['mode'],
			);
		}

		// Dal piu' profondo alla radice.
		$dirs = array_keys( $children );
		usort( $dirs, function ( $a, $b ) {
			return substr_count( $b, '/' ) + ( '' === $b ? -1 : 1 ) <=> substr_count( $a, '/' ) + ( '' === $a ? -1 : 1 );
		} );

		$objects   = array();
		$tree_shas = array();

		foreach ( $dirs as $dir ) {
			$entries = $children[ $dir ];

			// Ordinamento git: le directory si confrontano con '/' appeso.
			$names = array_keys( $entries );
			usort( $names, function ( $a, $b ) use ( $entries ) {
				$ka = $a . ( 'tree' === $entries[ $a ]['type'] ? '/' : '' );
				$kb = $b . ( 'tree' === $entries[ $b ]['type'] ? '/' : '' );
				return strcmp( $ka, $kb );
			} );

			$raw = '';
			foreach ( $names as $name ) {
				$entry = $entries[ $name ];
				if ( 'tree' === $entry['type'] ) {
					$sha  = $tree_shas[ ( '' === $dir ? '' : $dir . '/' ) . $name ];
					$mode = '40000';
				} else {
					$sha  = $entry['sha'];
					$mode = ltrim( $entry['mode'], '0' ) ?: '100644';
				}
				$raw .= $mode . ' ' . $name . "\0" . hex2bin( $sha );
			}

			$sha               = sha1( 'tree ' . strlen( $raw ) . "\0" . $raw );
			$tree_shas[ $dir ] = $sha;
			$objects[ $sha ]   = $raw;
		}

		return array(
			'root'    => $tree_shas[''],
			'objects' => $objects,
		);
	}

	/**
	 * Costruisce l'oggetto commit.
	 *
	 * @return array ['sha' => string, 'raw' => string]
	 */
	private function build_commit( $tree_sha, $parent_sha, $message ) {
		$host  = SED_Settings::site_host();
		$ident = 'Static Export & Deploy <plugin@' . ( $host ?: 'wordpress.local' ) . '> ' . time() . ' +0000';

		$raw = 'tree ' . $tree_sha . "\n";
		if ( $parent_sha ) {
			$raw .= 'parent ' . $parent_sha . "\n";
		}
		$raw .= 'author ' . $ident . "\n";
		$raw .= 'committer ' . $ident . "\n";
		$raw .= "\n" . $message . "\n";

		return array(
			'sha' => sha1( 'commit ' . strlen( $raw ) . "\0" . $raw ),
			'raw' => $raw,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Packfile                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Header oggetto nel pack: tipo + dimensione in varint little-endian.
	 * Tipi: commit=1, tree=2, blob=3.
	 */
	private function pack_object_header( $type, $size ) {
		$byte  = ( $type << 4 ) | ( $size & 0x0f );
		$size >>= 4;
		$out   = '';
		while ( $size > 0 ) {
			$out  .= chr( $byte | 0x80 );
			$byte  = $size & 0x7f;
			$size >>= 7;
		}
		return $out . chr( $byte );
	}

	/**
	 * Scrive il corpo HTTP completo: pkt-line del comando di update, flush e
	 * packfile (header, oggetti compressi zlib, checksum SHA-1 finale).
	 *
	 * @return array|WP_Error ['bytes' => int, 'objects' => int]
	 */
	private function build_request_body( $body_path, $old, $new, $branch, $commit, $trees, $files, $known_shas ) {
		$fh = @fopen( $body_path, 'wb' );
		if ( ! $fh ) {
			return new WP_Error( 'sed_pack_tmp', 'Impossibile creare il pacchetto temporaneo (' . $body_path . ').' );
		}

		// --- Comando di update del ref ---
		$command = $old . ' ' . $new . ' refs/heads/' . $branch . "\0" . 'report-status agent=sed/' . SED_VERSION;
		fwrite( $fh, $this->pkt_line( $command ) );
		fwrite( $fh, '0000' );

		// --- Blob da includere: solo quelli che il server non ha gia' ---
		$blobs = array();
		$seen  = array();
		foreach ( $files as $rel => $info ) {
			$sha = $info['sha'];
			if ( isset( $known_shas[ $sha ] ) || isset( $seen[ $sha ] ) ) {
				continue; // Gia' sul server, o duplicato locale: una sola copia.
			}
			$seen[ $sha ]  = 1;
			$blobs[ $rel ] = $info;
		}

		$count = 1 + count( $trees['objects'] ) + count( $blobs );

		// --- Header del pack (il checksum copre da qui in poi) ---
		$ctx = hash_init( 'sha1' );
		$write = function ( $data ) use ( $fh, $ctx ) {
			hash_update( $ctx, $data );
			fwrite( $fh, $data );
		};

		$write( 'PACK' . pack( 'N', 2 ) . pack( 'N', $count ) );

		// --- Commit (tipo 1) ---
		$write( $this->pack_object_header( 1, strlen( $commit['raw'] ) ) );
		$write( gzcompress( $commit['raw'], 6 ) );

		// --- Tree (tipo 2) ---
		foreach ( $trees['objects'] as $raw ) {
			$write( $this->pack_object_header( 2, strlen( $raw ) ) );
			$write( gzcompress( $raw, 6 ) );
		}

		// --- Blob (tipo 3), uno alla volta per non saturare la memoria ---
		foreach ( $blobs as $rel => $info ) {
			$content = @file_get_contents( $info['abs'] );
			if ( false === $content ) {
				fclose( $fh );
				return new WP_Error( 'sed_pack_read', 'Impossibile leggere ' . $rel . ' durante la costruzione del pacchetto.' );
			}
			$write( $this->pack_object_header( 3, strlen( $content ) ) );
			$write( gzcompress( $content, 6 ) );
			unset( $content );
		}

		// --- Checksum finale del pack ---
		fwrite( $fh, hash_final( $ctx, true ) );
		fclose( $fh );

		return array(
			'bytes'   => filesize( $body_path ),
			'objects' => $count,
		);
	}
}
