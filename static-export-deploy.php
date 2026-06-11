<?php
/**
 * Plugin Name:       Static Export & Deploy
 * Plugin URI:        https://github.com/alessandromasone/static-export-deploy
 * Description:       Esporta il sito in HTML statico (crawler integrato, compatibile WPLingua), lo ottimizza (WebP, pulizia HTML/SEO, GA4/AdSense) e lo pubblica su GitHub (branch raw + main) — tutto in background.
 * Version:           1.8.0
 * Author:            Alessandro Masone
 * License:           GPL-2.0-or-later
 * Text Domain:       static-export-deploy
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SED_VERSION', '1.8.0' );
define( 'SED_PLUGIN_FILE', __FILE__ );
define( 'SED_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SED_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SED_PLUGIN_DIR . 'includes/class-sed-logger.php';
require_once SED_PLUGIN_DIR . 'includes/class-sed-settings.php';
require_once SED_PLUGIN_DIR . 'includes/class-sed-crawler.php';
require_once SED_PLUGIN_DIR . 'includes/class-sed-optimizer.php';
require_once SED_PLUGIN_DIR . 'includes/class-sed-audit.php';
require_once SED_PLUGIN_DIR . 'includes/class-sed-github.php';
require_once SED_PLUGIN_DIR . 'includes/class-sed-gitpack.php';
require_once SED_PLUGIN_DIR . 'includes/class-sed-queue.php';
require_once SED_PLUGIN_DIR . 'includes/class-sed-admin.php';

/**
 * Bootstrap del plugin.
 */
final class SED_Plugin {

	/** @var SED_Plugin */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		register_activation_hook( SED_PLUGIN_FILE, array( $this, 'on_activate' ) );
		register_deactivation_hook( SED_PLUGIN_FILE, array( $this, 'on_deactivate' ) );

		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );

		// Pipeline in background.
		SED_Queue::init();

		// Interfaccia di amministrazione.
		if ( is_admin() ) {
			SED_Admin::init();
		}

		// Export pianificato.
		add_action( 'sed_scheduled_export', array( $this, 'run_scheduled_export' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
	}

	public function on_activate() {
		if ( ! get_option( 'sed_process_key' ) ) {
			update_option( 'sed_process_key', wp_generate_password( 40, false, false ), false );
		}
		SED_Settings::ensure_defaults();
	}

	public function on_deactivate() {
		wp_clear_scheduled_hook( 'sed_watchdog' );
		wp_clear_scheduled_hook( 'sed_scheduled_export' );
		delete_option( 'sed_lock' );
	}

	public function cron_schedules( $schedules ) {
		if ( ! isset( $schedules['sed_minutely'] ) ) {
			$schedules['sed_minutely'] = array(
				'interval' => 60,
				'display'  => __( 'Ogni minuto (Static Export & Deploy)', 'static-export-deploy' ),
			);
		}
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Settimanale', 'static-export-deploy' ),
			);
		}
		return $schedules;
	}

	/**
	 * Allinea la pianificazione automatica con l'opzione scelta.
	 */
	public function maybe_schedule() {
		$schedule = SED_Settings::get( 'schedule' );
		$hooked   = wp_next_scheduled( 'sed_scheduled_export' );

		if ( 'manual' === $schedule && $hooked ) {
			wp_clear_scheduled_hook( 'sed_scheduled_export' );
			return;
		}
		if ( in_array( $schedule, array( 'daily', 'weekly' ), true ) && ! $hooked ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, 'sed_scheduled_export' );
		}
	}

	public function run_scheduled_export() {
		// Non avvia un nuovo job se ce n'e' gia' uno in corso.
		$job = SED_Queue::get_job();
		if ( $job && 'running' === $job['status'] ) {
			return;
		}
		SED_Queue::start_job( 'scheduled' );
	}
}

SED_Plugin::instance();
