<?php
/**
 * Checks for code that newer PHP versions deprecate, run on every version the gate uses.
 *
 * Run: php tests/php-compat.php
 *
 * @package AI_Chat_Bedrock
 */

$failures = array();
function check_compat( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

$root  = dirname( __DIR__ );
$files = array( $root . '/ai-chat-for-amazon-bedrock.php', $root . '/uninstall.php' );
foreach ( array( 'includes', 'admin', 'public', 'blocks' ) as $dir ) {
	if ( ! is_dir( $root . '/' . $dir ) ) {
		continue;
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( 'php' === $file->getExtension() ) {
			$files[] = $file->getPathname();
		}
	}
}

/**
 * The arguments of each call to a function, as their count, from the tokens.
 *
 * @param array  $tokens   Tokens of a file.
 * @param string $function Function name.
 * @return int[] Argument count of each call, by line.
 */
function compat_calls( $tokens, $function ) {
	$calls = array();
	$count = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] || strtolower( $tokens[ $i ][1] ) !== $function ) {
			continue;
		}
		// A method or a definition of the same name is not the function.
		$before = $i - 1;
		while ( $before >= 0 && is_array( $tokens[ $before ] ) && T_WHITESPACE === $tokens[ $before ][0] ) {
			--$before;
		}
		if ( $before >= 0 && is_array( $tokens[ $before ] ) && in_array( $tokens[ $before ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
			continue;
		}
		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			++$j;
		}
		if ( '(' !== $tokens[ $j ] ) {
			continue;
		}
		$depth = 0;
		$args  = 1;
		for ( $k = $j; $k < $count; $k++ ) {
			$token = $tokens[ $k ];
			if ( in_array( $token, array( '(', '[', '{' ), true ) ) {
				++$depth;
			} elseif ( in_array( $token, array( ')', ']', '}' ), true ) ) {
				--$depth;
				if ( 0 === $depth ) {
					break;
				}
			} elseif ( ',' === $token && 1 === $depth ) {
				++$args;
			}
		}
		$calls[ $tokens[ $i ][2] ] = $args;
	}
	return $calls;
}

/*
 * Since PHP 8.4 leaving out the escape argument of the CSV functions is deprecated, because
 * its default is to change. Where errors are displayed, the notice was written into a CSV
 * download. The escape argument is the fifth of fputcsv and fgetcsv, the fourth of
 * str_getcsv.
 */
$escape_at = array(
	'fputcsv'    => 5,
	'fgetcsv'    => 5,
	'str_getcsv' => 4,
);
$seen      = 0;
foreach ( $files as $path ) {
	$tokens = token_get_all( (string) file_get_contents( $path ) );
	foreach ( $escape_at as $function => $position ) {
		foreach ( compat_calls( $tokens, $function ) as $line => $args ) {
			++$seen;
			check_compat( $args >= $position, substr( $path, strlen( $root ) + 1 ) . ':' . $line . ' passes the escape argument to ' . $function . '().' );
		}
	}
}
check_compat( $seen >= 3, 'The CSV exports are found, so the check is looking at the code.' );

// curl_close() does nothing since PHP 8.0 and is deprecated in 8.5, so it may only run on 7.x.
foreach ( $files as $path ) {
	$source = (string) file_get_contents( $path );
	$tokens = token_get_all( $source );
	foreach ( compat_calls( $tokens, 'curl_close' ) as $line => $args ) {
		$lines = explode( "\n", $source );
		$guard = implode( "\n", array_slice( $lines, max( 0, $line - 3 ), 2 ) );
		check_compat( false !== strpos( $guard, 'PHP_VERSION_ID < 80000' ), substr( $path, strlen( $root ) + 1 ) . ':' . $line . ' calls curl_close() only before PHP 8.0.' );
	}
}

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: PHP compatibility checks passed\n";
