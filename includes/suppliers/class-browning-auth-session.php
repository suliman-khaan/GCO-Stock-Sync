<?php
/**
 * Browning Auth Session — Microsoft OAuth2 refresh-token cycle + Sana
 * Commerce session-token exchange for the Browning dealer portal.
 *
 * Architecture confirmed live in research/BROWNING-NOTES.md: a one-time,
 * human-performed login extracts a Microsoft refresh_token from browser
 * Local Storage; everything after that is silent, server-side refresh — no
 * headless browser, no separate hosting. The initial login can never be
 * automated (we don't control Browning's Azure app registration's redirect
 * URIs) — see BROWNING-NOTES.md §3.1. Do not attempt to change that.
 *
 * @package GCO_Stock_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GCO_Stock_Sync_Browning_Auth_Session
 */
class GCO_Stock_Sync_Browning_Auth_Session {

	/**
	 * Browning's own Microsoft Entra tenant (not the client's).
	 *
	 * @var string
	 */
	const TENANT = 'a548c4b0-f83e-49a8-9091-8a3329d0488a';

	/**
	 * Public client ID of the Browning-Sana-SSO app registration.
	 *
	 * @var string
	 */
	const CLIENT_ID = '27b04987-c8a0-4fd4-9eb3-3c105b480a0b';

	/**
	 * Redirect URI the app registration is locked to. Only relevant to the
	 * token endpoint's own validation of the refresh request shape — this
	 * plugin is never itself redirected anywhere.
	 *
	 * @var string
	 */
	const REDIRECT_URI = 'https://dealer.browning.eu/en-gb/profile/login/callback';

	/**
	 * OAuth scope requested on every refresh.
	 *
	 * @var string
	 */
	const SCOPE = 'email profile openid User.Read offline_access';

	/**
	 * Sana's pre-registered persisted-query hash for the loginWithSingleSignOn
	 * mutation. Confirmed from a full (untruncated) HAR capture — see
	 * BROWNING-NOTES.md §1/§6c.
	 *
	 * @var string
	 */
	const SSO_PERSISTED_QUERY_HASH = '88065568f0da60ecee939e153d331655d4a55b200eb4f3b967036d7e37a8a400';

	/**
	 * Browning's single GraphQL endpoint, used for both the SSO exchange and
	 * (by the caller) the stock query itself.
	 *
	 * @var string
	 */
	const GRAPH_ENDPOINT = 'https://dealer.browning.eu/api/graph';

	/**
	 * Substring identifying Microsoft's token endpoint, for scoping the ALPN
	 * workaround to only that host.
	 *
	 * @var string
	 */
	const MS_HOST_NEEDLE = 'login.microsoftonline.com';

	/**
	 * Transient holding the current cached Sana Bearer token.
	 *
	 * @var string
	 */
	const SANA_TOKEN_TRANSIENT = 'gco_ss_browning_sana_token';

	/**
	 * Option flagging that the stored refresh token was rejected by
	 * Microsoft and a human needs to paste in a fresh one.
	 *
	 * @var string
	 */
	const REAUTH_OPTION = 'gco_ss_browning_reauth_needed';

	/**
	 * Refresh the Sana token this many seconds before its real expiry, so a
	 * long-running sync never starts a request with a token that expires
	 * mid-flight.
	 *
	 * @var int
	 */
	const SANA_TOKEN_SAFETY_BUFFER = 120;

	/**
	 * Whether the ALPN workaround has been registered yet in this process.
	 * Guards against duplicate add_action() calls if this class is
	 * constructed more than once per request (get_suppliers() is not
	 * memoized, so that does happen).
	 *
	 * @var bool
	 */
	private static $alpn_hook_registered = false;

	/**
	 * Option name holding this supplier's settings (owns 'refresh_token').
	 *
	 * @var string
	 */
	private $settings_option_name;

	/**
	 * Constructor.
	 *
	 * @param string $settings_option_name The supplier's own settings option
	 *                                     name (e.g. from get_option_name()),
	 *                                     so the refresh token can be read
	 *                                     and the rotated value written back
	 *                                     to the exact same admin-editable row.
	 */
	public function __construct( $settings_option_name ) {
		$this->settings_option_name = $settings_option_name;
		$this->maybe_register_alpn_workaround();
	}

