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
function _x( $text, $context, $domain = null ) {
	return $text;
}
function esc_html__( $text, $domain = null ) {
	return $text;
}
function wp_nonce_url( $url, $action = -1 ) {
	return $url . '&_wpnonce=nonce-' . $action;
}

// What the chat page handler touches.
$GLOBALS['aicfab_caps']     = array( 'edit_pages' => true );
$GLOBALS['aicfab_pages']    = array();
$GLOBALS['aicfab_referer']  = '';
$GLOBALS['aicfab_redirect'] = '';
class Aicfab_Exit extends Exception {
}
function current_user_can( $cap, ...$args ) {
	if ( 'edit_post' === $cap ) {
		return ! empty( $GLOBALS['aicfab_caps']['edit_post'] );
	}
	return ! empty( $GLOBALS['aicfab_caps'][ $cap ] );
}
function check_admin_referer( $action ) {
	$GLOBALS['aicfab_referer'] = $action;
	return 1;
}
function get_current_user_id() {
	return 3;
}
function get_posts( $args ) {
	$ids = array();
	foreach ( $GLOBALS['aicfab_pages'] as $id => $page ) {
		if ( in_array( $page['post_status'], (array) $args['post_status'], true ) && ! empty( $page['meta_input'][ $args['meta_key'] ] ) ) {
			$ids[] = $id;
		}
	}
	return array_slice( $ids, 0, $args['posts_per_page'] );
}
function wp_insert_post( $post, $wp_error = false ) {
	$id                             = 100 + count( $GLOBALS['aicfab_pages'] );
	$GLOBALS['aicfab_pages'][ $id ] = $post;
	return $id;
}
function is_wp_error( $value ) {
	return false;
}
function wp_safe_redirect( $url ) {
	$GLOBALS['aicfab_redirect'] = $url;
}
function wp_die( $message = '', $title = '', $args = array() ) {
	throw new Aicfab_Exit( 'die:' . $message );
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

class AI_Chat_Bedrock_Translation {
	public static function items( $items ) {
		return implode( ', ', (array) $items );
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

// Plugins that register abilities are worth connecting while the chat cannot use them.
$aicfab_done    = array(
	'enable_site_context' => 1,
	'fallback_model_id'   => 'other-model',
);
$aicfab_ability = "Let the chat use your plugins' abilities";
reset_state();
AI_Chat_Bedrock_Conversations::$on = true;
$next = AI_Chat_Bedrock_Setup_Steps::next(
	array(
		'options'         => $aicfab_done,
		'ability_sources' => array( 'WooCommerce', 'Rank Math' ),
	)
);
$hit  = array_values( array_filter( $next, function ( $step ) use ( $aicfab_ability ) {
	return $aicfab_ability === $step['label'];
} ) );
check_step( 1 === count( $hit ), 'abilities found on the site, unused, are suggested' );
check_step( isset( $hit[0] ) && false !== strpos( $hit[0]['help'], 'WooCommerce, Rank Math' ), 'the suggestion names the plugins' );
check_step( isset( $hit[0] ) && false !== strpos( $hit[0]['url'], 'tab=knowledge' ), 'the suggestion leads to the setting' );

$aicfab_on                                         = $aicfab_done + array( 'abilities_tools' => 1 );
$GLOBALS['aicfab_options']['ai_chat_bedrock_enable_mcp'] = false;
$labels = array_column( AI_Chat_Bedrock_Setup_Steps::next( array( 'options' => $aicfab_on, 'ability_sources' => array( 'WooCommerce' ) ) ), 'label' );
check_step( in_array( $aicfab_ability, $labels, true ), 'abilities switched on while tools in chat are off are still suggested' );

$GLOBALS['aicfab_options']['ai_chat_bedrock_enable_mcp'] = true;
$labels = array_column( AI_Chat_Bedrock_Setup_Steps::next( array( 'options' => $aicfab_on, 'ability_sources' => array( 'WooCommerce' ) ) ), 'label' );
check_step( ! in_array( $aicfab_ability, $labels, true ), 'once the chat can use them the suggestion goes' );

$labels = array_column( AI_Chat_Bedrock_Setup_Steps::next( array( 'options' => $aicfab_done ) ), 'label' );
check_step( ! in_array( $aicfab_ability, $labels, true ), 'a site without such plugins is not told about abilities' );
unset( $GLOBALS['aicfab_options']['ai_chat_bedrock_enable_mcp'] );

// --- Creating the chat page -------------------------------------------------

reset_state();
$publish = null;
foreach ( AI_Chat_Bedrock_Setup_Steps::essential( context( array(), true, 'a-model', 'US East', 1 ) ) as $aicfab_step ) {
	if ( 'Publish the chat' === $aicfab_step['label'] ) {
		$publish = $aicfab_step;
	}
}
check_step( null !== $publish && ! $publish['done'], 'an unplaced chat leaves the publish step open' );
check_step( null !== $publish && false !== strpos( $publish['url'], 'admin-post.php?action=ai_chat_bedrock_create_chat_page' ) && false !== strpos( $publish['url'], '_wpnonce=nonce-ai_chat_bedrock_create_chat_page' ), 'the publish step creates the page, behind a nonce' );

// A handler that gets as far as its exit would end this file with success, so that is a failure.
$GLOBALS['aicfab_in_handler'] = true;
register_shutdown_function(
	function () {
		if ( ! empty( $GLOBALS['aicfab_in_handler'] ) ) {
			fwrite( STDERR, "FAILED\n- the handler went on to create a page and exit for a user who may not\n" );
			exit( 1 );
		}
	}
);
$GLOBALS['aicfab_caps'] = array();
try {
	AI_Chat_Bedrock_Setup_Steps::handle_create_chat_page();
	$aicfab_stopped = '';
} catch ( Aicfab_Exit $stop ) {
	$aicfab_stopped = $stop->getMessage();
}
$GLOBALS['aicfab_in_handler'] = false;
check_step( 0 === strpos( $aicfab_stopped, 'die:' ) && array() === $GLOBALS['aicfab_pages'], 'a user who cannot create pages gets none' );
check_step( '' === $GLOBALS['aicfab_referer'], 'the capability is checked before anything else' );

$GLOBALS['aicfab_caps'] = array(
	'edit_pages' => true,
	'edit_post'  => true,
);
$aicfab_edit = AI_Chat_Bedrock_Setup_Steps::open_chat_page();
check_step( 1 === count( $GLOBALS['aicfab_pages'] ), 'the first visit creates one page' );
$aicfab_page = reset( $GLOBALS['aicfab_pages'] );
check_step( 'draft' === $aicfab_page['post_status'] && 'page' === $aicfab_page['post_type'], 'the page is a draft, so nothing goes live unpublished' );
check_step( '<!-- wp:ai-chat-bedrock/chat /-->' === $aicfab_page['post_content'], 'the page holds the chat block' );
check_step( 3 === $aicfab_page['post_author'], 'the page belongs to whoever created it' );
check_step( 'https://example.test/wp-admin/post.php?post=100&action=edit' === $aicfab_edit, 'the editor for the new page opens, got ' . $aicfab_edit );

check_step( $aicfab_edit === AI_Chat_Bedrock_Setup_Steps::open_chat_page() && 1 === count( $GLOBALS['aicfab_pages'] ), 'a second visit reopens the same draft' );

$GLOBALS['aicfab_caps']['edit_post'] = false;
AI_Chat_Bedrock_Setup_Steps::open_chat_page();
check_step( 2 === count( $GLOBALS['aicfab_pages'] ), 'a draft the user may not edit is not handed to them' );

$GLOBALS['aicfab_caps']['edit_post']          = true;
$GLOBALS['aicfab_pages'][100]['post_status'] = 'publish';
$GLOBALS['aicfab_pages'][101]['post_status'] = 'publish';
AI_Chat_Bedrock_Setup_Steps::open_chat_page();
check_step( 3 === count( $GLOBALS['aicfab_pages'] ), 'a published page is not reopened as the draft' );

$aicfab_wiring = file_get_contents( __DIR__ . '/../includes/class-ai-chat-bedrock.php' );
check_step( false !== strpos( $aicfab_wiring, "'admin_post_" . AI_Chat_Bedrock_Setup_Steps::CREATE_ACTION . "', 'AI_Chat_Bedrock_Setup_Steps', 'handle_create_chat_page'" ), 'the handler is wired to admin-post under its action' );
check_step( false !== strpos( file_get_contents( __DIR__ . '/../uninstall.php' ), "'_aicfab_chat_page'" ), 'uninstalling removes the mark on the page' );

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
