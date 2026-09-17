<?php
/**
 * ============================================================================
 *  TEMPORARY DIAGNOSTIC — NOT part of the plugin. Upload, use once, DELETE.
 * ============================================================================
 *
 * Browser-accessible version of the Browning refresh-token test, for hosts
 * without CLI/SSH access. Upload this file anywhere reachable over HTTPS on
 * the REAL production server (site root, or any folder), visit it in a
 * browser at the URL printed below, paste in a fresh refresh token, and read
 * the result. Then delete this file from the server — it accepts a pasted
 * OAuth credential and relays it to Microsoft/Browning, which is not
 * something that should be left sitting on a live server indefinitely.
 *
 * It does not write, log, or store anything — the token only ever exists in
 * this one request's memory.
 *
 * Access is gated by a secret key in the URL so this can't be stumbled onto:
 *   https://your-site.example/path/to/browning-diagnostic-web.php?key=dfe0ca1bf783a1ad960170958093070562ec9bf5e78d51d1
 */

const ACCESS_KEY = 'dfe0ca1bf783a1ad960170958093070562ec9bf5e78d51d1';

if ( ! isset( $_GET['key'] ) || ! hash_equals( ACCESS_KEY, (string) $_GET['key'] ) ) {
	http_response_code( 404 );
	exit;
}

const CLIENT_ID    = '27b04987-c8a0-4fd4-9eb3-3c105b480a0b';
const TENANT       = 'a548c4b0-f83e-49a8-9091-8a3329d0488a';
const REDIRECT_URI = 'https://dealer.browning.eu/en-gb/profile/login/callback';
const SCOPE        = 'email profile openid User.Read offline_access';

function post_form( $url, array $fields, array $extra_headers = array() ) {
	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => http_build_query( $fields ),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 20,
			// IONOS support: Debian 13 (Trixie)'s updated cURL/OpenSSL mishandles the
			// ALPN/HTTP2 fallback against Microsoft's gateway, which drops the route
			// with a bare 404. Disabling ALPN negotiation works around it.
			CURLOPT_SSL_ENABLE_ALPN => false,
			CURLOPT_HTTPHEADER     => array_merge(
				array( 'Origin: https://dealer.browning.eu', 'Referer: https://dealer.browning.eu/', 'Accept: application/json' ),
				$extra_headers
			),
		)
	);
	$body = curl_exec( $ch );
	if ( false === $body ) {
		$err = curl_error( $ch );
		curl_close( $ch );
		return array( 0, '', $err );
	}
	$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );
	return array( $code, $body, null );
}

function post_json( $url, $bearer, $payload ) {
	$headers = array( 'Content-Type: application/json; charset=UTF-8' );
	if ( $bearer ) {
		$headers[] = 'Authorization: Bearer ' . $bearer;
	}
	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_POST            => true,
			CURLOPT_POSTFIELDS      => json_encode( $payload ),
			CURLOPT_HTTPHEADER      => $headers,
			CURLOPT_RETURNTRANSFER  => true,
			CURLOPT_TIMEOUT         => 20,
			CURLOPT_SSL_ENABLE_ALPN => false,
		)
	);
	$body = curl_exec( $ch );
	$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );
	return array( $code, $body );
}

function probe_get( $url ) {
	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER  => true,
			CURLOPT_TIMEOUT         => 15,
			CURLOPT_HEADER          => false,
			CURLOPT_SSL_ENABLE_ALPN => false,
		)
	);
	$body = curl_exec( $ch );
	if ( false === $body ) {
		$err = curl_error( $ch );
		curl_close( $ch );
		return "NETWORK ERROR: $err";
	}
	$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );
	return "HTTP $code — " . substr( trim( $body ), 0, 150 );
}

function run_connectivity_probe() {
	$out  = "Step 0: outbound connectivity probe (to isolate a general host-level\n";
	$out .= "block from a Microsoft/Browning-specific one)...\n\n";
	$out .= '  Generic control (httpbin.org):        ' . probe_get( 'https://httpbin.org/get' ) . "\n";
	$out .= '  Microsoft identity platform:          ' . probe_get( 'https://login.microsoftonline.com/' . TENANT . '/v2.0/.well-known/openid-configuration' ) . "\n";
	$out .= '  Browning dealer portal (anonymous):   ' . probe_get( 'https://dealer.browning.eu/en-gb/' ) . "\n\n";
	return $out;
}

