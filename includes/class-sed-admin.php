<?php
/**
 * Interfaccia di amministrazione: dashboard (avvio export, barra di
 * avanzamento, log, report) e pagina impostazioni.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SED_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_save_settings' ) );

		add_action( 'wp_ajax_sed_start', array( __CLASS__, 'ajax_start' ) );
		add_action( 'wp_ajax_sed_cancel', array( __CLASS__, 'ajax_cancel' ) );
		add_action( 'wp_ajax_sed_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'admin_post_sed_download_report', array( __CLASS__, 'download_report' ) );
		add_action( 'admin_post_sed_download_zip', array( __CLASS__, 'download_zip' ) );
		add_action( 'admin_post_sed_artifact_download', array( __CLASS__, 'artifact_download' ) );
		add_action( 'admin_post_sed_artifact_delete', array( __CLASS__, 'artifact_delete' ) );
	}

	/** @var string[] Hook delle pagine registrate (per l'enqueue mirato). */
	private static $page_hooks = array();

	public static function menu() {
		self::$page_hooks[] = add_menu_page(
			'Static Export & Deploy',
			'Static Export',
			'manage_options',
			'sed-dashboard',
			array( __CLASS__, 'render_dashboard_page' ),
			'dashicons-migrate',
			81
		);
		// Sottovoci nella barra laterale: la prima rinomina la voce
		// auto-generata del genitore in "Dashboard".
		add_submenu_page(
			'sed-dashboard',
			'Dashboard — Static Export & Deploy',
			'Dashboard',
			'manage_options',
			'sed-dashboard',
			array( __CLASS__, 'render_dashboard_page' )
		);
		self::$page_hooks[] = add_submenu_page(
			'sed-dashboard',
			'Artefatti — Static Export & Deploy',
			'Artefatti',
			'manage_options',
			'sed-artifacts',
			array( __CLASS__, 'render_artifacts_page' )
		);
		self::$page_hooks[] = add_submenu_page(
			'sed-dashboard',
			'Impostazioni — Static Export & Deploy',
			'Impostazioni',
			'manage_options',
			'sed-settings',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function assets( $hook ) {
		if ( ! in_array( $hook, self::$page_hooks, true ) ) {
			return;
		}
		wp_enqueue_style( 'sed-admin', SED_PLUGIN_URL . 'assets/admin.css', array(), SED_VERSION );
		wp_enqueue_script( 'sed-admin', SED_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), SED_VERSION, true );
		wp_localize_script( 'sed-admin', 'SED', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'sed_admin' ),
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Salvataggio impostazioni                                              */
	/* ------------------------------------------------------------------ */

	public static function maybe_save_settings() {
		if ( empty( $_POST['sed_save_settings'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'sed_settings' );
		SED_Settings::save_from_post( wp_unslash( $_POST ) );
		add_settings_error( 'sed', 'sed_saved', 'Impostazioni salvate.', 'success' );
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                                  */
	/* ------------------------------------------------------------------ */

	private static function check_ajax() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'sed_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Permessi insufficienti.' ), 403 );
		}
	}

	public static function ajax_start() {
		self::check_ajax();
		$result = SED_Queue::start_job( 'manual' );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( self::status_payload() );
	}

	public static function ajax_cancel() {
		self::check_ajax();
		SED_Queue::cancel_job();
		wp_send_json_success( self::status_payload() );
	}

	public static function ajax_status() {
		self::check_ajax();
		// Spinta di sicurezza: se il job sembra fermo da >90s, rilancia un tick.
		$job = SED_Queue::get_job();
		if ( $job && 'running' === $job['status'] && ( time() - (int) $job['updated'] ) > 90 ) {
			SED_Queue::dispatch();
		}
		wp_send_json_success( self::status_payload() );
	}

	private static function status_payload() {
		$job = SED_Queue::get_job();
		$out = array(
			'job' => null,
			'log' => array(),
		);
		if ( $job ) {
			$out['job'] = array(
				'id'       => $job['id'],
				'status'   => $job['status'],
				'phase'    => $job['phase'],
				'error'    => $job['error'],
				'started'  => $job['started'],
				'progress' => $job['progress'],
				'report'   => file_exists( trailingslashit( $job['dir'] ) . 'report.txt' ),
				'zip_raw'  => file_exists( trailingslashit( $job['dir'] ) . 'raw.zip' ),
				'zip_main' => file_exists( trailingslashit( $job['dir'] ) . 'main.zip' ),
			);
			$out['log'] = SED_Logger::tail( 80 );
		}
		return $out;
	}

	public static function download_report() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permessi insufficienti.' );
		}
		check_admin_referer( 'sed_report' );
		$job = SED_Queue::get_job();
		if ( ! $job ) {
			wp_die( 'Nessun job disponibile.' );
		}
		$file = trailingslashit( $job['dir'] ) . 'report.txt';
		if ( ! file_exists( $file ) ) {
			wp_die( 'Report non ancora generato.' );
		}
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="sed-report-' . $job['id'] . '.txt"' );
		readfile( $file );
		exit;
	}

	public static function download_zip() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permessi insufficienti.' );
		}
		check_admin_referer( 'sed_zip' );
		$which = isset( $_GET['which'] ) ? sanitize_key( $_GET['which'] ) : '';
		if ( ! in_array( $which, array( 'raw', 'main' ), true ) ) {
			wp_die( 'Parametro non valido.' );
		}
		$job = SED_Queue::get_job();
		if ( ! $job ) {
			wp_die( 'Nessun job disponibile.' );
		}
		$file = trailingslashit( $job['dir'] ) . $which . '.zip';
		if ( ! file_exists( $file ) ) {
			wp_die( 'ZIP non ancora generato.' );
		}
		// Streaming senza buffer (gli ZIP possono essere grandi).
		@set_time_limit( 0 );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Content-Disposition: attachment; filename="' . SED_Settings::site_host() . '-' . $which . '-' . $job['id'] . '.zip"' );
		readfile( $file );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                             */
	/* ------------------------------------------------------------------ */

	/* ------------------------------------------------------------------ */
	/* Artefatti                                                             */
	/* ------------------------------------------------------------------ */

	public static function artifact_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permessi insufficienti.' );
		}
		check_admin_referer( 'sed_artifact' );
		$id   = isset( $_GET['job'] ) ? sanitize_text_field( wp_unslash( $_GET['job'] ) ) : '';
		$file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';

		$path = SED_Queue::artifact_path( $id, $file );
		if ( is_wp_error( $path ) ) {
			wp_die( esc_html( $path->get_error_message() ) );
		}

		@set_time_limit( 0 );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		$is_zip = '.zip' === substr( $file, -4 );
		header( 'Content-Type: ' . ( $is_zip ? 'application/zip' : 'text/plain; charset=utf-8' ) );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: attachment; filename="' . SED_Settings::site_host() . '-' . $id . '-' . $file . '"' );
		readfile( $path );
		exit;
	}

	public static function artifact_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permessi insufficienti.' );
		}
		check_admin_referer( 'sed_artifact' );

		$result = true;
		if ( ! empty( $_GET['all'] ) ) {
			foreach ( SED_Queue::list_jobs() as $job ) {
				if ( 'running' !== $job['status'] ) {
					SED_Queue::delete_job_dir( $job['id'] );
				}
			}
			$msg = 'deleted_all';
		} else {
			$id     = isset( $_GET['job'] ) ? sanitize_text_field( wp_unslash( $_GET['job'] ) ) : '';
			$result = SED_Queue::delete_job_dir( $id );
			$msg    = is_wp_error( $result ) ? 'error' : 'deleted';
		}

		$url = admin_url( 'admin.php?page=sed-artifacts&sed_msg=' . $msg );
		if ( is_wp_error( $result ) ) {
			$url = add_query_arg( 'sed_err', rawurlencode( $result->get_error_message() ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	public static function render_artifacts_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$jobs       = SED_Queue::list_jobs();
		$msg        = isset( $_GET['sed_msg'] ) ? sanitize_key( $_GET['sed_msg'] ) : '';
		$err        = isset( $_GET['sed_err'] ) ? sanitize_text_field( wp_unslash( $_GET['sed_err'] ) ) : '';
		$total      = 0;
		$deletable  = 0;
		foreach ( $jobs as $job ) {
			$total += $job['total_size'];
			if ( 'running' !== $job['status'] ) {
				$deletable++;
			}
		}
		$labels = array(
			'raw.zip'    => 'ZIP raw',
			'main.zip'   => 'ZIP ottimizzato',
			'report.txt' => 'Report SEO',
			'log.txt'    => 'Log',
		);
		$status_badges = array(
			'running'   => array( 'running', 'In esecuzione' ),
			'done'      => array( 'done', 'Completato' ),
			'error'     => array( 'error', 'Errore' ),
			'cancelled' => array( 'cancelled', 'Annullato' ),
			'archived'  => array( '', 'Archiviato' ),
		);
		?>
		<div class="wrap sed-wrap">
			<h1>Artefatti</h1>
			<hr class="wp-header-end" />

			<?php if ( 'deleted' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p>Export eliminato.</p></div>
			<?php elseif ( 'deleted_all' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p>Tutti gli export archiviati sono stati eliminati.</p></div>
			<?php elseif ( 'error' === $msg ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $err ?: 'Operazione non riuscita.' ); ?></p></div>
			<?php endif; ?>

			<p class="sed-muted">
				Gli export occupano <strong><?php echo esc_html( size_format( $total ) ); ?></strong> in
				<code>wp-content/uploads/sed-export/</code>. All'avvio di un nuovo export i piu' vecchi vengono
				eliminati automaticamente (ne vengono conservati <?php echo (int) SED_Settings::get( 'keep_jobs' ); ?>:
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sed-settings' ) ); ?>">modifica</a>).
			</p>

			<?php if ( empty( $jobs ) ) : ?>
				<div class="sed-empty">
					<p>Nessun export presente. <a href="<?php echo esc_url( admin_url( 'admin.php?page=sed-dashboard' ) ); ?>">Avviane uno dalla dashboard</a>.</p>
				</div>
			<?php else : ?>
				<table class="widefat striped sed-artifacts">
					<thead>
						<tr>
							<th>Export</th>
							<th>Stato</th>
							<th>Dimensione</th>
							<th>Artefatti</th>
							<th class="sed-col-actions">Azioni</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $jobs as $job ) : ?>
						<?php list( $badge_class, $badge_text ) = $status_badges[ $job['status'] ] ?? $status_badges['archived']; ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $job['time'] ? wp_date( 'd/m/Y H:i', $job['time'] ) : $job['id'] ); ?></strong><br />
								<span class="sed-muted">job-<?php echo esc_html( $job['id'] ); ?></span>
							</td>
							<td><span class="sed-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_text ); ?></span></td>
							<td><?php echo esc_html( size_format( $job['total_size'] ) ); ?></td>
							<td>
								<?php if ( empty( $job['files'] ) ) : ?>
									<span class="sed-muted">nessun file scaricabile<?php echo 'running' === $job['status'] ? ' (in lavorazione)' : ''; ?></span>
								<?php else : ?>
									<?php foreach ( $job['files'] as $name => $size ) : ?>
										<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sed_artifact_download&job=' . $job['id'] . '&file=' . $name ), 'sed_artifact' ) ); ?>">
											<?php echo esc_html( $labels[ $name ] . ' (' . size_format( $size ) . ')' ); ?>
										</a>
									<?php endforeach; ?>
								<?php endif; ?>
							</td>
							<td class="sed-col-actions">
								<?php if ( 'running' === $job['status'] ) : ?>
									<span class="sed-muted">in esecuzione</span>
								<?php else : ?>
									<a class="button-link-delete" onclick="return confirm('Eliminare definitivamente questo export?');"
										href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sed_artifact_delete&job=' . $job['id'] ), 'sed_artifact' ) ); ?>">Elimina</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $deletable > 1 ) : ?>
					<p class="sed-bulk">
						<a class="button" onclick="return confirm('Eliminare TUTTI gli export non in esecuzione? L\'operazione e\' definitiva.');"
							href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sed_artifact_delete&all=1' ), 'sed_artifact' ) ); ?>">Elimina tutti gli archiviati</a>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_dashboard_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap sed-wrap">
			<h1>Static Export &amp; Deploy</h1>
			<hr class="wp-header-end" />
			<?php self::render_dashboard(); ?>
		</div>
		<?php
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap sed-wrap">
			<h1>Impostazioni</h1>
			<hr class="wp-header-end" />
			<?php settings_errors( 'sed' ); ?>
			<?php self::render_settings(); ?>
		</div>
		<?php
	}

	private static function render_dashboard() {
		$job        = SED_Queue::get_job();
		$repo       = SED_Settings::resolve_repo();
		$settings   = SED_Settings::all();
		$prod_parts = SED_Settings::prod_url_parts();
		$langs      = SED_Settings::language_slugs();
		$ready      = SED_Settings::has_token() && false !== strpos( $repo, '/' );
		$settings_url = admin_url( 'admin.php?page=sed-settings' );
		$running    = $job && 'running' === $job['status'];
		?>

		<?php if ( ! $ready ) : ?>
			<div class="notice notice-warning inline sed-notice">
				<p>Per iniziare servono il <strong>token GitHub</strong> e il <strong>proprietario</strong> del repository: <a href="<?php echo esc_url( $settings_url ); ?>">configurali nelle impostazioni</a>.</p>
			</div>
		<?php endif; ?>

		<div class="sed-columns">

			<div class="postbox">
				<div class="postbox-header"><h2 class="hndle">Stato</h2></div>
				<div class="inside" id="sed-status-card" data-status="<?php echo esc_attr( $job['status'] ?? '' ); ?>">
					<?php
					$status_labels = array(
						'running'   => 'In esecuzione',
						'done'      => 'Completato',
						'error'     => 'Errore',
						'cancelled' => 'Annullato',
					);
					$status_key   = $job['status'] ?? '';
					$status_text  = $status_labels[ $status_key ] ?? '';
					$phase_label  = $job ? (string) $job['progress']['label'] : 'Nessun export eseguito finora.';
					if ( $phase_label === $status_text ) {
						$phase_label = ''; // Job salvati da versioni precedenti.
					}
					$pct        = 0;
					if ( $job && ! empty( $job['progress']['total'] ) ) {
						$pct = min( 100, (int) round( 100 * $job['progress']['current'] / max( 1, $job['progress']['total'] ) ) );
					}
					if ( 'done' === $status_key ) {
						$pct = 100;
					}
					?>
					<p class="sed-status-line">
						<span id="sed-status-badge" class="sed-badge <?php echo esc_attr( $status_key ); ?>"><?php echo esc_html( $status_labels[ $status_key ] ?? 'Inattivo' ); ?></span>
						<span id="sed-status-label"><?php echo esc_html( $phase_label ); ?></span>
					</p>

					<div class="sed-progress"><div class="sed-progress-bar <?php echo 'error' === $status_key ? 'sed-bar-error' : ''; ?>" id="sed-progress-bar" style="width:<?php echo (int) $pct; ?>%"></div></div>

					<div id="sed-error" class="notice notice-error inline" style="display:none"><p></p></div>

					<p class="sed-actions">
						<button class="button button-primary" id="sed-start" data-ready="<?php echo $ready ? '1' : '0'; ?>" <?php disabled( ! $ready || $running ); ?>>Avvia export &amp; deploy</button>
						<button class="button button-link-delete" id="sed-cancel" <?php echo $running ? '' : 'style="display:none"'; ?>>Annulla</button>
					</p>

					<p id="sed-downloads">
						<a class="button" id="sed-report-link" style="display:none" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sed_download_report' ), 'sed_report' ) ); ?>">Report SEO</a>
						<a class="button" id="sed-zip-raw" style="display:none" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sed_download_zip&which=raw' ), 'sed_zip' ) ); ?>">ZIP raw</a>
						<a class="button" id="sed-zip-main" style="display:none" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sed_download_zip&which=main' ), 'sed_zip' ) ); ?>">ZIP ottimizzato</a>
					</p>

					<p class="description">L'elaborazione avviene in background: puoi chiudere questa pagina.</p>
				</div>
			</div>

			<div class="postbox">
				<div class="postbox-header"><h2 class="hndle">Configurazione attuale</h2></div>
				<div class="inside">
					<table class="sed-summary">
						<tr>
							<th>Repository</th>
							<td><?php echo $repo ? '<code>' . esc_html( $repo ) . '</code>' : '<em>non configurato</em>'; ?></td>
						</tr>
						<tr>
							<th>Branch</th>
							<td><code><?php echo esc_html( $settings['branch_main'] ); ?></code><?php echo $settings['deploy_raw'] ? ' + <code>' . esc_html( $settings['branch_raw'] ) . '</code>' : ''; ?></td>
						</tr>
						<tr>
							<th>Dominio</th>
							<td>
								<?php if ( $prod_parts ) : ?>
									<code><?php echo esc_html( SED_Settings::site_host() ); ?></code> &rarr; <code><?php echo esc_html( $prod_parts['scheme'] . '://' . $prod_parts['host'] ); ?></code>
								<?php else : ?>
									<code><?php echo esc_html( $settings['sub_staging'] ); ?>.*</code> &rarr; <code><?php echo esc_html( $settings['sub_prod'] ); ?>.*</code>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th>Lingue</th>
							<td><?php echo $langs ? '<code>' . implode( '</code> <code>', array_map( 'esc_html', $langs ) ) . '</code>' : 'rilevamento automatico'; ?></td>
						</tr>
						<tr>
							<th>JavaScript</th>
							<td><?php echo $settings['keep_js'] ? 'mantenuto' : 'rimosso (salvo JSON-LD, GA4/AdSense, ricerca Fuse)'; ?></td>
						</tr>
						<tr>
							<th>Deploy</th>
							<td><?php echo 'pack' === $settings['deploy_engine'] ? 'pacchetto git (1 upload)' : 'API REST'; ?><?php echo $settings['make_zips'] ? ', con ZIP scaricabili' : ''; ?></td>
						</tr>
						<tr>
							<th>Token</th>
							<td><?php echo SED_Settings::has_token() ? '<span class="sed-ok">configurato</span>' : '<span class="sed-err">mancante</span>'; ?></td>
						</tr>
						<tr>
							<th>Pianificazione</th>
							<td><?php echo 'manual' === $settings['schedule'] ? 'manuale' : ( 'daily' === $settings['schedule'] ? 'giornaliera' : 'settimanale' ); ?></td>
						</tr>
					</table>
					<p><a class="button" href="<?php echo esc_url( $settings_url ); ?>">Modifica impostazioni</a></p>
				</div>
			</div>

		</div>

		<details id="sed-log-wrap" class="sed-log-wrap" <?php echo $running ? 'open' : ''; ?>>
			<summary>Log dell'ultimo export</summary>
			<pre id="sed-log" class="sed-log">In attesa...</pre>
		</details>
		<?php
	}

	private static function render_settings() {
		$s = SED_Settings::all();
		?>
		<form method="post" class="sed-settings">
			<?php wp_nonce_field( 'sed_settings' ); ?>
			<input type="hidden" name="sed_save_settings" value="1" />

			<h2 class="title">GitHub</h2>
			<p>Dove pubblicare il sito statico. Serve un token con permesso di scrittura sui contenuti del repository.</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="github_token">Token di accesso</label></th>
					<td>
						<input type="password" id="github_token" name="github_token" value="" class="regular-text" autocomplete="new-password"
							placeholder="<?php echo SED_Settings::has_token() ? 'configurato — lascia vuoto per non modificarlo' : 'ghp_… oppure github_pat_…'; ?>" />
						<?php if ( SED_Settings::has_token() ) : ?>
							<label class="sed-inline-check"><input type="checkbox" name="github_token_clear" value="1" /> Rimuovi</label>
						<?php endif; ?>
						<p class="description">Permesso richiesto: <code>repo</code> (classic) o <code>Contents: read &amp; write</code> (fine-grained). Salvato cifrato.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="github_owner">Proprietario</label></th>
					<td>
						<input type="text" id="github_owner" name="github_owner" value="<?php echo esc_attr( $s['github_owner'] ); ?>" class="regular-text" placeholder="utente o organizzazione" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="github_repo">Repository</label></th>
					<td>
						<input type="text" id="github_repo" name="github_repo" value="<?php echo esc_attr( $s['github_repo'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( SED_Settings::auto_repo_name() ); ?>" />
						<p class="description">Vuoto = derivato dal dominio (<code><?php echo esc_html( SED_Settings::auto_repo_name() ); ?></code>). Accetta anche <code>owner/repo</code>.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Branch</th>
					<td>
						<fieldset>
							<span class="sed-field">
								<label class="sed-field-label" for="branch_main">Sito ottimizzato</label>
								<input type="text" id="branch_main" name="branch_main" value="<?php echo esc_attr( $s['branch_main'] ); ?>" class="sed-input-s" />
							</span>
							<span class="sed-field">
								<label class="sed-field-label" for="branch_raw">Export originale</label>
								<input type="text" id="branch_raw" name="branch_raw" value="<?php echo esc_attr( $s['branch_raw'] ); ?>" class="sed-input-s" />
							</span>
						</fieldset>
						<p class="sed-check-row"><label><input type="checkbox" name="deploy_raw" value="1" <?php checked( $s['deploy_raw'] ); ?> /> Carica anche l'export originale (non ottimizzato)</label></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="preserve_files">File da preservare</label></th>
					<td>
						<textarea id="preserve_files" name="preserve_files" rows="3" class="regular-text code"><?php echo esc_textarea( $s['preserve_files'] ); ?></textarea>
						<p class="description">File di root del repository che il deploy non sovrascrive, uno per riga.</p>
					</td>
				</tr>
			</table>

			<h2 class="title">Dominio</h2>
			<p>Come trasformare gli URL del sito (host attuale: <code><?php echo esc_html( SED_Settings::site_host() ); ?></code>, rilevato automaticamente).</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="prod_url">URL di produzione</label></th>
					<td>
						<input type="url" id="prod_url" name="prod_url" value="<?php echo esc_attr( $s['prod_url'] ); ?>" class="regular-text" placeholder="https://www.<?php echo esc_attr( SED_Settings::registrable_domain() ); ?>/" />
						<p class="description">Indirizzo completo del sito pubblico: ogni riferimento all'host attuale viene riscritto qui, <strong>protocollo incluso</strong> (link, canonical, hreflang, srcset, CSS, JSON-LD, sitemap, robots).</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Sottodomini <span class="sed-muted">(fallback)</span></th>
					<td>
						<fieldset>
							<span class="sed-field">
								<label class="sed-field-label" for="sub_staging">Attuale</label>
								<input type="text" id="sub_staging" name="sub_staging" value="<?php echo esc_attr( $s['sub_staging'] ); ?>" class="sed-input-s" />
							</span>
							<span class="sed-arrow">&rarr;</span>
							<span class="sed-field">
								<label class="sed-field-label" for="sub_prod">Pubblico</label>
								<input type="text" id="sub_prod" name="sub_prod" value="<?php echo esc_attr( $s['sub_prod'] ); ?>" class="sed-input-s" />
							</span>
						</fieldset>
						<p class="description">Usati solo se l'URL di produzione e' vuoto.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">HTTPS</th>
					<td>
						<label><input type="checkbox" name="force_https" value="1" <?php checked( $s['force_https'] ); ?> /> Forza <code>https://</code> sugli URL interni</label>
						<p class="description">Evita il <em>mixed content</em>; aggiunge anche <code>upgrade-insecure-requests</code> nel <code>&lt;head&gt;</code>.</p>
					</td>
				</tr>
			</table>

			<h2 class="title">Ottimizzazione</h2>
			<p>Cosa succede ai contenuti tra l'export e la pubblicazione sul branch ottimizzato.</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">JavaScript</th>
					<td>
						<label><input type="checkbox" name="keep_js" value="1" <?php checked( $s['keep_js'] ); ?> /> Mantieni il JavaScript</label>
						<p class="description">Se disattivato (consigliato) il JS viene rimosso, salvo JSON-LD, GA4/AdSense e gli script qui sotto.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="js_allowlist">Script da preservare</label></th>
					<td>
						<textarea id="js_allowlist" name="js_allowlist" rows="4" class="regular-text code"><?php echo esc_textarea( $s['js_allowlist'] ); ?></textarea>
						<p class="description">Un pattern per riga, confrontato con <code>src</code>, <code>id</code> e contenuto degli script. I default preservano la ricerca Fuse; gli indici <code>static-search/*.json</code> sono inclusi automaticamente.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="webp_quality">Qualita' WebP</label></th>
					<td>
						<input type="number" id="webp_quality" name="webp_quality" value="<?php echo esc_attr( $s['webp_quality'] ); ?>" min="1" max="100" class="small-text" /> <span class="sed-muted">1–100, predefinito 80</span>
						<p class="description">PNG e JPG vengono convertiti in WebP e tutti i riferimenti aggiornati.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ga_id">Google Analytics</label></th>
					<td>
						<input type="text" id="ga_id" name="ga_id" value="<?php echo esc_attr( $s['ga_id'] ); ?>" class="regular-text" placeholder="G-XXXXXXXXXX" />
						<p class="description">Il tag GA4 viene iniettato in ogni pagina. Vuoto = disattivato.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="adsense_id">Google AdSense</label></th>
					<td>
						<input type="text" id="adsense_id" name="adsense_id" value="<?php echo esc_attr( $s['adsense_id'] ); ?>" class="regular-text" placeholder="ca-pub-XXXXXXXXXXXXXXXX" />
						<p><label><input type="checkbox" name="ads_txt" value="1" <?php checked( $s['ads_txt'] ); ?> /> Genera <code>ads.txt</code> se il sito non lo espone gia'</label></p>
					</td>
				</tr>
			</table>

			<h2 class="title">Crawler e lingue</h2>
			<p>Cosa includere nell'export. Sitemap (anche annidate), pagina 404 e lingue WPLingua sono gestite automaticamente.</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="lang_slugs">Lingue</label></th>
					<td>
						<input type="text" id="lang_slugs" name="lang_slugs" value="<?php echo esc_attr( $s['lang_slugs'] ); ?>" class="regular-text" placeholder="auto: <?php echo esc_attr( implode( ', ', SED_Settings::detect_language_slugs() ) ?: 'nessuna rilevata' ); ?>" />
						<p class="description">Slug separati da virgola (es. <code>en, fr</code>). Vuoto = rilevamento automatico: plugin multilingua (WPLingua, Polylang, WPML, TranslatePress) e, in ogni caso, tag <code>hreflang</code> e percorsi <code>/xx/</code> della home.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="extra_urls">URL aggiuntivi</label></th>
					<td>
						<textarea id="extra_urls" name="extra_urls" rows="3" class="large-text code" placeholder="/pagina-non-linkata/"><?php echo esc_textarea( $s['extra_urls'] ); ?></textarea>
						<p class="description">Pagine non raggiungibili dai link, una per riga.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="exclude_paths">Percorsi esclusi</label></th>
					<td>
						<textarea id="exclude_paths" name="exclude_paths" rows="3" class="large-text code" placeholder="/area-privata"><?php echo esc_textarea( $s['exclude_paths'] ); ?></textarea>
						<p class="description">Prefissi da non esportare, uno per riga. <code>/wp-admin</code>, <code>/wp-json</code> e <code>/feed</code> sono gia' esclusi.</p>
					</td>
				</tr>
			</table>

			<h2 class="title">Esecuzione</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="schedule">Pianificazione</label></th>
					<td>
						<select id="schedule" name="schedule">
							<option value="manual" <?php selected( $s['schedule'], 'manual' ); ?>>Solo manuale</option>
							<option value="daily" <?php selected( $s['schedule'], 'daily' ); ?>>Giornaliera</option>
							<option value="weekly" <?php selected( $s['schedule'], 'weekly' ); ?>>Settimanale</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">ZIP scaricabili</th>
					<td>
						<label><input type="checkbox" name="make_zips" value="1" <?php checked( $s['make_zips'] ); ?> /> Crea gli ZIP dell'export originale e di quello ottimizzato</label>
					</td>
				</tr>
			</table>

			<h2 class="title">Avanzate</h2>
			<p>Valori predefiniti adatti alla maggior parte degli hosting.</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="deploy_engine">Motore di deploy</label></th>
					<td>
						<select id="deploy_engine" name="deploy_engine">
							<option value="pack" <?php selected( $s['deploy_engine'], 'pack' ); ?>>Pacchetto git — 1 upload, nessun rate limit (consigliato)</option>
							<option value="api" <?php selected( $s['deploy_engine'], 'api' ); ?>>API REST — file per file</option>
						</select>
						<p class="description">In caso di errore il plugin passa da solo al motore API.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="batch_seconds">Budget per ciclo</label></th>
					<td>
						<input type="number" id="batch_seconds" name="batch_seconds" value="<?php echo esc_attr( $s['batch_seconds'] ); ?>" min="5" max="50" class="small-text" /> secondi
						<p class="description">Riduci il valore su hosting con timeout aggressivi.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="parallel_uploads">Upload paralleli</label></th>
					<td>
						<input type="number" id="parallel_uploads" name="parallel_uploads" value="<?php echo esc_attr( $s['parallel_uploads'] ); ?>" min="1" max="20" class="small-text" />
						<p class="description">Richieste simultanee del motore API REST.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="keep_jobs">Export conservati</label></th>
					<td>
						<input type="number" id="keep_jobs" name="keep_jobs" value="<?php echo esc_attr( $s['keep_jobs'] ); ?>" min="1" max="10" class="small-text" />
						<p class="description">Quanti export tenere su disco: i piu' vecchi vengono eliminati all'avvio di uno nuovo. Gestione completa nella pagina <a href="<?php echo esc_url( admin_url( 'admin.php?page=sed-artifacts' ) ); ?>">Artefatti</a>.</p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'Salva impostazioni' ); ?>
		</form>
		<?php
	}
}
