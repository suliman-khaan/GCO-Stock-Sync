<?php
/**
 * Odoo Session — thin JSON-RPC POST helper for the Ladds Guns (INFAC) storefront.
 *
 * Findings in research/LADDS-NOTES.md show the get_combination_info endpoint is
 * fully stateless: anonymous requests succeed with zero cookies, no CSRF token,
 * and no Referer header. A session is therefore not required to make requests
 * work — this class exists only to behave politely (pass back whatever cookie
 * the server last issued) and to give the connector one bounded retry when a
 * request does come back as a 4xx or an Odoo error object.
 *
 * @package GCO_Stock_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GCO_Stock_Sync_Odoo_Session
 */
class GCO_Stock_Sync_Odoo_Session {

	/**
	 * Transient key holding the last-seen session_id cookie value.
	 *
	 * @var string
	 */
	const TRANSIENT_KEY = 'gco_ss_ladds_session';

	/**
	 * How long a captured session_id cookie is reused before being dropped.
	 *
	 * @var int
	 */
	const SESSION_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Base URL of the storefront, e.g. https://www.laddsguns.com.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Constructor.
	 *
	 * @param string $base_url Storefront base URL (scheme + host, no trailing slash).
	 */
	public function __construct( $base_url ) {
		$this->base_url = untrailingslashit( $base_url );
	}

	/**
	 * Make a JSON-RPC 2.0 "call" POST against the given route.
	 *
	 * Retries exactly once, with a fresh (cookie-less) attempt, when the first
	 * attempt comes back as an HTTP 4xx or an Odoo `{"error": ...}` body — per
	 * the "no second retry" rule, a second failure is returned to the caller
	 * as-is.
	 *
	 * @param string $path    Route path, e.g. '/website_sale/get_combination_info'.
	 * @param array  $params  JSON-RPC params payload.
	 * @param int    $timeout Request timeout in seconds.
	 * @return array {
	 *     @type bool        $ok           True if the HTTP request completed (regardless of Odoo-level error).
	 *     @type WP_Error|null $transport_error Transport-level failure, if any.
	 *     @type int|null    $http_code    HTTP status code, if the request completed.
	 *     @type array|null  $decoded      Decoded JSON-RPC body (has either 'result' or 'error'), if parseable.
	 *     @type string      $raw_body     Raw response body, for debugging.
	 * }
	 */
	public function call( $path, array $params, $timeout = 15 ) {
		$result = $this->attempt( $path, $params, $timeout );

		if ( $this->should_retry( $result ) ) {
			delete_transient( self::TRANSIENT_KEY );
			$result = $this->attempt( $path, $params, $timeout );
		}

		return $result;
	}

	/**
	 * Whether a completed attempt warrants the single allowed retry.
	 *
	 * @param array $result Result of attempt().
	 * @return bool
	 */
	private function should_retry( $result ) {
		if ( ! $result['ok'] ) {
			return false; // Transport failure — retrying won't help and risks hammering the host.
		}

		if ( $result['http_code'] >= 400 && $result['http_code'] < 500 ) {
			return true;
		}

		if ( is_array( $result['decoded'] ) && isset( $result['decoded']['error'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Perform a single JSON-RPC POST attempt.
	 *
	 * @param string $path    Route path.
	 * @param array  $params  JSON-RPC params payload.
	 * @param int    $timeout Request timeout in seconds.
	 * @return array See call() for shape.
	 */
	private function attempt( $path, array $params, $timeout ) {
		$headers = array( 'Content-Type' => 'application/json' );

		$cookie = get_transient( self::TRANSIENT_KEY );
		if ( is_string( $cookie ) && '' !== $cookie ) {
			$headers['Cookie'] = 'session_id=' . $cookie;
		}

		$body = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'method'  => 'call',
				'params'  => $params,
			)
		);

		$response = wp_remote_post(
			$this->base_url . $path,
			array(
				'timeout'    => $timeout,
				'headers'    => $headers,
				'body'       => $body,
				'user-agent' => 'GCO-Stock-Sync/' . GCO_STOCK_SYNC_VERSION . '; ' . home_url(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'              => false,
				'transport_error' => $response,
				'http_code'       => null,
				'decoded'         => null,
				'raw_body'        => '',
			);
		}

		$this->maybe_store_session_cookie( $response );

		$raw_body = wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $raw_body, true );

		return array(
			'ok'              => true,
			'transport_error' => null,
			'http_code'       => (int) wp_remote_retrieve_response_code( $response ),
			'decoded'         => is_array( $decoded ) ? $decoded : null,
			'raw_body'        => $raw_body,
		);
	}

	/**
	 * Capture the session_id cookie from a response's Set-Cookie header, if present.
	 *
	 * @param array|WP_Error $response Result of wp_remote_post().
	 */
	private function maybe_store_session_cookie( $response ) {
		$set_cookie = wp_remote_retrieve_header( $response, 'set-cookie' );

		// WordPress may return a single string or an array when the header repeats.
		$candidates = is_array( $set_cookie ) ? $set_cookie : array( $set_cookie );

		foreach ( $candidates as $cookie_line ) {
			if ( ! is_string( $cookie_line ) ) {
				continue;
			}

			if ( preg_match( '/session_id=([^;]+)/', $cookie_line, $matches ) ) {
				set_transient( self::TRANSIENT_KEY, $matches[1], self::SESSION_TTL );
				return;
			}
		}
	}
}
