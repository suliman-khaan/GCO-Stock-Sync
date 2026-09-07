<?php
defined( 'ABSPATH' ) || ( php_sapi_name() === 'cli' ? null : exit );

$root_dir = dirname( __DIR__ );
$languages_dir = $root_dir . '/languages';
if ( ! is_dir( $languages_dir ) ) {
	mkdir( $languages_dir, 0755, true );
}

$pot_file = $languages_dir . '/gco-stock-sync.pot';

$exclude_dirs = array(
	realpath( $root_dir . '/tests' ),
	realpath( $root_dir . '/vendor' ),
	realpath( $root_dir . '/node_modules' ),
	realpath( $root_dir . '/development-plan' ),
	realpath( $root_dir . '/research' ),
	realpath( $root_dir . '/bin' ),
);

$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root_dir ) );
$php_files = array();

foreach ( $files as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}

	$real_path = $file->getRealPath();
	$skip = false;
	foreach ( $exclude_dirs as $ex ) {
		if ( $ex && 0 === strpos( $real_path, $ex ) ) {
			$skip = true;
			break;
		}
	}

	if ( ! $skip ) {
		$php_files[] = $real_path;
	}
}

$entries = array(); // msgid => array of references

// Regex matching gettext functions: __, _e, esc_html__, esc_html_e, esc_attr__, esc_attr_e, _x, _ex, _n
// specifically looking for 'gco-stock-sync' text domain
foreach ( $php_files as $filepath ) {
	$relative_path = str_replace( '\\', '/', substr( $filepath, strlen( $root_dir ) + 1 ) );
	$tokens = token_get_all( file_get_contents( $filepath ) );
	$token_count = count( $tokens );

	for ( $i = 0; $i < $token_count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) ) {
			continue;
		}

		$token_name = token_name( $tokens[ $i ][0] );
		$token_val  = $tokens[ $i ][1];
		$line_no    = $tokens[ $i ][2];

		if ( 'T_STRING' === $token_name && in_array( $token_val, array( '__', '_e', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e', '_x', '_ex', '_n' ), true ) ) {
			// Find opening parenthesis
			$j = $i + 1;
			while ( $j < $token_count && ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT ), true ) ) ) {
				$j++;
			}

			if ( $j < $token_count && '(' === $tokens[ $j ] ) {
				// Collect arguments inside call
				$depth = 1;
				$k = $j + 1;
				$call_tokens = array();

				while ( $k < $token_count && $depth > 0 ) {
					if ( '(' === $tokens[ $k ] ) {
						$depth++;
					} elseif ( ')' === $tokens[ $k ] ) {
						$depth--;
						if ( 0 === $depth ) {
							break;
						}
					}
					$call_tokens[] = $tokens[ $k ];
					$k++;
				}

				// Check if domain is 'gco-stock-sync'
				$has_domain = false;
				$strings = array();

				foreach ( $call_tokens as $ct ) {
					if ( is_array( $ct ) && T_CONSTANT_ENCAPSED_STRING === $ct[0] ) {
						$raw_str = trim( $ct[1], "'\"" );
						if ( 'gco-stock-sync' === $raw_str ) {
							$has_domain = true;
						} else {
							// Unescape standard single quote escapes
							$parsed_str = stripcslashes( substr( $ct[1], 1, -1 ) );
							$strings[] = $parsed_str;
						}
					}
				}

				if ( $has_domain && ! empty( $strings ) ) {
					$msgid = $strings[0];
					if ( ! isset( $entries[ $msgid ] ) ) {
						$entries[ $msgid ] = array();
					}
					$ref = "{$relative_path}:{$line_no}";
					if ( ! in_array( $ref, $entries[ $msgid ], true ) ) {
						$entries[ $msgid ][] = $ref;
					}
				}
			}
		}
	}
}

// Write POT
$now = date( 'Y-m-d H:iO' );
$header = <<<POT
#, fuzzy
msgid ""
msgstr ""
"Project-Id-Version: GCO Supplier Stock Sync 1.0.0\\n"
"Report-Msgid-Bugs-To: \\n"
"POT-Creation-Date: {$now}\\n"
"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"
"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n"
"Language-Team: LANGUAGE <LL@li.org>\\n"
"Language: \\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"X-Generator: GCO POT Generator 1.0.0\\n"
"X-Domain: gco-stock-sync\\n"

POT;

$out = $header . "\n";

foreach ( $entries as $msgid => $refs ) {
	$ref_line = '#: ' . implode( ' ', $refs );
	$escaped_msgid = addcslashes( $msgid, "\"\\\n\r\t" );
	// Format multiline if newline present
	if ( strpos( $escaped_msgid, '\n' ) !== false ) {
		$parts = explode( '\n', $escaped_msgid );
		$msgid_formatted = "\"\"\n";
		$c = count( $parts );
		for ( $idx = 0; $idx < $c; $idx++ ) {
			$nl = ( $idx < $c - 1 ) ? '\n' : '';
			$msgid_formatted .= '"' . $parts[ $idx ] . $nl . "\"\n";
		}
	} else {
		$msgid_formatted = '"' . $escaped_msgid . "\"\n";
	}

	$out .= "{$ref_line}\n";
	$out .= "msgid {$msgid_formatted}";
	$out .= "msgstr \"\"\n\n";
}

file_put_contents( $pot_file, $out );
echo "POT file generated successfully with " . count( $entries ) . " entries at {$pot_file}\n";
