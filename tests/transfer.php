<?php
/**
 * Standalone tests for moving a configuration between sites.
 *
 * The property that matters most is negative: no credential may ever reach the file. AWS
 * keys and MCP tokens are encrypted with this site's salts, so exporting them would be
 * both useless elsewhere and a liability in an email attachment.
 *
 * Run: php tests/transfer.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'AI_CHAT_BEDROCK_VERSION', '1.28.0' );

$GLOBALS['aicfab_options'] = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_options'] ) ? $GLOBALS['aicfab_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['aicfab_options'][ $name ] = $value;
	return true;
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function sanitize_text_field( $value ) {
	return trim( preg_replace( '/<[^>]*>/', '', (string) $value ) );
}
function esc_url_raw( $url ) {
	return (string) $url;
}
function absint( $value ) {
	return abs( (int) $value );
}
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}
function __( $text, $domain = null ) {
	return $text;
}

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

class AI_Chat_Bedrock_Security {
	public static function is_safe_mcp_url( $url ) {
		return 0 === strpos( (string) $url, 'https://' ) && false === strpos( (string) $url, 'localhost' );
	}
}
class AI_Chat_Bedrock_Profiles {
	public static $saved = array();
	public static function save( $key, $profile ) {
		self::$saved[ $key ] = $profile;
		return true;
	}
}
class AI_Chat_Bedrock_Rate_Limits {
	public static $saved = null;
	public static function save( $limits ) {
		self::$saved = $limits;
		return $limits;
	}
}
class AI_Chat_Bedrock_Admin {
	public function __construct( $name = '', $version = '' ) {}
	public function validate_settings( $input ) {
		// Stands in for the real validator: proves the transfer routes values through it.
		unset( $input['_aicfab_fields'] );
		$input['validated'] = 1;
		return $input;
	}
}

require_once __DIR__ . '/../includes/class-ai-chat-bedrock-transfer.php';

$failures = array();

/**
 * Treat a PHP notice or warning as a failure.
 *
 * Unguarded array access is exactly this class of bug: PHP 8 warns, the value degrades to
 * an empty string, and the outcome can still look correct. Without this the suite passed
 * while reaching into a key that was not there.
 */
set_error_handler(
	function ( $severity, $message, $file, $line ) {
		global $failures;
		$failures[] = 'PHP ' . $severity . ': ' . $message . ' at ' . basename( (string) $file ) . ':' . $line;
		return true;
	},
	E_ALL
);

