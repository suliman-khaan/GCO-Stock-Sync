<?php
/**
 * Failure Notifier — alerts site administrators when sync runs fail repeatedly.
 *
 * Prevents silent multi-day breakage (e.g. when supplier feed hashes rotate)
 * while deduplicating alerts so admins aren't spammed every hour.
 *
 * @package GCO_Stock_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GCO_Stock_Sync_Failure_Notifier
 */
class GCO_Stock_Sync_Failure_Notifier {

	/**
	 * Option key for consecutive failure count.
	 */
	const OPTION_CONSECUTIVE_FAILURES = 'gco_stock_sync_consecutive_failures';

	/**
	 * Option key for timestamp of the last email alert sent.
	 */
	const OPTION_LAST_ALERT_TIME = 'gco_stock_sync_last_failure_alert';

	/**
	 * Default failure count threshold before emailing.
	 */
	const DEFAULT_FAILURE_THRESHOLD = 3;

	/**
	 * Default deduplication cooldown in seconds (24 hours).
	 */
	const DEFAULT_ALERT_INTERVAL = 86400;

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_action( 'gco_stock_sync_run_completed', array( $this, 'handle_run_completed' ), 10, 2 );
	}

	/**
	 * Handle run completion event.
	 *
	 * @param string                      $supplier_key Supplier key.
	 * @param GCO_Stock_Sync_Sync_Result $result       Sync result object.
	 */
	public function handle_run_completed( $supplier_key, $result ) {
		if ( ! $result instanceof GCO_Stock_Sync_Sync_Result ) {
			return;
		}

		if ( 'success' === $result->status ) {
			$this->reset_failure_count();
			return;
		}

		if ( 'failed' === $result->status ) {
			$this->record_failure_and_maybe_alert( $supplier_key, $result->message );
		}
	}

	/**
	 * Get the current consecutive failure count.
	 *
	 * @return int
	 */
	public function get_consecutive_failures() {
		return absint( get_option( self::OPTION_CONSECUTIVE_FAILURES, 0 ) );
	}

	/**
	 * Reset consecutive failure count to 0.
	 */
	public function reset_failure_count() {
		update_option( self::OPTION_CONSECUTIVE_FAILURES, 0 );
	}

	/**
	 * Increment failure counter and trigger alert if threshold reached.
	 *
	 * @param string $supplier_key Supplier key.
	 * @param string $error_msg    Error message from sync result.
	 * @return bool True if email was dispatched, false otherwise.
	 */
	public function record_failure_and_maybe_alert( $supplier_key, $error_msg = '' ) {
		$failures = $this->get_consecutive_failures() + 1;
		update_option( self::OPTION_CONSECUTIVE_FAILURES, $failures );

		$threshold = apply_filters( 'gco_stock_sync_failure_threshold', self::DEFAULT_FAILURE_THRESHOLD );
		$cooldown  = apply_filters( 'gco_stock_sync_alert_interval', self::DEFAULT_ALERT_INTERVAL );

		if ( $failures < $threshold ) {
			return false;
		}

		$last_alert = absint( get_option( self::OPTION_LAST_ALERT_TIME, 0 ) );
		$now        = time();

		if ( ( $now - $last_alert ) < $cooldown ) {
			// In deduplication window; skip sending email.
			return false;
		}

		update_option( self::OPTION_LAST_ALERT_TIME, $now );
		return $this->send_failure_email( $supplier_key, $failures, $error_msg );
	}

	/**
	 * Dispatch email notification to site admin.
	 *
	 * @param string $supplier_key Supplier key.
	 * @param int    $failures     Consecutive failure count.
	 * @param string $error_msg    Error message.
	 * @return bool
	 */
	private function send_failure_email( $supplier_key, $failures, $error_msg ) {
		$admin_email = get_option( 'admin_email' );
		$to          = apply_filters( 'gco_stock_sync_alert_email', $admin_email );

		if ( empty( $to ) || ! is_email( $to ) ) {
			return false;
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$log_url   = admin_url( 'admin.php?page=gco-stock-sync&tab=logs' );

		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Stock Sync Alert: Repeated sync failures detected', 'gco-stock-sync' ), $site_name );

		$message  = sprintf(
			/* translators: 1: site name, 2: failure count, 3: supplier key */
			__( "Attention Admin,\n\nThe GCO Stock Sync plugin on %1\$s has failed %2\$d consecutive times while connecting to supplier '%3\$s'.\n\n", 'gco-stock-sync' ),
			$site_name,
			$failures,
			$supplier_key
		);

		if ( ! empty( $error_msg ) ) {
			$message .= sprintf(
				/* translators: %s: error message */
				__( "Reported Error:\n%s\n\n", 'gco-stock-sync' ),
				$error_msg
			);
		}

		$message .= __( "SAFETY NOTICE:\nPer plugin safety rules, existing WooCommerce stock statuses have been preserved and NOT changed. No products were set out of stock.\n\n", 'gco-stock-sync' );

		$message .= sprintf(
			/* translators: %s: admin log URL */
			__( "Please review the sync history and supplier feed settings:\n%s\n", 'gco-stock-sync' ),
			$log_url
		);

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		return (bool) wp_mail( $to, $subject, $message, $headers );
	}
}
