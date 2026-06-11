<?php
/**
 * Logger minimale su file, con lettura delle ultime righe per la dashboard.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SED_Logger {

	/**
	 * Aggiunge una riga al log del job corrente.
	 *
	 * @param string $message Messaggio.
	 * @param string $level   info|warn|error.
	 */
	public static function log( $message, $level = 'info' ) {
		$job = SED_Queue::get_job();
		if ( ! $job || empty( $job['dir'] ) ) {
			return;
		}
		$file = trailingslashit( $job['dir'] ) . 'log.txt';
		$line = sprintf( "[%s] [%s] %s\n", gmdate( 'Y-m-d H:i:s' ), strtoupper( $level ), $message );
		@file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Restituisce le ultime N righe del log del job corrente.
	 *
	 * @param int $lines Numero di righe.
	 * @return string[]
	 */
	public static function tail( $lines = 60 ) {
		$job = SED_Queue::get_job();
		if ( ! $job || empty( $job['dir'] ) ) {
			return array();
		}
		$file = trailingslashit( $job['dir'] ) . 'log.txt';
		if ( ! file_exists( $file ) ) {
			return array();
		}
		$content = @file_get_contents( $file );
		if ( false === $content ) {
			return array();
		}
		$rows = preg_split( '/\r\n|\r|\n/', trim( $content ) );
		return array_slice( $rows, -1 * absint( $lines ) );
	}
}
