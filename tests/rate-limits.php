<?php
/**
 * Standalone tests for per-role request limits.
 *
 * Run: php tests/rate-limits.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['aicfab_options']   = array();
$GLOBALS['aicfab_logged_in'] = false;
$GLOBALS['aicfab_user_roles'] = array();

// --- WordPress stubs -------------------------------------------------------

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
function absint( $value ) {
	return abs( (int) $value );
}
function apply_filters( $hook, $value ) {
	return $value;
}
function __( $text, $domain = null ) {
	return $text;
}
function is_user_logged_in() {
	return (bool) $GLOBALS['aicfab_logged_in'];
}
function wp_get_current_user() {
	$user        = new stdClass();
	$user->roles = $GLOBALS['aicfab_user_roles'];
	return $user;
}
function wp_roles() {
	$roles              = new stdClass();
	$roles->role_names  = array(
		'administrator' => 'Administrator',
		'editor'        => 'Editor',
		'author'        => 'Author',
		'contributor'   => 'Contributor',
		'subscriber'    => 'Subscriber',
	);
	return $roles;
}

require_once __DIR__ . '/../includes/class-ai-chat-bedrock-rate-limits.php';

$failures = array();
function check_limit( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

function as_guest() {
	$GLOBALS['aicfab_logged_in']  = false;
	$GLOBALS['aicfab_user_roles'] = array();
}

function as_user( $roles ) {
	$GLOBALS['aicfab_logged_in']  = true;
	$GLOBALS['aicfab_user_roles'] = (array) $roles;
}

// --- Nothing configured -----------------------------------------------------

as_guest();
check_limit( array() === AI_Chat_Bedrock_Rate_Limits::all(), 'no overrides exist by default' );
check_limit( 5 === AI_Chat_Bedrock_Rate_Limits::for_current_user( 5 ), 'the site-wide limit applies when nothing is configured' );
as_user( array( 'administrator' ) );
check_limit( 5 === AI_Chat_Bedrock_Rate_Limits::for_current_user( 5 ), 'an administrator also gets the site-wide limit by default' );

// --- Roles offered ----------------------------------------------------------

$roles = AI_Chat_Bedrock_Rate_Limits::roles();
check_limit( isset( $roles[ AI_Chat_Bedrock_Rate_Limits::GUEST_KEY ] ), 'visitors who are not signed in can be limited' );
check_limit( isset( $roles['administrator'], $roles['subscriber'] ), 'site roles are offered' );
check_limit( 6 === count( $roles ), 'guests plus the five site roles, got ' . count( $roles ) );

// --- Saving -----------------------------------------------------------------

$saved = AI_Chat_Bedrock_Rate_Limits::save(
	array(
		'administrator' => 40,
		'editor'        => 20,
		'guest'         => 2,
		'subscriber'    => 0,          // zero removes the override
		'nonexistent'   => 99,         // unknown roles are refused
		'author'        => 'not a number',
	)
);
check_limit( array( 'administrator' => 40, 'editor' => 20, 'guest' => 2 ) === $saved, 'only usable rows are stored: ' . wp_json_encode_compat( $saved ) );
check_limit( ! isset( $saved['subscriber'] ), 'a zero clears the override rather than blocking the role' );
check_limit( ! isset( $saved['nonexistent'] ), 'a role that does not exist is refused' );
check_limit( ! isset( $saved['author'] ), 'a non-numeric value is refused' );

$capped = AI_Chat_Bedrock_Rate_Limits::save( array( 'administrator' => 100000 ) );
check_limit( AI_Chat_Bedrock_Rate_Limits::MAX_PER_ROLE === $capped['administrator'], 'a limit is capped rather than trusted' );

// --- Resolution -------------------------------------------------------------

AI_Chat_Bedrock_Rate_Limits::save( array( 'administrator' => 40, 'editor' => 20, 'guest' => 2 ) );

as_guest();
check_limit( 2 === AI_Chat_Bedrock_Rate_Limits::for_current_user( 5 ), 'a visitor who is not signed in gets the guest limit' );

as_user( array( 'editor' ) );
check_limit( 20 === AI_Chat_Bedrock_Rate_Limits::for_current_user( 5 ), 'an editor gets the editor limit' );

as_user( array( 'administrator' ) );
check_limit( 40 === AI_Chat_Bedrock_Rate_Limits::for_current_user( 5 ), 'an administrator gets the administrator limit' );

as_user( array( 'editor', 'administrator' ) );
check_limit( 40 === AI_Chat_Bedrock_Rate_Limits::for_current_user( 5 ), 'several roles resolve to the most permissive' );

// Order must not matter. With the permissive role first, an implementation that simply
// assigns each match in turn would end on the tighter limit.
as_user( array( 'administrator', 'editor' ) );
check_limit( 40 === AI_Chat_Bedrock_Rate_Limits::for_current_user( 5 ), 'the most permissive wins regardless of role order' );

as_user( array( 'subscriber' ) );
check_limit( 5 === AI_Chat_Bedrock_Rate_Limits::for_current_user( 5 ), 'a role without an override falls back to the site-wide limit' );

as_user( array( 'subscriber', 'editor' ) );
check_limit( 20 === AI_Chat_Bedrock_Rate_Limits::for_current_user( 5 ), 'one matching role is enough' );

as_user( array() );
check_limit( 5 === AI_Chat_Bedrock_Rate_Limits::for_current_user( 5 ), 'a signed-in user with no roles falls back' );

// A guest override must not leak to signed-in users.
AI_Chat_Bedrock_Rate_Limits::save( array( 'guest' => 1 ) );
as_user( array( 'subscriber' ) );
check_limit( 5 === AI_Chat_Bedrock_Rate_Limits::for_current_user( 5 ), 'the guest limit does not apply to signed-in visitors' );
as_guest();
check_limit( 1 === AI_Chat_Bedrock_Rate_Limits::for_current_user( 5 ), 'the guest limit applies to visitors who are not signed in' );

// The resolved limit is never zero, whatever is configured.
check_limit( AI_Chat_Bedrock_Rate_Limits::for_current_user( 0 ) >= 1, 'a resolved limit is always at least one request' );

// --- Reading stored data defensively ---------------------------------------

$GLOBALS['aicfab_options'][ AI_Chat_Bedrock_Rate_Limits::OPTION ] = 'not an array';
check_limit( array() === AI_Chat_Bedrock_Rate_Limits::all(), 'a corrupt option reads as no overrides' );

$GLOBALS['aicfab_options'][ AI_Chat_Bedrock_Rate_Limits::OPTION ] = array( 'editor' => 500, 'BAD KEY' => 5, '' => 9 );
$read = AI_Chat_Bedrock_Rate_Limits::all();
check_limit( AI_Chat_Bedrock_Rate_Limits::MAX_PER_ROLE === $read['editor'], 'a stored limit above the cap is clamped on read' );
check_limit( ! isset( $read[''] ), 'an empty role key is dropped on read' );
check_limit( isset( $read['badkey'] ), 'a stored key is sanitized rather than silently lost' );

// --- The settings summary ---------------------------------------------------

$GLOBALS['aicfab_options'][ AI_Chat_Bedrock_Rate_Limits::OPTION ] = array();
$summary = AI_Chat_Bedrock_Rate_Limits::describe( 7 );
check_limit( false !== strpos( $summary, '7' ), 'with no overrides the summary states the site-wide limit' );

AI_Chat_Bedrock_Rate_Limits::save( array( 'administrator' => 30, 'guest' => 2 ) );
$summary = AI_Chat_Bedrock_Rate_Limits::describe( 7 );
check_limit( false !== strpos( $summary, 'Administrator: 30' ), 'the summary names each override, got: ' . $summary );
check_limit( false !== strpos( $summary, 'Not signed in: 2' ), 'the summary uses readable role names' );
check_limit( false !== strpos( $summary, '7' ), 'the summary still states the fallback' );

function wp_json_encode_compat( $value ) {
	return (string) json_encode( $value );
}
function wp_json_encode( $value ) {
	return json_encode( $value );
}

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: per-role rate limit checks passed\n";
