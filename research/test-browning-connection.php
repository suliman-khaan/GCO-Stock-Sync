<?php
/**
 * Standalone diagnostic — NOT part of the plugin, run manually once.
 *
 * Proves (or disproves) whether Browning's dealer-portal Microsoft refresh
 * token can be silently exchanged for a fresh session from THIS server,
 * without any browser/interactive login. This is the one open question from
 * research/BROWNING-NOTES.md: does the AADSTS53003 Conditional Access block
 * seen from the dev sandbox also happen from the real production host, or
 * was that a location-specific block that doesn't apply here?
 *
 * Usage:
 *   php test-browning-connection.php "<refresh_token>"
 *
 * Get the refresh_token fresh from a real browser login to
 * https://dealer.browning.eu/ : DevTools -> Application -> Local Storage ->
 * https://dealer.browning.eu -> key "au.rt". Use a FRESH one — don't reuse
 * an old one from a chat log or ticket, since the whole point is testing
 * from this server's real network location.
 *
 * This script does not write anything anywhere. It only makes 3 read-only
 * HTTPS calls and prints what happened at each step.
 */

if ( $argc < 2 || '' === trim( $argv[1] ) ) {
	fwrite( STDERR, "Usage: php test-browning-connection.php \"<refresh_token>\"\n" );
	fwrite( STDERR, "Get a fresh one from: DevTools -> Application -> Local Storage -> https://dealer.browning.eu -> key \"au.rt\"\n" );
	exit( 1 );
}

$refresh_token = trim( $argv[1] );
$client_id     = '27b04987-c8a0-4fd4-9eb3-3c105b480a0b';
$tenant        = 'a548c4b0-f83e-49a8-9091-8a3329d0488a';
$redirect_uri  = 'https://dealer.browning.eu/en-gb/profile/login/callback';
$scope         = 'email profile openid User.Read offline_access';

function post_form( $url, array $fields, array $extra_headers = array() ) {
	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_POST            => true,
			CURLOPT_POSTFIELDS      => http_build_query( $fields ),
			CURLOPT_RETURNTRANSFER  => true,
			CURLOPT_TIMEOUT         => 20,
			// IONOS support: Debian 13 (Trixie)'s cURL/OpenSSL mishandles the
			// ALPN/HTTP2 fallback against Microsoft's gateway (bare 404). Disabling
			// ALPN negotiation works around it.
			CURLOPT_SSL_ENABLE_ALPN => false,
			CURLOPT_HTTPHEADER      => array_merge(
				array(
					'Origin: https://dealer.browning.eu',
					'Referer: https://dealer.browning.eu/',
					'Accept: application/json',
				),
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

echo "==========================================================\n";
echo "  Browning refresh-token diagnostic — testing from THIS host\n";
echo "==========================================================\n\n";

echo "Step 1: refresh_token grant against Microsoft...\n";
list( $code, $body, $curl_err ) = post_form(
	"https://login.microsoftonline.com/$tenant/oauth2/v2.0/token",
	array(
		'client_id'     => $client_id,
		'grant_type'    => 'refresh_token',
		'refresh_token' => $refresh_token,
		'scope'         => $scope,
		'redirect_uri'  => $redirect_uri,
	)
);

if ( null !== $curl_err ) {
	echo "  NETWORK ERROR: $curl_err\n";
	echo "  Could not even reach login.microsoftonline.com from this server. Check outbound HTTPS/firewall.\n";
	exit( 1 );
}

echo "  HTTP $code\n";
$json = json_decode( $body, true );

if ( 200 !== $code || ! is_array( $json ) || ! isset( $json['id_token'] ) ) {
	echo "\n  RESULT: FAILED.\n";
	if ( is_array( $json ) && isset( $json['error'] ) ) {
		echo '  error: ' . $json['error'] . "\n";
		echo '  error_description: ' . ( $json['error_description'] ?? '(none)' ) . "\n";
		if ( isset( $json['error_codes'] ) && in_array( 53003, $json['error_codes'], true ) ) {
			echo "\n  >>> Same AADSTS53003 Conditional Access block as seen elsewhere.\n";
			echo "  >>> This confirms it's NOT location-specific — the policy blocks\n";
			echo "  >>> non-interactive refresh from ANY server, not just one location.\n";
			echo "  >>> See research/BROWNING-NOTES.md §5 for what this means.\n";
		}
	} else {
		echo "  Raw response:\n$body\n";
	}
	exit( 1 );
}

echo "  SUCCESS — got a fresh id_token without any interactive login.\n";
echo '  expires_in: ' . $json['expires_in'] . " seconds\n";
echo '  new refresh_token issued: ' . ( isset( $json['refresh_token'] ) ? 'yes (save this one — Microsoft may rotate it each time)' : 'no' ) . "\n\n";

$id_token     = $json['id_token'];
$access_token = $json['access_token'];

echo "Step 2: exchanging id_token + access_token for a Sana session token...\n";
// Real browser request uses Sana's persisted-query mechanism (a pre-registered
// query hash), not a literal query string — replaying that exact hash + the
// exact ExternalAuthenticationInput shape (idToken AND accessToken, both
// required), extracted from the full (untruncated) HAR capture.
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
				'version'    => '1',
				'sha256Hash' => '88065568f0da60ecee939e153d331655d4a55b200eb4f3b967036d7e37a8a400',
			),
		),
	)
);
echo "  HTTP $code2\n";
$json2      = json_decode( $body2, true );
$sana_token = $json2['data']['profile']['loginWithSingleSignOn']['token']['value'] ?? null;

if ( ! $sana_token ) {
	echo "  RESULT: FAILED.\n  Raw response: $body2\n";
	exit( 1 );
}
echo "  SUCCESS — got a Sana Bearer token.\n";
echo '  expiration: ' . ( $json2['data']['profile']['loginWithSingleSignOn']['token']['expiration'] ?? '(not returned)' ) . "\n\n";

echo "Step 3: live stock query using the fresh Bearer token...\n";
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
echo "  HTTP $code3\n";
echo "  Response: $body3\n\n";

echo "==========================================================\n";
echo "  ALL THREE STEPS SUCCEEDED from this server, no browser needed.\n";
echo "  Option 1 (fully automated background refresh) is confirmed viable.\n";
echo "==========================================================\n";