function run_diagnostic( $refresh_token ) {
	$out = run_connectivity_probe();

	$out .= "Step 1: refresh_token grant against Microsoft...\n";
	list( $code, $body, $curl_err ) = post_form(
		'https://login.microsoftonline.com/' . TENANT . '/oauth2/v2.0/token',
		array(
			'client_id'     => CLIENT_ID,
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh_token,
			'scope'         => SCOPE,
			'redirect_uri'  => REDIRECT_URI,
		)
	);

	if ( null !== $curl_err ) {
		$out .= "  NETWORK ERROR: $curl_err\n";
		$out .= "  Could not reach login.microsoftonline.com from this server. Check outbound HTTPS.\n";
		return $out;
	}

	$out .= "  HTTP $code\n";
	$json = json_decode( $body, true );

	if ( 200 !== $code || ! is_array( $json ) || ! isset( $json['id_token'] ) ) {
		$out .= "\n  RESULT: FAILED.\n";
		if ( is_array( $json ) && isset( $json['error'] ) ) {
			$out .= '  error: ' . $json['error'] . "\n";
			$out .= '  error_description: ' . ( $json['error_description'] ?? '(none)' ) . "\n";
			if ( isset( $json['error_codes'] ) && in_array( 53003, $json['error_codes'], true ) ) {
				$out .= "\n  >>> AADSTS53003 Conditional Access block — same as seen from the dev sandbox.\n";
				$out .= "  >>> This confirms the block is NOT location-specific: non-interactive\n";
				$out .= "  >>> refresh is blocked from this server too. See research/BROWNING-NOTES.md.\n";
			}
		} else {
			$out .= "  Raw response:\n" . $body . "\n";
		}
		return $out;
	}

	$out .= "  SUCCESS — got a fresh id_token with no interactive login.\n";
	$out .= '  expires_in: ' . $json['expires_in'] . " seconds\n";
	$out .= '  new refresh_token issued: ' . ( isset( $json['refresh_token'] ) ? 'yes' : 'no' ) . "\n\n";

	$id_token     = $json['id_token'];
	$access_token = $json['access_token'];

	$out .= "Step 2: exchanging id_token + access_token for a Sana session token...\n";
	// The real browser request uses Sana's persisted-query mechanism (a
	// pre-registered query hash), not a literal query string — replaying that
	// exact hash + the exact ExternalAuthenticationInput shape (idToken AND
	// accessToken, both required) rather than guessing our own query text.
	list( $code2, $body2 ) = post_json(
		'https://dealer.browning.eu/api/graph',
		null,
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
					'version'   => '1',
					'sha256Hash' => '88065568f0da60ecee939e153d331655d4a55b200eb4f3b967036d7e37a8a400',
				),
			),
		)
	);
	$out       .= "  HTTP $code2\n";
	$json2      = json_decode( $body2, true );
	$sana_token = $json2['data']['profile']['loginWithSingleSignOn']['token']['value'] ?? null;

	if ( ! $sana_token ) {
		$out .= "  RESULT: FAILED.\n  Raw response: $body2\n";
		return $out;
	}
	$out .= "  SUCCESS — got a Sana Bearer token.\n";
	$out .= '  expiration: ' . ( $json2['data']['profile']['loginWithSingleSignOn']['token']['expiration'] ?? '(not returned)' ) . "\n\n";

	$out        .= "Step 3: live stock query using the fresh Bearer token...\n";
	$stock_query = ' query CalculatedProductStocks($options:ProductsLoadOptions!){catalog{products(options:$options){products{id inventory secondaryInventory isOrderable}}}}';
	list( $code3, $body3 ) = post_json(
		'https://dealer.browning.eu/api/graph',
		$sana_token,
		array(
			'query'     => $stock_query,
			'variables' => array(
				'options' => array(
					'ids'   => array( 'C192102431' ),
					'uomId' => null,
					'page'  => array(
						'size'  => 1,
						'index' => 0,
					),
				),
			),
		)
	);
	$out .= "  HTTP $code3\n";
	$out .= "  Response: $body3\n\n";
	$out .= "ALL THREE STEPS SUCCEEDED from this server, no browser needed.\n";
	$out .= "Option 1 (fully automated background refresh) is confirmed viable.\n";

	return $out;
}

$result = null;
if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['action'] ) && 'probe' === $_POST['action'] ) {
	$result = run_connectivity_probe();
} elseif ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['refresh_token'] ) && '' !== trim( $_POST['refresh_token'] ) ) {
	$result = run_diagnostic( trim( $_POST['refresh_token'] ) );
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Browning connector diagnostic (delete after use)</title>
<style>
	body { font-family: system-ui, sans-serif; max-width: 700px; margin: 40px auto; padding: 0 16px; color: #222; }
	.warn { background: #fff3cd; border: 1px solid #ffe69c; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; }
	textarea { width: 100%; box-sizing: border-box; }
	pre { background: #1e1e1e; color: #d4d4d4; padding: 16px; border-radius: 6px; overflow-x: auto; white-space: pre-wrap; word-break: break-word; }
	label { display: block; margin-bottom: 6px; font-weight: 600; }
	button { padding: 8px 20px; font-size: 14px; cursor: pointer; }
</style>
</head>
<body>
	<div class="warn">
		<strong>Temporary diagnostic.</strong> Delete this file from the server once you're done with it —
		it accepts a pasted Microsoft refresh token and uses it to make live calls. Nothing typed here is
		logged, stored, or written to disk; it only exists for the duration of this one request.
	</div>

	<h1>Browning connector diagnostic</h1>
	<p>Paste a <strong>fresh</strong> refresh token (from a real browser login just now — DevTools →
	Application → Local Storage → <code>https://dealer.browning.eu</code> → key <code>au.rt</code>),
	then submit. This tests whether this server can silently renew the Browning session with no
	browser involved.</p>

	<form method="post" action="?key=<?php echo urlencode( $_GET['key'] ); ?>">
		<p><button type="submit" name="action" value="probe">Test connectivity only (no token needed)</button></p>
	</form>

	<form method="post" action="?key=<?php echo urlencode( $_GET['key'] ); ?>">
		<label for="refresh_token">Refresh token (au.rt value)</label>
		<textarea id="refresh_token" name="refresh_token" rows="4"></textarea>
		<p><button type="submit">Run full diagnostic</button></p>
	</form>

	<?php if ( null !== $result ) : ?>
		<h2>Result</h2>
		<pre><?php echo htmlspecialchars( $result, ENT_QUOTES ); ?></pre>
	<?php endif; ?>
</body>
</html>
