<?php
/**
 * Standalone tests for the per-client rate limit.
 *
 * Run: php tests/rate-limit.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['aicfab_transients'] = array();
$GLOBALS['aicfab_ttls']       = array();
$GLOBALS['aicfab_user']       = 0;
$GLOBALS['aicfab_filters']    = array();
$_SERVER['REMOTE_ADDR']       = '10.0.0.1';

function get_transient( $key ) {
	return isset( $GLOBALS['aicfab_transients'][ $key ] ) ? $GLOBALS['aicfab_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['aicfab_transients'][ $key ] = $value;
	$GLOBALS['aicfab_ttls'][ $key ][]     = $ttl;
	return true;
}
function is_user_logged_in() {
	return $GLOBALS['aicfab_user'] > 0;
}
function get_current_user_id() {
	return $GLOBALS['aicfab_user'];
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function sanitize_text_field( $value ) {
	return trim( (string) $value );
}
function wp_unslash( $value ) {
	return $value;
}
function wp_salt( $scheme = 'auth' ) {
	return 'salt-' . $scheme;
}
function add_filter( $hook, $callback ) {
	$GLOBALS['aicfab_filters'][ $hook ][] = $callback;
}
function apply_filters( $hook, $value ) {
	foreach ( isset( $GLOBALS['aicfab_filters'][ $hook ] ) ? $GLOBALS['aicfab_filters'][ $hook ] : array() as $callback ) {
		$value = $callback( $value );
	}
	return $value;
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';

$failures = array();
function check_rl( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

// Keep the whole run inside one window, so a boundary crossing mid-test cannot flip a result.
if ( time() % 60 > 55 ) {
	sleep( 5 );
}

$allowed = 0;
for ( $i = 0; $i < 5; $i++ ) {
	$allowed += AI_Chat_Bedrock_Security::check_rate_limit( 'chat', 3 ) ? 1 : 0;
}
check_rl( 3 === $allowed, 'Only the limit is allowed within a window: ' . $allowed );
check_rl( AI_Chat_Bedrock_Security::check_rate_limit( 'mcp', 3 ), 'Buckets are counted separately.' );

$keys = array_keys( $GLOBALS['aicfab_transients'] );
check_rl( 2 === count( $keys ), 'One counter per bucket and client.' );
check_rl( 3 === count( $GLOBALS['aicfab_ttls'][ $keys[0] ] ), 'Refused requests do not touch the counter.' );
foreach ( $keys as $key ) {
	check_rl( 0 === strpos( $key, 'aicfab_rl_' ) && strlen( $key ) <= 172, 'The transient key is short enough for the options table.' );
	check_rl( false === strpos( $key, '10.0.0.1' ), 'The address is not stored in the key.' );
}

// The window is part of the key, so the count resets when the window turns over.
$window_key = 'aicfab_rl_' . md5( 'chat|60|' . ( (int) floor( time() / 60 ) + 1 ) . '|guest:' . hash_hmac( 'sha256', '10.0.0.1', 'salt-nonce' ) );
check_rl( ! isset( $GLOBALS['aicfab_transients'][ $window_key ] ), 'The next window starts from zero.' );

// Another guest has a limit of their own.
$_SERVER['REMOTE_ADDR'] = '10.0.0.2';
check_rl( AI_Chat_Bedrock_Security::check_rate_limit( 'chat', 3 ), 'Another address has its own count.' );

// Behind a proxy every guest shares its address, until a site says which header to trust.
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
add_filter(
	'ai_chat_bedrock_client_ip',
	function () {
		return '203.0.113.9';
	}
);
check_rl( AI_Chat_Bedrock_Security::check_rate_limit( 'chat', 3 ), 'The client address filter separates guests behind a proxy.' );
$GLOBALS['aicfab_filters'] = array();

// A signed-in user is counted by account, wherever they connect from.
$GLOBALS['aicfab_user'] = 5;
check_rl( AI_Chat_Bedrock_Security::check_rate_limit( 'chat', 3 ), 'A signed-in user is not limited by the guest count of their address.' );
$_SERVER['REMOTE_ADDR'] = '10.9.9.9';
AI_Chat_Bedrock_Security::check_rate_limit( 'chat', 3 );
AI_Chat_Bedrock_Security::check_rate_limit( 'chat', 3 );
check_rl( ! AI_Chat_Bedrock_Security::check_rate_limit( 'chat', 3 ), 'Changing address does not reset a user\'s count.' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: rate limit checks passed\n";