	/**
	 * Register the ALPN workaround once per process. Confirmed live
	 * (BROWNING-NOTES.md §6a): this host's cURL/OpenSSL stack mishandles
	 * ALPN negotiation against Microsoft's gateway specifically, returning a
	 * bare 404 instead of a real response. Scoped strictly to Microsoft's
	 * token host — never applied to any other outbound request on this site.
	 */
	private function maybe_register_alpn_workaround() {
		if ( self::$alpn_hook_registered ) {
			return;
		}

		add_action( 'http_api_curl', array( __CLASS__, 'disable_alpn_for_microsoft' ), 10, 3 );
		self::$alpn_hook_registered = true;
	}

	/**
	 * http_api_curl callback — disables ALPN negotiation only for requests
	 * to Microsoft's identity platform.
	 *
	 * @param resource|\CurlHandle $handle The curl handle, before curl_exec().
	 * @param array                $r      WP_Http request args (unused).
	 * @param string               $url    The request URL.
	 */
	public static function disable_alpn_for_microsoft( $handle, $r, $url ) {
		if ( false !== strpos( $url, self::MS_HOST_NEEDLE ) ) {
			curl_setopt( $handle, CURLOPT_SSL_ENABLE_ALPN, false );
		}
	}

	/**
	 * Get a currently-valid Sana Bearer token, refreshing through Microsoft
	 * only when the cached one is missing or within SANA_TOKEN_SAFETY_BUFFER
	 * seconds of expiring.
	 *
	 * @return string|WP_Error Bearer token, or WP_Error describing why not.
	 */
	public function get_bearer_token() {
		$cached = get_transient( self::SANA_TOKEN_TRANSIENT );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		return $this->refresh_cycle();
	}

	/**
	 * Whether the stored refresh token was rejected by Microsoft and needs
	 * to be replaced by a human.
	 *
	 * @return bool
	 */
	public function is_reauth_needed() {
		return false !== get_option( self::REAUTH_OPTION, false );
	}

	/**
	 * Human-readable reason the reauth flag was set, for display in the admin.
	 *
	 * @return string
	 */
	public function get_reauth_message() {
		$data = get_option( self::REAUTH_OPTION, false );

		return ( is_array( $data ) && isset( $data['message'] ) ) ? $data['message'] : '';
	}

	/**
	 * The full refresh cycle: Microsoft refresh_token grant, then exchange
	 * the resulting id_token + access_token for a Sana session token.
	 *
	 * @return string|WP_Error
	 */
	private function refresh_cycle() {
		$refresh_token = $this->get_stored_refresh_token();

		if ( '' === $refresh_token ) {
			return new WP_Error( 'browning_not_configured', __( 'No Browning refresh token configured.', 'gco-stock-sync' ) );
		}

		$ms = $this->request_microsoft_token( $refresh_token );

		if ( is_wp_error( $ms ) ) {
			// Transport-level failure (network, timeout) — NOT a reauth signal.
			return $ms;
		}

		if ( isset( $ms['error'] ) ) {
			// A genuine credential rejection from Microsoft — this is the one
			// case that means a human needs to paste in a fresh token.
			$this->set_reauth_needed( isset( $ms['error_description'] ) ? $ms['error_description'] : $ms['error'] );

			return new WP_Error( 'browning_needs_reauth', __( 'Browning refresh token was rejected by Microsoft. Reauthentication needed.', 'gco-stock-sync' ) );
		}

		if ( ! isset( $ms['id_token'], $ms['access_token'] ) ) {
			return new WP_Error( 'browning_fetch_failed', __( 'Unexpected response from the Microsoft token endpoint.', 'gco-stock-sync' ) );
		}

		// Microsoft rotates the refresh token on every use — the old value
		// becomes invalid, so the new one MUST be persisted or the next
		// refresh will fail even though nothing is actually wrong.
		if ( ! empty( $ms['refresh_token'] ) ) {
			$this->store_refresh_token( $ms['refresh_token'] );
		}

		$sana = $this->request_sana_token( $ms['id_token'], $ms['access_token'] );

		if ( is_wp_error( $sana ) ) {
			return $sana;
		}

		// A working Sana exchange proves the credential is genuinely valid —
		// clear any stale reauth flag left over from an earlier failure.
		$this->clear_reauth_needed();

		$ttl = max( 60, $sana['ttl'] - self::SANA_TOKEN_SAFETY_BUFFER );
		set_transient( self::SANA_TOKEN_TRANSIENT, $sana['token'], $ttl );

		return $sana['token'];
	}

