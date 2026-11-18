<?php
/**
 * Behavioral tests: wp ai-chat-bedrock usage labels chat requests whose token usage Bedrock
 * did not fully report.
 *
 * Run: php tests/test_feature_56689b30e4cc.php
 *
 * All usage records and counters are synthetic and kept in a stubbed option. No WordPress,
 * WP-CLI or Amazon Bedrock is involved. Any PHP warning or notice fails the case it occurs in.
 * WP_CLI::error() and halt() throw, so a case that completes ran with exit status 0, and
 * warnings are captured in the output so an empty warning list means nothing went to stderr.
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

	$GLOBALS['aicfab_uu_options'] = array();

	function get_option( $name, $default_value = false ) {
		return array_key_exists( $name, $GLOBALS['aicfab_uu_options'] ) ? $GLOBALS['aicfab_uu_options'][ $name ] : $default_value;
	}
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['aicfab_uu_options'][ $name ] = $value;
		return true;
	}
	function delete_option( $name ) {
		unset( $GLOBALS['aicfab_uu_options'][ $name ] );
		return true;
	}
	function absint( $value ) {
		return abs( (int) $value );
	}
	function apply_filters( $hook, $value ) {
		return $value;
	}
	function delete_transient( $name ) {
		return true;
	}

	class Aicfab_Uu_Cli_Error extends Exception {
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
			throw new Aicfab_Uu_Cli_Error( (string) $message );
		}
		public static function halt( $code ) {
			throw new Aicfab_Uu_Cli_Error( 'halt ' . (int) $code );
		}
	}

	require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-usage.php';
	require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-cli.php';

	set_error_handler(
		static function ( $errno, $errstr, $errfile, $errline ) {
			throw new ErrorException( 'PHP notice or warning: ' . $errstr, 0, $errno, $errfile, $errline );
		}
	);

	$aicfab_uu_failures = 0;

	function aicfab_uu_case( $name, callable $test ) {
		global $aicfab_uu_failures;
		$GLOBALS['aicfab_uu_options'] = array();
		try {
			$test();
			echo 'ok   ' . $name . "\n";
		} catch ( Throwable $e ) {
			++$aicfab_uu_failures;
			echo 'FAIL ' . $name . ': ' . $e->getMessage() . "\n";
		}
	}

	function aicfab_uu_same( $expected, $actual, $what ) {
		if ( $expected !== $actual ) {
			throw new RuntimeException( $what . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
		}
	}

	function aicfab_uu_true( $condition, $what ) {
		if ( ! $condition ) {
			throw new RuntimeException( $what );
		}
	}

	/**
	 * Store one synthetic UTC day of counters.
	 */
	function aicfab_uu_seed( $extra = array(), $days_ago = 0 ) {
		$entry = array_merge(
			array(
				'requests'      => 3,
				'input_tokens'  => 120,
				'output_tokens' => 45,
				'models'        => array(),
			),
			$extra
		);
		$day   = gmdate( 'Y-m-d', time() - ( $days_ago * DAY_IN_SECONDS ) );

		$GLOBALS['aicfab_uu_options'][ AI_Chat_Bedrock_Usage::OPTION ][ $day ] = $entry;
	}

	function aicfab_uu_run( $assoc_args = array() ) {
		WP_CLI::$out = array();
		$cli         = new AI_Chat_Bedrock_CLI();
		$cli->usage( array(), $assoc_args );
		return WP_CLI::$out;
	}

	function aicfab_uu_index( $out, $prefix ) {
		foreach ( $out as $i => $line ) {
			if ( 0 === strpos( $line, $prefix ) ) {
				return $i;
			}
		}
		return -1;
	}

	function aicfab_uu_lines_with( $out, $prefix ) {
		$found = array();
		foreach ( $out as $line ) {
			if ( 0 === strpos( $line, $prefix ) ) {
				$found[] = $line;
			}
		}
		return $found;
	}

	function aicfab_uu_expected( $count_text ) {
		return 'Token usage unknown for ' . $count_text . ' in this window: Bedrock did not report complete token counts for them, so the token totals above are a lower bound.';
	}

	function aicfab_uu_unknown_count( $days = 7 ) {
		$totals = AI_Chat_Bedrock_Usage::totals( $days );
		aicfab_uu_true( array_key_exists( 'usage_unknown_requests', $totals ), 'totals() has no usage_unknown_requests key' );
		return $totals['usage_unknown_requests'];
	}

	function aicfab_uu_assert_no_line( $out, $what ) {
		aicfab_uu_same( array(), aicfab_uu_lines_with( $out, 'Token usage unknown' ), $what . ': no unknown line' );
	}

	function aicfab_uu_assert_line_after_total( $out, $expected ) {
		$total = aicfab_uu_index( $out, 'Total:' );
		$line  = aicfab_uu_index( $out, 'Token usage unknown' );
		$first = aicfab_uu_index( $out, 'Time to first text' );
		aicfab_uu_true( $total >= 0, 'Total line printed' );
		aicfab_uu_same( $total + 1, $line, 'unknown line directly after Total' );
		aicfab_uu_true( $first > $line, 'unknown line before Time to first text' );
		aicfab_uu_same( array( $expected ), aicfab_uu_lines_with( $out, 'Token usage unknown' ), 'unknown line text' );
		aicfab_uu_same( array(), aicfab_uu_lines_with( $out, 'Warning:' ), 'nothing written as a warning' );
		return $out[ $line ];
	}

	aicfab_uu_case(
		'record() counts chat answers with missing or non-numeric token counts and keeps the other totals',
		static function () {
			$model = 'amazon.nova-lite-v1:0';
			AI_Chat_Bedrock_Usage::record( array(), $model );
			AI_Chat_Bedrock_Usage::record( array( 'input_tokens' => 5 ), $model );
			AI_Chat_Bedrock_Usage::record( array( 'input_tokens' => 'n/a', 'output_tokens' => 2 ), $model );

			aicfab_uu_same( 3, aicfab_uu_unknown_count(), 'unknown requests' );
			$totals = AI_Chat_Bedrock_Usage::totals( 7 );
			aicfab_uu_same( 3, (int) $totals['requests'], 'requests' );
			aicfab_uu_same( 5, (int) $totals['input_tokens'], 'input tokens' );
			aicfab_uu_same( 2, (int) $totals['output_tokens'], 'output tokens' );

			$rows = AI_Chat_Bedrock_Usage::by_model( 7 );
			aicfab_uu_same( 1, count( $rows ), 'one model row' );
			aicfab_uu_same( array( 3, 5, 2 ), array( (int) $rows[0]['requests'], (int) $rows[0]['input_tokens'], (int) $rows[0]['output_tokens'] ), 'model row' );
		}
	);

	aicfab_uu_case(
		'requests_today() still counts every chat answer, known or unknown',
		static function () {
			AI_Chat_Bedrock_Usage::record( array() );
			AI_Chat_Bedrock_Usage::record( array( 'input_tokens' => 1, 'output_tokens' => 1 ) );
			aicfab_uu_same( 2, (int) AI_Chat_Bedrock_Usage::requests_today(), 'requests today' );
		}
	);

	aicfab_uu_case(
		'complete counts, including a real 0, and embedding, rerank and speech records add nothing',
		static function () {
			AI_Chat_Bedrock_Usage::record( array( 'input_tokens' => 0, 'output_tokens' => 0 ), 'amazon.nova-lite-v1:0' );
			AI_Chat_Bedrock_Usage::record( array( 'input_tokens' => '7', 'output_tokens' => '3' ), 'amazon.nova-lite-v1:0' );
			AI_Chat_Bedrock_Usage::record( array(), 'amazon.titan-embed-text-v2:0', 'embedding' );
			AI_Chat_Bedrock_Usage::record( array(), 'cohere.rerank-v3-5:0', 'rerank' );
			AI_Chat_Bedrock_Usage::record( array(), 'polly', 'speech' );

			aicfab_uu_same( 0, aicfab_uu_unknown_count(), 'unknown requests' );
			$totals = AI_Chat_Bedrock_Usage::totals( 7 );
			aicfab_uu_same( 2, (int) $totals['requests'], 'chat requests' );
			aicfab_uu_same( 7, (int) $totals['input_tokens'], 'input tokens' );
			aicfab_uu_same( 3, (int) $totals['output_tokens'], 'output tokens' );

			$stored = get_option( AI_Chat_Bedrock_Usage::OPTION );
			aicfab_uu_true( ! isset( $stored[ gmdate( 'Y-m-d' ) ]['usage_unknown_requests'] ), 'no counter stored for complete counts' );
		}
	);

	aicfab_uu_case(
		'the stored counter survives prune() and holds no visitor or message data',
		static function () {
			AI_Chat_Bedrock_Usage::record( array(), 'amazon.nova-lite-v1:0' );
			AI_Chat_Bedrock_Usage::record( array(), 'amazon.nova-lite-v1:0' );
			$stored = get_option( AI_Chat_Bedrock_Usage::OPTION );
			aicfab_uu_same( 2, $stored[ gmdate( 'Y-m-d' ) ]['usage_unknown_requests'], 'stored counter after two writes' );

			AI_Chat_Bedrock_Usage::record( 'not an array' );
			$stored = get_option( AI_Chat_Bedrock_Usage::OPTION );
			aicfab_uu_same( 3, $stored[ gmdate( 'Y-m-d' ) ]['usage_unknown_requests'], 'non-array usage counts as unknown' );

			$json = strtolower( (string) json_encode( $stored ) );
			foreach ( array( 'question', 'answer', 'user', 'ip', 'prompt' ) as $word ) {
				aicfab_uu_true( false === strpos( $json, $word ), 'stored option contains ' . $word );
			}
		}
	);

	aicfab_uu_case(
		'usage prints the unknown count directly after Total',
		static function () {
			aicfab_uu_seed(
				array(
					'requests'               => 5,
					'usage_unknown_requests' => 2,
					'models'                 => array(
						'amazon.nova-lite-v1:0' => array(
							'requests'      => 5,
							'input_tokens'  => 120,
							'output_tokens' => 45,
						),
					),
				)
			);
			$line = aicfab_uu_assert_line_after_total( aicfab_uu_run( array( 'days' => '7' ) ), aicfab_uu_expected( '2 requests' ) );
			aicfab_uu_true( false === strpos( $line, 'nova' ), 'line contains no model ID' );
		}
	);

	aicfab_uu_case(
		'one unknown request reads singular, and a counter above requests is clamped',
		static function () {
			aicfab_uu_seed( array( 'usage_unknown_requests' => 1 ) );
			aicfab_uu_assert_line_after_total( aicfab_uu_run( array( 'days' => '7' ) ), aicfab_uu_expected( '1 request' ) );

			$GLOBALS['aicfab_uu_options'] = array();
			aicfab_uu_seed(
				array(
					'requests'               => 4,
					'usage_unknown_requests' => 9,
				)
			);
			aicfab_uu_assert_line_after_total( aicfab_uu_run( array( 'days' => '7' ) ), aicfab_uu_expected( '4 requests' ) );
		}
	);

	aicfab_uu_case(
		'a window with pre-upgrade days does not present them as measured',
		static function () {
			aicfab_uu_seed( array( 'requests' => 480 ), 1 );
			aicfab_uu_seed(
				array(
					'requests'               => 20,
					'usage_unknown_requests' => 2,
				)
			);
			$out = aicfab_uu_run( array( 'days' => '7' ) );
			aicfab_uu_same( 1, count( aicfab_uu_lines_with( $out, 'Total: 500 requests,' ) ), 'Total line covers the whole window' );
			$line = aicfab_uu_assert_line_after_total( $out, aicfab_uu_expected( '2 requests' ) );
			foreach ( array( ' of ', '500', '20' ) as $text ) {
				aicfab_uu_true( false === strpos( $line, $text ), 'unknown line contains ' . $text );
			}
		}
	);

	aicfab_uu_case(
		'no unknown line when there is nothing to report or in --by-model output',
		static function () {
			aicfab_uu_seed();
			$out = aicfab_uu_run( array( 'days' => '7' ) );
			aicfab_uu_true( aicfab_uu_index( $out, 'Total:' ) >= 0, 'Total line printed' );
			aicfab_uu_assert_no_line( $out, 'no counter' );
			aicfab_uu_same( 0, aicfab_uu_unknown_count(), 'no counter totals 0' );

			foreach ( array( 0, 'abc', -3 ) as $bad ) {
				$GLOBALS['aicfab_uu_options'] = array();
				aicfab_uu_seed( array( 'usage_unknown_requests' => $bad ) );
				aicfab_uu_assert_no_line( aicfab_uu_run( array( 'days' => '7' ) ), 'counter ' . var_export( $bad, true ) );
				aicfab_uu_same( 0, aicfab_uu_unknown_count(), 'counter ' . var_export( $bad, true ) . ' totals 0' );
			}

			$GLOBALS['aicfab_uu_options'] = array();
			aicfab_uu_seed(
				array(
					'requests'               => 0,
					'usage_unknown_requests' => 2,
				)
			);
			aicfab_uu_assert_no_line( aicfab_uu_run( array( 'days' => '7' ) ), 'zero requests' );

			$GLOBALS['aicfab_uu_options'] = array();
			aicfab_uu_seed( array( 'usage_unknown_requests' => 2 ), 10 );
			aicfab_uu_assert_no_line( aicfab_uu_run( array( 'days' => '7' ) ), 'counter outside the window' );

			$GLOBALS['aicfab_uu_options'] = array();
			aicfab_uu_seed(
				array(
					'usage_unknown_requests' => 2,
					'models'                 => array(
						'amazon.nova-lite-v1:0' => array(
							'requests'      => 3,
							'input_tokens'  => 120,
							'output_tokens' => 45,
						),
					),
				)
			);
			$out = aicfab_uu_run(
				array(
					'days'     => '7',
					'by-model' => true,
				)
			);
			aicfab_uu_true( 1 === count( aicfab_uu_lines_with( $out, 'TABLE ' ) ), 'by-model table printed' );
			aicfab_uu_assert_no_line( $out, 'by-model' );
		}
	);

	echo ( 0 === $aicfab_uu_failures ? 'All cases passed.' : $aicfab_uu_failures . ' case(s) failed.' ) . "\n";
	exit( 0 === $aicfab_uu_failures ? 0 : 1 );
}
