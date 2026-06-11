<?php

declare(strict_types=1);

namespace Cashu\WC\Helpers;

class Logger {
	public static function debug( $message, $force = false ): void {
		if ( get_option( 'cashu_debug' ) === 'yes' || $force ) {
			if ( ! is_string( $message ) ) {
				$message = wc_print_r( $message, true );
			}

			$logger  = new \WC_Logger();
			$context = array( 'source' => CASHU_WC_BASE );
			$logger->debug( $message, $context );
		}
	}

	public static function error( $message ): void {
		if ( ! is_string( $message ) ) {
			$message = wc_print_r( $message, true );
		}

		$logger  = new \WC_Logger();
		$context = array( 'source' => CASHU_WC_BASE );
		$logger->error( $message, $context );
	}

	public static function getLogFileUrl(): string {
		$log_file =
		CASHU_WC_BASE .
		'-' .
		gmdate( 'Y-m-d' ) .
		'-' .
		sanitize_file_name( wp_hash( CASHU_WC_BASE ) ) .
		'-log';
		return esc_url( admin_url( 'admin.php?page=wc-status&tab=logs&log_file=' . $log_file ) );
	}
}