	/**
	 * POST the refresh_token grant to Microsoft.
	 *
	 * @param string $refresh_token Current refresh token.
	 * @return array|WP_Error Decoded JSON body (may itself contain an 'error'
	 *                        key on a credential rejection), or WP_Error on
	 *                        a transport-level failure.
	 */
	private function request_microsoft_token( $refresh_token ) {
		$response = wp_remote_post(
			'https://login.microsoftonline.com/' . self::TENANT . '/oauth2/v2.0/token',
			array(
				'timeout' => 20,
				'body'    => array(
					'client_id'     => self::CLIENT_ID,
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh_token,
					'scope'         => self::SCOPE,
					'redirect_uri'  => self::REDIRECT_URI,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Exchange a Microsoft id_token + access_token for a Sana session token.
	 *
	 * @param string $id_token     Microsoft id_token.
	 * @param string $access_token Microsoft access_token.
	 * @return array|WP_Error {
	 *     @type string $token Sana Bearer token.
	 *     @type int    $ttl   Seconds until it expires.
	 * }
	 */
	private function request_sana_token( $id_token, $access_token ) {
		$response = wp_remote_post(
			self::GRAPH_ENDPOINT,
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json; charset=UTF-8' ),
				'body'    => wp_json_encode(
					array(
						'variables'  => array(
							'externalAuthenticationInput' => array(
								'idToken'     => $id_token,
								'accessToken' => $access_token,
							),
							'keys'                        => array( 'VIEW_CATALOG', 'VIEW_STOCK' ),
						),
						'extensions' => array(
							'persistedQuery' => array(
								'version'    => '1',
								'sha256Hash' => self::SSO_PERSISTED_QUERY_HASH,
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$json  = json_decode( wp_remote_retrieve_body( $response ), true );
		$token = isset( $json['data']['profile']['loginWithSingleSignOn']['token']['value'] )
			? $json['data']['profile']['loginWithSingleSignOn']['token']['value']
			: null;

		if ( ! $token ) {
			return new WP_Error( 'browning_sso_failed', __( 'Browning did not return a session token for the given credentials.', 'gco-stock-sync' ) );
		}

		$expiration = isset( $json['data']['profile']['loginWithSingleSignOn']['token']['expiration'] )
			? $json['data']['profile']['loginWithSingleSignOn']['token']['expiration']
			: null;

		$ttl = 3300; // ~55 min fallback if the expiration field is ever missing/unparseable.
		if ( $expiration ) {
			$expires_at = strtotime( $expiration );
			if ( $expires_at ) {
				$ttl = max( 60, $expires_at - time() );
			}
		}

		return array(
			'token' => $token,
			'ttl'   => $ttl,
		);
	}

	/**
	 * Read the currently-stored refresh token from the supplier's own
	 * settings option.
	 *
	 * @return string
	 */
	private function get_stored_refresh_token() {
		$settings = get_option( $this->settings_option_name, array() );

		return isset( $settings['refresh_token'] ) ? trim( (string) $settings['refresh_token'] ) : '';
	}

	/**
	 * Persist a rotated refresh token back into the supplier's settings
	 * option, without touching any other setting (product_map, etc.).
	 *
	 * @param string $refresh_token New refresh token from Microsoft.
	 */
	private function store_refresh_token( $refresh_token ) {
		$settings = get_option( $this->settings_option_name, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings['refresh_token'] = $refresh_token;
		update_option( $this->settings_option_name, $settings );
	}

	/**
	 * Flag that the refresh token was rejected and a human needs to act.
	 *
	 * @param string $message Reason, from Microsoft's error_description if available.
	 */
	private function set_reauth_needed( $message ) {
		update_option(
			self::REAUTH_OPTION,
			array(
				'message' => (string) $message,
				'time'    => time(),
			)
		);
	}

	/**
	 * Clear the reauth flag, e.g. after a fresh token starts working again.
	 */
	private function clear_reauth_needed() {
		delete_option( self::REAUTH_OPTION );
	}
}
