<?php
/**
 * Disinstallazione: rimuove opzioni, eventi cron e la cartella di lavoro.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'sed_settings' );
delete_option( 'sed_job' );
delete_option( 'sed_process_key' );
delete_option( 'sed_lock' );
delete_transient( 'sed_lang_sniff' );

wp_clear_scheduled_hook( 'sed_watchdog' );
wp_clear_scheduled_hook( 'sed_scheduled_export' );

// Rimozione della cartella di lavoro (export e log).
$uploads = wp_upload_dir();
$base    = trailingslashit( $uploads['basedir'] ) . 'sed-export';

if ( is_dir( $base ) ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
	}
	@rmdir( $base );
}
