<?php
/**
 * Behavioral tests: wp ai-chat-bedrock usage ends with a Time to first text line.
 *
 * Run: php tests/test_feature_fe5052185c45.php
 *
 * All counters are synthetic and seeded into a stubbed option. No WordPress, WP-CLI or
 * Amazon Bedrock is involved. Any PHP warning or notice fails the case it occurs in.
 *
 * @package AI_Chat_Bedrock
 */

namespace WP_CLI\Utils {
	function format_items( $format, $items, $fields ) {
		\WP_CLI::$out[] = 'TABLE ' . $format . ' ' . implode( ',', $fields ) . ' ' . json_encode( $items );
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 );
	}

	$GLOBALS['aicfab_ft_options'] = array();

	function get_option( $name, $default_value = false ) {
		return array_key_exists( $name, $GLOBALS['aicfab_ft_options'] ) ? $GLOBALS['aicfab_ft_options'][ $name ] : $default_value;
	}
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['aicfab_ft_options'][ $name ] = $value;
		return true;
	}
	function delete_option( $name ) {
		unset( $GLOBALS['aicfab_ft_options'][ $name ] );
		return true;
	}
	function absint( $value ) {
		return abs( (int) $value );
	}
	function apply_filters( $hook, $value ) {
		return $value;
	}

	class Aicfab_Ft_Cli_Error extends Exception {
	}

	class WP_CLI {
		public static $out = array();
		public static function add_command( $name, $class_name ) {
		}
		public static function log( $message = '' ) {
			self::$out[] = (string) $message;
		}
		public static function line( $message = '' ) {
			self::$out[] = (string) $message;
		}
		public static function success( $message = '' ) {
			self::$out[] = 'Success: ' . $message;
		}
		public static function warning( $message = '' ) {
			self::$out[] = 'Warning: ' . $message;
		}
		public static function error( $message = '' ) {
			throw new Aicfab_Ft_Cli_Error( (string) $message );
		}
		public static function halt( $code ) {
			throw new Aicfab_Ft_Cli_Error( 'halt ' . (int) $code );
		}
	}

	require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-usage.php';
	require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-cli.php';

	set_error_handler(
		static function ( $errno, $errstr, $errfile, $errline ) {
			throw new ErrorException( 'PHP notice or warning: ' . $errstr, 0, $errno, $errfile, $errline );
		}
	);

	$aicfab_ft_failures = 0;

	function aicfab_ft_case( $name, callable $test ) {
		global $aicfab_ft_failures;
		$GLOBALS['aicfab_ft_options'] = array();
		try {
			$test();
			echo 'ok   ' . $name . "\n";
		} catch ( Throwable $e ) {
			++$aicfab_ft_failures;
			echo 'FAIL ' . $name . ': ' . $e->getMessage() . "\n";
		}
	}

	function aicfab_ft_same( $expected, $actual, $what ) {
		if ( $expected !== $actual ) {
			throw new RuntimeException( $what . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
		}
	}

	function aicfab_ft_true( $condition, $what ) {
		if ( ! $condition ) {
			throw new RuntimeException( $what );
		}
	}

	/**
	 * Store one synthetic UTC day of counters.
	 */
	function aicfab_ft_seed( $first_token, $extra = array(), $days_ago = 0 ) {
		$entry = array_merge(
			array(
				'requests'      => 3,
				'input_tokens'  => 120,
				'output_tokens' => 45,
				'models'        => array(),
			),
			$extra
		);
		if ( null !== $first_token ) {
			$entry['first_token'] = $first_token;
		}
		$day = gmdate( 'Y-m-d', time() - ( $days_ago * DAY_IN_SECONDS ) );

		$GLOBALS['aicfab_ft_options'][ AI_Chat_Bedrock_Usage::OPTION ][ $day ] = $entry;
	}

	function aicfab_ft_run( $assoc_args = array() ) {
		WP_CLI::$out = array();
		$cli         = new AI_Chat_Bedrock_CLI();
		$cli->usage( array(), $assoc_args );
		return WP_CLI::$out;
	}

	function aicfab_ft_last( $out ) {
		return empty( $out ) ? null : $out[ count( $out ) - 1 ];
	}

	const AICFAB_FT_UNKNOWN_7 = 'Time to first text (streamed, 7 days): unknown, no streamed answers recorded.';

	if ( ! function_exists( 'delete_transient' ) ) {
		function delete_transient( $name ) {
			return true;
		}
	}

	function aicfab_ft_assert_unknown( $out, $what ) {
		aicfab_ft_same( AICFAB_FT_UNKNOWN_7, aicfab_ft_last( $out ), $what . ' last line' );
		aicfab_ft_true( false === strpos( implode( "\n", $out ), '0 ms' ), $what . ': output must not show 0 ms' );
	}

	aicfab_ft_case(
		'measured counters end the output with count, average and median band',
		static function () {
			aicfab_ft_seed( array( 'count' => 3, 'sum_ms' => 4500, 'lt1s' => 1, '1to2s' => 2 ) );
			aicfab_ft_same( 'Time to first text (streamed, 7 days): 3 answers, average 1500 ms, median 1 to 2 s.', aicfab_ft_last( aicfab_ft_run() ), 'last line' );
		}
	);

	aicfab_ft_case(
		'a single sample reads 1 answer',
		static function () {
			aicfab_ft_seed( array( 'count' => 1, 'sum_ms' => 800, 'lt1s' => 1 ) );
			aicfab_ft_same( 'Time to first text (streamed, 7 days): 1 answer, average 800 ms, median under 1 s.', aicfab_ft_last( aicfab_ft_run() ), 'last line' );
		}
	);

	aicfab_ft_case(
		'the slowest band is labelled 10 s and over',
		static function () {
			aicfab_ft_seed( array( 'count' => 1, 'sum_ms' => 12000, 'gte10s' => 1 ) );
			aicfab_ft_same( 'Time to first text (streamed, 7 days): 1 answer, average 12000 ms, median 10 s and over.', aicfab_ft_last( aicfab_ft_run() ), 'last line' );
		}
	);

	aicfab_ft_case(
		'no stored usage at all reads unknown',
		static function () {
			aicfab_ft_assert_unknown( aicfab_ft_run(), 'empty option' );
		}
	);

	aicfab_ft_case(
		'a day without first_token counters reads unknown',
		static function () {
			aicfab_ft_seed( null );
			aicfab_ft_assert_unknown( aicfab_ft_run(), 'no first_token' );
		}
	);

	aicfab_ft_case(
		'after a reset the line reads unknown',
		static function () {
			aicfab_ft_seed( array( 'count' => 3, 'sum_ms' => 4500, 'lt1s' => 1, '1to2s' => 2 ) );
			AI_Chat_Bedrock_Usage::reset();
			aicfab_ft_assert_unknown( aicfab_ft_run(), 'after reset' );
		}
	);

	aicfab_ft_case(
		'a non-numeric, zero or missing count reads unknown',
		static function () {
			aicfab_ft_seed( array( 'count' => 'many', 'sum_ms' => 4500, 'lt1s' => 3 ) );
			aicfab_ft_assert_unknown( aicfab_ft_run(), 'non-numeric count' );
			$GLOBALS['aicfab_ft_options'] = array();
			aicfab_ft_seed( array( 'count' => 0, 'sum_ms' => 0 ) );
			aicfab_ft_assert_unknown( aicfab_ft_run(), 'zero count' );
			$GLOBALS['aicfab_ft_options'] = array();
			aicfab_ft_seed( array( 'sum_ms' => 4500, 'lt1s' => 3 ) );
			aicfab_ft_assert_unknown( aicfab_ft_run(), 'missing count' );
			$GLOBALS['aicfab_ft_options'] = array();
			aicfab_ft_seed( 'not-a-map' );
			aicfab_ft_assert_unknown( aicfab_ft_run(), 'non-array first_token' );
		}
	);

	aicfab_ft_case(
		'a missing or invalid sum reads average unknown, never 0 ms',
		static function () {
			$expected = 'Time to first text (streamed, 7 days): 2 answers, average unknown, median under 1 s.';
			aicfab_ft_seed( array( 'count' => 2, 'lt1s' => 2 ) );
			aicfab_ft_same( $expected, aicfab_ft_last( aicfab_ft_run() ), 'missing sum_ms' );
			$GLOBALS['aicfab_ft_options'] = array();
			aicfab_ft_seed( array( 'count' => 2, 'sum_ms' => 'slow', 'lt1s' => 2 ) );
			$out = aicfab_ft_run();
			aicfab_ft_same( $expected, aicfab_ft_last( $out ), 'non-numeric sum_ms' );
			aicfab_ft_true( false === strpos( implode( "\n", $out ), '0 ms' ), 'output must not show 0 ms' );
		}
	);

	aicfab_ft_case(
		'missing or invalid band counters read median unknown',
		static function () {
			$expected = 'Time to first text (streamed, 7 days): 2 answers, average 1500 ms, median unknown.';
			aicfab_ft_seed( array( 'count' => 2, 'sum_ms' => 3000 ) );
			aicfab_ft_same( $expected, aicfab_ft_last( aicfab_ft_run() ), 'missing bands' );
			$GLOBALS['aicfab_ft_options'] = array();
			aicfab_ft_seed( array( 'count' => 2, 'sum_ms' => 3000, 'lt1s' => 'x', '1to2s' => 'y' ) );
			aicfab_ft_same( $expected, aicfab_ft_last( aicfab_ft_run() ), 'non-numeric bands' );
		}
	);

	aicfab_ft_case(
		'--days=1 counts only today and --days=7 includes yesterday',
		static function () {
			aicfab_ft_seed( array( 'count' => 1, 'sum_ms' => 800, 'lt1s' => 1 ) );
			aicfab_ft_seed( array( 'count' => 5, 'sum_ms' => 10000, '2to5s' => 5 ), array(), 1 );
			aicfab_ft_same( 'Time to first text (streamed, 1 day): 1 answer, average 800 ms, median under 1 s.', aicfab_ft_last( aicfab_ft_run( array( 'days' => '1' ) ) ), 'one day' );
			aicfab_ft_same( 'Time to first text (streamed, 7 days): 6 answers, average 1800 ms, median 2 to 5 s.', aicfab_ft_last( aicfab_ft_run() ), 'seven days' );
		}
	);

	aicfab_ft_case(
		'--days above the retention period is capped at 30 days',
		static function () {
			aicfab_ft_seed( array( 'count' => 2, 'sum_ms' => 3000, '1to2s' => 2 ) );
			aicfab_ft_same( 'Time to first text (streamed, 30 days): 2 answers, average 1500 ms, median 1 to 2 s.', aicfab_ft_last( aicfab_ft_run( array( 'days' => '90' ) ) ), 'capped window' );
		}
	);

	aicfab_ft_case(
		'the table and Total line are unchanged and the latency line comes last',
		static function () {
			aicfab_ft_seed( null );
			$without = aicfab_ft_run();
			$GLOBALS['aicfab_ft_options'] = array();
			aicfab_ft_seed( array( 'count' => 3, 'sum_ms' => 4500, 'lt1s' => 1, '1to2s' => 2 ) );
			$with = aicfab_ft_run();

			aicfab_ft_same( 3, count( $without ), 'line count without first-token data' );
			aicfab_ft_true( 0 === strpos( $without[0], 'TABLE table day,requests,input_tokens,output_tokens ' ), 'per-day table comes first' );
			aicfab_ft_same( 'Total: 3 requests, 120 input tokens, 45 output tokens.', $without[1], 'Total line' );
			aicfab_ft_same( array_slice( $without, 0, -1 ), array_slice( $with, 0, -1 ), 'lines before the latency line' );
			foreach ( array_slice( $with, 0, -1 ) as $line ) {
				aicfab_ft_true( false === strpos( $line, 'Time to first text' ), 'only the last line mentions latency' );
			}
		}
	);

	aicfab_ft_case(
		'--by-model prints no latency line',
		static function () {
			aicfab_ft_seed(
				array( 'count' => 3, 'sum_ms' => 4500, 'lt1s' => 1, '1to2s' => 2 ),
				array( 'models' => array( 'synthetic.model-v1' => array( 'requests' => 3, 'input_tokens' => 120, 'output_tokens' => 45 ) ) )
			);
			foreach ( aicfab_ft_run( array( 'by-model' => true ) ) as $line ) {
				aicfab_ft_true( false === strpos( $line, 'Time to first text' ), 'by-model output mentions latency: ' . $line );
			}
		}
	);

	aicfab_ft_case(
		'wp help text describes the line, its 30-day limit and the by-model exception',
		static function () {
			$method = new ReflectionMethod( 'AI_Chat_Bedrock_CLI', 'usage' );
			$doc    = preg_replace( '/\s*\n\s*\*\s*/', ' ', (string) $method->getDocComment() );
			aicfab_ft_true( false !== strpos( $doc, 'ends with a Time to first text line for streamed answers' ), 'description mentions the line' );
			aicfab_ft_true( false !== strpos( $doc, 'Time to first text line covers at most 30 days' ), '--days note mentions the 30-day limit' );
			aicfab_ft_true( false !== strpos( $doc, 'leaves out the Time to first text line' ), '--by-model note says the line is left out' );
		}
	);

	echo ( 0 === $aicfab_ft_failures ? 'All first-token CLI cases passed.' : $aicfab_ft_failures . ' case(s) failed.' ) . "\n";
	exit( 0 === $aicfab_ft_failures ? 0 : 1 );
}
