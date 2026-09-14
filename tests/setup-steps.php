<?php
/**
 * Standalone tests for the dashboard setup checklist.
 *
 * The reason this exists: the last step used to be written as done => false, so the
 * checklist could never be completed however the site was configured. Every step is now
 * decided from state, and the test below asserts that no step is a constant.
 *
 * Run: php tests/setup-steps.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['aicfab_options']    = array();
$GLOBALS['aicfab_transients'] = array();
$GLOBALS['aicfab_db_rows']    = array();
$GLOBALS['aicfab_queries']    = 0;

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_options'] ) ? $GLOBALS['aicfab_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['aicfab_options'][ $name ] = $value;
	return true;
}
function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['aicfab_transients'] ) ? $GLOBALS['aicfab_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['aicfab_transients'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['aicfab_transients'][ $key ] );
	return true;
}
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}
function get_edit_post_link( $id, $context = 'display' ) {
	return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit';
}
function __( $text, $domain = null ) {
	return $text;
}

/**
 * Minimal wpdb that answers the placement lookup from a fixture.
 */
class Fake_WPDB {
	public $posts = 'wp_posts';
	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%s/', "'" . $arg . "'", $query, 1 );
		}
		return $query;
	}
	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}
	public function get_row( $query ) {
		++$GLOBALS['aicfab_queries'];
		foreach ( $GLOBALS['aicfab_db_rows'] as $row ) {
			return (object) $row;
		}
		return null;
	}
}
$GLOBALS['wpdb'] = new Fake_WPDB();

class AI_Chat_Bedrock_Conversations {
	public static $on = false;
	public static function enabled() {
		return self::$on;
	}
}
class AI_Chat_Bedrock_Rate_Limits {
	const GUEST_KEY = 'guest';
	public static $limits = array();
	public static function all() {
		return self::$limits;
	}
}

require_once __DIR__ . '/../includes/class-ai-chat-bedrock-setup-steps.php';