function check_transfer( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

// --- Nothing secret leaves ----------------------------------------------------

$GLOBALS['aicfab_options'] = array(
	'ai_chat_bedrock_settings'    => array(
		'aws_region'        => 'us-east-1',
		'model_id'          => 'a-model',
		'chat_title'        => 'Ask us',
		'aws_access_key'    => 'ENCRYPTED-ACCESS-KEY',
		'aws_secret_key'    => 'ENCRYPTED-SECRET-KEY',
		'aws_session_token' => 'ENCRYPTED-SESSION-TOKEN',
	),
	'ai_chat_bedrock_mcp_servers' => array(
		'partner' => array(
			'url'  => 'https://mcp.example.com/mcp',
			'auth' => array(
				'type'    => 'bearer',
				'token'   => 'ENCRYPTED-BEARER-TOKEN',
				'service' => '',
				'region'  => '',
			),
		),
	),
	'ai_chat_bedrock_profiles'    => array( 'docs' => array( 'key' => 'docs', 'label' => 'Docs' ) ),
	'ai_chat_bedrock_enable_mcp'  => 1,
	'ai_chat_bedrock_oauth_clients' => array( 'secret' => 'CLIENT-SECRET' ),
	'ai_chat_bedrock_conversations' => array( array( 'question' => 'what a visitor typed' ) ),
	'ai_chat_bedrock_usage'         => array( 'requests' => 5 ),
);

$export = AI_Chat_Bedrock_Transfer::export();
$json   = wp_json_encode( $export );

foreach ( array( 'ENCRYPTED-ACCESS-KEY', 'ENCRYPTED-SECRET-KEY', 'ENCRYPTED-SESSION-TOKEN', 'ENCRYPTED-BEARER-TOKEN', 'CLIENT-SECRET' ) as $secret ) {
	check_transfer( false === strpos( $json, $secret ), "the file does not contain $secret" );
}
foreach ( AI_Chat_Bedrock_Transfer::credential_keys() as $key ) {
	check_transfer( ! isset( $export['options']['ai_chat_bedrock_settings'][ $key ] ), "the settings in the file have no $key" );
}
check_transfer(
	'' === $export['options']['ai_chat_bedrock_mcp_servers']['partner']['auth']['token'],
	'an MCP token is blanked rather than exported'
);
check_transfer(
	'https://mcp.example.com/mcp' === $export['options']['ai_chat_bedrock_mcp_servers']['partner']['url'],
	'the server URL still travels, since it is not a secret'
);

// What a visitor typed, and who is connected, are not configuration.
check_transfer( ! isset( $export['options']['ai_chat_bedrock_conversations'] ), 'conversations are not exported' );
check_transfer( ! isset( $export['options']['ai_chat_bedrock_usage'] ), 'usage counters are not exported' );
check_transfer( ! isset( $export['options']['ai_chat_bedrock_oauth_clients'] ), 'OAuth clients are not exported' );

// The file says what it withheld, so nobody assumes credentials came along.
check_transfer( in_array( 'aws_access_key', $export['excluded'], true ), 'the file records that the access key was withheld' );
check_transfer( in_array( 'mcp token for partner', $export['excluded'], true ), 'the file records that an MCP token was withheld' );

check_transfer( isset( $export['options']['ai_chat_bedrock_settings'] ), 'settings do travel' );
check_transfer( isset( $export['options']['ai_chat_bedrock_profiles'] ), 'profiles do travel' );
check_transfer( 'Ask us' === $export['options']['ai_chat_bedrock_settings']['chat_title'], 'ordinary settings travel unchanged' );

// --- Only an allowlist is written --------------------------------------------

// An import must never be a way to write arbitrary options.
$result = AI_Chat_Bedrock_Transfer::import(
	wp_json_encode(
		array(
			'plugin'  => 'ai-chat-for-amazon-bedrock',
			'format'  => AI_Chat_Bedrock_Transfer::FORMAT,
			'options' => array(
				'wp_user_roles'                 => array( 'administrator' => array( 'capabilities' => array( 'manage_options' => true ) ) ),
				'active_plugins'                => array( 'evil/evil.php' ),
				'ai_chat_bedrock_oauth_clients' => array( 'x' => 'y' ),
				'ai_chat_bedrock_enable_mcp'    => 1,
			),
		)
	)
);
check_transfer( ! is_wp_error( $result ), 'a file with unknown options is still processed' );
check_transfer( array( 'ai_chat_bedrock_enable_mcp' ) === $result['applied'], 'only allowlisted options are applied, got ' . wp_json_encode( $result['applied'] ) );
check_transfer( ! isset( $GLOBALS['aicfab_options']['wp_user_roles'] ), 'roles are not written' );
check_transfer( ! isset( $GLOBALS['aicfab_options']['active_plugins'] ), 'the plugin list is not written' );
check_transfer( in_array( 'wp_user_roles', $result['skipped'], true ), 'the skipped options are reported' );
check_transfer( in_array( 'ai_chat_bedrock_oauth_clients', $result['skipped'], true ), 'OAuth state cannot be imported either' );

// --- Credentials already here are kept ---------------------------------------

$GLOBALS['aicfab_options']['ai_chat_bedrock_settings'] = array(
	'chat_title'     => 'Before',
	'aws_access_key' => 'LOCAL-KEY',
	'aws_secret_key' => 'LOCAL-SECRET',
);
AI_Chat_Bedrock_Transfer::import(
	wp_json_encode(
		array(
			'plugin'  => 'ai-chat-for-amazon-bedrock',
			'format'  => AI_Chat_Bedrock_Transfer::FORMAT,
			'options' => array( 'ai_chat_bedrock_settings' => array( 'chat_title' => 'After' ) ),
		)
	)
);
$stored = get_option( 'ai_chat_bedrock_settings' );
check_transfer( 'After' === $stored['chat_title'], 'the imported value is applied' );
check_transfer( 'LOCAL-KEY' === $stored['aws_access_key'], 'a credential already configured here survives an import' );
check_transfer( 'LOCAL-SECRET' === $stored['aws_secret_key'], 'so does the secret' );
check_transfer( isset( $stored['validated'] ), 'settings go through the validator rather than being written raw' );

// --- Files that must be refused ----------------------------------------------

foreach ( array(
	'garbage'                                                                              => 'aicfab_import_invalid',
	'{"plugin":"something-else","format":1,"options":{"a":1}}'                              => 'aicfab_import_foreign',
	'{"plugin":"ai-chat-for-amazon-bedrock","format":99,"options":{"a":1}}'                 => 'aicfab_import_format',
	'{"plugin":"ai-chat-for-amazon-bedrock","format":1,"options":[]}'                       => 'aicfab_import_empty',
) as $body => $expected ) {
	$refused = AI_Chat_Bedrock_Transfer::import( $body );
	check_transfer( is_wp_error( $refused ), 'a bad file is refused: ' . substr( $body, 0, 30 ) );
	check_transfer( is_wp_error( $refused ) && $expected === $refused->get_error_code(), "the refusal is $expected, got " . ( is_wp_error( $refused ) ? $refused->get_error_code() : 'success' ) );
}

// --- An MCP server that is not safe is dropped -------------------------------

AI_Chat_Bedrock_Transfer::import(
	wp_json_encode(
		array(
			'plugin'  => 'ai-chat-for-amazon-bedrock',
			'format'  => AI_Chat_Bedrock_Transfer::FORMAT,
			'options' => array(
				'ai_chat_bedrock_mcp_servers' => array(
					'good' => array( 'url' => 'https://mcp.example.com/mcp', 'auth' => array( 'type' => 'bearer', 'token' => 'SHOULD-NOT-LAND' ) ),
					'bad'  => array( 'url' => 'http://insecure.example.com/mcp' ),
					'local' => array( 'url' => 'https://localhost/mcp' ),
				),
			),
		)
	)
);
$servers = get_option( 'ai_chat_bedrock_mcp_servers' );
check_transfer( isset( $servers['good'] ), 'an HTTPS server is imported' );
check_transfer( ! isset( $servers['bad'] ), 'a plain HTTP server is refused' );
check_transfer( ! isset( $servers['local'] ), 'a loopback server is refused' );
check_transfer( '' === $servers['good']['auth']['token'], 'a token in the file is never written, even if someone puts one there' );

// A file may omit the auth block. Reaching into it unguarded produced a warning and a null
// auth type, so this covers a server with no auth at all.
AI_Chat_Bedrock_Transfer::import(
	wp_json_encode(
		array(
			'plugin'  => 'ai-chat-for-amazon-bedrock',
			'format'  => AI_Chat_Bedrock_Transfer::FORMAT,
			'options' => array(
				'ai_chat_bedrock_mcp_servers' => array( 'plain' => array( 'url' => 'https://plain.example.com/mcp' ) ),
			),
		)
	)
);
$plain = get_option( 'ai_chat_bedrock_mcp_servers' );
check_transfer( isset( $plain['plain'] ), 'a server without an auth block is imported' );
check_transfer( 'none' === ( $plain['plain']['auth']['type'] ?? null ), 'its auth type defaults to none rather than null' );


if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: configuration transfer checks passed\n";