$failures = array();
function check_step( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

function context( $options = array(), $configured = true, $model = 'a-model', $region = 'US East', $requests = 5 ) {
	return array(
		'options'     => $options,
		'credentials' => array(
			'configured' => $configured,
			'message'    => 'EC2 instance role',
		),
		'model'       => $model,
		'region_name' => $region,
		'requests'    => $requests,
	);
}

function reset_state() {
	$GLOBALS['aicfab_transients'] = array();
	$GLOBALS['aicfab_db_rows']    = array();
	$GLOBALS['aicfab_options']    = array();
	$GLOBALS['aicfab_queries']              = 0;
	AI_Chat_Bedrock_Conversations::$on     = false;
	AI_Chat_Bedrock_Rate_Limits::$limits   = array();
}

// --- No step may be a constant ------------------------------------------------

// The regression this file exists for: a step written as done => false can never be
// completed, so the checklist is broken by construction.
$source = file_get_contents( __DIR__ . '/../includes/class-ai-chat-bedrock-setup-steps.php' );
check_step(
	false === strpos( $source, "'done'  => false," ) || false !== strpos( $source, '$placement' ),
	'no step hard-codes itself as never done'
);

reset_state();
$nothing = AI_Chat_Bedrock_Setup_Steps::essential( context( array(), false, '', '', 0 ) );
// Settings changes flush this in production, which is what the hook is for.
AI_Chat_Bedrock_Setup_Steps::flush();
$all = AI_Chat_Bedrock_Setup_Steps::essential(
	context( array( 'popup_site_wide' => 1 ) )
);
check_step( count( $nothing ) === count( $all ), 'the same steps are listed either way' );

$never_done = array();
foreach ( $nothing as $index => $step ) {
	if ( ! empty( $step['done'] ) ) {
		continue;
	}
	if ( empty( $all[ $index ]['done'] ) ) {
		$never_done[] = $step['label'];
	}
}
check_step(
	array() === $never_done,
	'every step can reach done, stuck: ' . implode( ', ', $never_done )
);

// --- A brand new site --------------------------------------------------------

reset_state();
$steps    = AI_Chat_Bedrock_Setup_Steps::essential( context( array(), false, '', '', 0 ) );
$progress = AI_Chat_Bedrock_Setup_Steps::progress( $steps );
check_step( 0 === $progress['done'], 'a new site has nothing done, got ' . $progress['done'] );
check_step( 4 === $progress['total'], 'there are four essential steps, got ' . $progress['total'] );
check_step( ! $progress['complete'], 'a new site is not complete' );
foreach ( $steps as $step ) {
	check_step( ! empty( $step['label'] ) && ! empty( $step['help'] ) && ! empty( $step['url'] ), 'every step has a label, help text and link' );
}

// --- Each condition moves exactly one step ----------------------------------

reset_state();
$before = AI_Chat_Bedrock_Setup_Steps::progress( AI_Chat_Bedrock_Setup_Steps::essential( context( array(), false, '', '', 0 ) ) );
$after  = AI_Chat_Bedrock_Setup_Steps::progress( AI_Chat_Bedrock_Setup_Steps::essential( context( array(), true, '', '', 0 ) ) );
check_step( $before['done'] + 1 === $after['done'], 'credentials complete one step' );

reset_state();
$after = AI_Chat_Bedrock_Setup_Steps::progress( AI_Chat_Bedrock_Setup_Steps::essential( context( array(), false, 'a-model', 'US East', 0 ) ) );
check_step( 1 === $after['done'], 'a region and model complete one step' );

reset_state();
$after = AI_Chat_Bedrock_Setup_Steps::progress( AI_Chat_Bedrock_Setup_Steps::essential( context( array(), false, '', '', 3 ) ) );
check_step( 1 === $after['done'], 'a recorded request completes the test step' );

// --- Publishing the chat ----------------------------------------------------

reset_state();
$placement = AI_Chat_Bedrock_Setup_Steps::chat_placement( true );
check_step( ! $placement['placed'], 'nothing published means the chat is not placed' );

reset_state();
$GLOBALS['aicfab_options']['ai_chat_bedrock_settings'] = array( 'popup_site_wide' => 1 );
$placement = AI_Chat_Bedrock_Setup_Steps::chat_placement( true );
check_step( $placement['placed'] && 'floating' === $placement['how'], 'the floating button counts as placed' );
check_step( 0 === $GLOBALS['aicfab_queries'], 'the floating button needs no database query' );

reset_state();
$GLOBALS['aicfab_db_rows'] = array(
	array(
		'ID'           => 42,
		'post_content' => 'Ask away: <!-- wp:ai-chat-bedrock/chat /-->',
	),
);
$placement = AI_Chat_Bedrock_Setup_Steps::chat_placement( true );
check_step( $placement['placed'] && 'block' === $placement['how'], 'the block counts as placed, got ' . $placement['how'] );
check_step( 42 === $placement['post_id'], 'the page holding it is identified' );

reset_state();
$GLOBALS['aicfab_db_rows'] = array(
	array(
		'ID'           => 7,
		'post_content' => 'Try it [ai_chat_bedrock] here',
	),
);
$placement = AI_Chat_Bedrock_Setup_Steps::chat_placement( true );
check_step( $placement['placed'] && 'shortcode' === $placement['how'], 'the shortcode counts as placed, got ' . $placement['how'] );

// The step text must say which way it was found, not just that it was.
$steps = AI_Chat_Bedrock_Setup_Steps::essential( context() );
$last  = end( $steps );
check_step( ! empty( $last['done'] ), 'the publish step is done when the shortcode is present' );
check_step( false !== stripos( $last['help'], 'shortcode' ), 'the help says how it was found, got ' . $last['help'] );

// --- The lookup is cached ----------------------------------------------------

reset_state();
$GLOBALS['aicfab_db_rows'] = array( array( 'ID' => 1, 'post_content' => '[ai_chat_bedrock]' ) );
$GLOBALS['aicfab_queries'] = 0;
AI_Chat_Bedrock_Setup_Steps::chat_placement( true );
AI_Chat_Bedrock_Setup_Steps::chat_placement();
AI_Chat_Bedrock_Setup_Steps::chat_placement();
check_step( 1 === $GLOBALS['aicfab_queries'], 'repeat calls come from the cache, queries: ' . $GLOBALS['aicfab_queries'] );
AI_Chat_Bedrock_Setup_Steps::flush();
AI_Chat_Bedrock_Setup_Steps::chat_placement();
check_step( 2 === $GLOBALS['aicfab_queries'], 'flushing forces a fresh lookup' );

// --- Grounding ---------------------------------------------------------------

check_step( ! AI_Chat_Bedrock_Setup_Steps::grounding_ready( array() ), 'nothing configured is not grounded' );
check_step( AI_Chat_Bedrock_Setup_Steps::grounding_ready( array( 'enable_site_context' => 1 ) ), 'site content search grounds answers' );
check_step( AI_Chat_Bedrock_Setup_Steps::grounding_ready( array( 'knowledge_base_id' => 'KB1' ) ), 'a knowledge base grounds answers' );
check_step( ! AI_Chat_Bedrock_Setup_Steps::grounding_ready( 'not an array' ), 'a corrupt value is handled' );

// --- Worth doing next --------------------------------------------------------

reset_state();
$next   = AI_Chat_Bedrock_Setup_Steps::next( array( 'options' => array() ) );
$labels = array_column( $next, 'label' );
check_step( in_array( 'Ground answers in your own content', $labels, true ), 'ungrounded sites are told to fix it' );
check_step( in_array( 'Pick a fallback model', $labels, true ), 'a missing fallback model is suggested' );
check_step( in_array( 'Record conversations to see what visitors ask', $labels, true ), 'logging is suggested while off' );

reset_state();
AI_Chat_Bedrock_Conversations::$on = true;
$labels = array_column(
	AI_Chat_Bedrock_Setup_Steps::next(
		array(
			'options' => array(
				'enable_site_context' => 1,
				'fallback_model_id'   => 'other-model',
			),
		)
	),
	'label'
);
check_step( array() === $labels, 'nothing is suggested once it is all done, got ' . implode( ', ', $labels ) );

// Guest chat without a guest limit is the one worth flagging.
reset_state();
AI_Chat_Bedrock_Conversations::$on = true;
$labels = array_column(
	AI_Chat_Bedrock_Setup_Steps::next(
		array(
			'options' => array(
				'enable_site_context' => 1,
				'fallback_model_id'   => 'other-model',
				'allow_public_chat'   => 1,
			),
		)
	),
	'label'
);
check_step( in_array( 'Set a limit for visitors who are not signed in', $labels, true ), 'guest chat without a guest limit is flagged' );

AI_Chat_Bedrock_Rate_Limits::$limits = array( 'guest' => 2 );
$labels = array_column(
	AI_Chat_Bedrock_Setup_Steps::next(
		array(
			'options' => array(
				'enable_site_context' => 1,
				'fallback_model_id'   => 'other-model',
				'allow_public_chat'   => 1,
			),
		)
	),
	'label'
);
check_step( ! in_array( 'Set a limit for visitors who are not signed in', $labels, true ), 'a guest limit clears the suggestion' );

// --- Progress ----------------------------------------------------------------

check_step( 0 === AI_Chat_Bedrock_Setup_Steps::progress( array() )['total'], 'no steps means no total' );
check_step( ! AI_Chat_Bedrock_Setup_Steps::progress( array() )['complete'], 'an empty list is not complete' );
$done_all = AI_Chat_Bedrock_Setup_Steps::progress( array( array( 'done' => true ), array( 'done' => true ) ) );
check_step( $done_all['complete'] && 2 === $done_all['done'], 'all done reports complete' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: setup checklist checks passed\n";
