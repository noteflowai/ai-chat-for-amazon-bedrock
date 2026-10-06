<?php
/**
 * The review request: asked once, only after the chat has proved itself, and never again.
 *
 * @package AI_Chat_Bedrock
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['aicfab_can']     = true;
$GLOBALS['aicfab_screen']  = 'toplevel_page_ai-chat-for-amazon-bedrock';
$GLOBALS['aicfab_meta']    = array();
$GLOBALS['aicfab_filters'] = array();
$GLOBALS['aicfab_days']    = array();
$GLOBALS['aicfab_scripts'] = array();
$GLOBALS['aicfab_json']    = null;

function current_user_can( $cap ) { return $GLOBALS['aicfab_can']; }
function get_current_screen() { return null === $GLOBALS['aicfab_screen'] ? null : (object) array( 'id' => $GLOBALS['aicfab_screen'] ); }
function get_current_user_id() { return 7; }
function get_user_meta( $user, $key, $single ) { return isset( $GLOBALS['aicfab_meta'][ $user ][ $key ] ) ? $GLOBALS['aicfab_meta'][ $user ][ $key ] : ''; }
function update_user_meta( $user, $key, $value ) { $GLOBALS['aicfab_meta'][ $user ][ $key ] = $value; return true; }
function apply_filters( $hook, $value ) { return isset( $GLOBALS['aicfab_filters'][ $hook ] ) ? call_user_func( $GLOBALS['aicfab_filters'][ $hook ], $value ) : $value; }
function esc_html__( $text ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function __( $text ) { return $text; }
function esc_url( $url ) { return $url; }
function wp_add_inline_script( $handle, $code ) { $GLOBALS['aicfab_scripts'][] = $code; }
function wp_create_nonce( $action ) { return 'nonce-' . $action; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function check_ajax_referer( $action, $arg, $die ) { return ! empty( $GLOBALS['aicfab_nonce_ok'] ); }
function wp_send_json_success() { $GLOBALS['aicfab_json'] = 'success'; }
function wp_send_json_error( $data, $status ) { $GLOBALS['aicfab_json'] = 'error ' . $status; }

class AI_Chat_Bedrock_Usage {
	public static function days() { return $GLOBALS['aicfab_days']; }
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-review-prompt.php';

$failures = 0;
function check_review( $ok, $message ) {
	global $failures;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $message . "\n";
	if ( ! $ok ) {
		++$failures;
	}
}

function aicfab_days_ago( $n ) { return gmdate( 'Y-m-d', time() - $n * DAY_IN_SECONDS ); }
$today = gmdate( 'Y-m-d' );

// --- When it has been earned ---------------------------------------------------

check_review( ! AI_Chat_Bedrock_Review_Prompt::earned( array(), $today ), 'a new install is not asked' );
check_review( ! AI_Chat_Bedrock_Review_Prompt::earned( array( aicfab_days_ago( 10 ) => array( 'requests' => 5 ) ), $today ), 'a handful of answers is not enough' );
check_review( ! AI_Chat_Bedrock_Review_Prompt::earned( array( aicfab_days_ago( 2 ) => array( 'requests' => 200 ) ), $today ), 'a busy first two days is not enough' );
check_review(
	AI_Chat_Bedrock_Review_Prompt::earned(
		array(
			aicfab_days_ago( 8 ) => array( 'requests' => 12 ),
			aicfab_days_ago( 1 ) => array( 'requests' => 9 ),
		),
		$today
	),
	'twenty answers over more than a week earns it'
);
check_review(
	! AI_Chat_Bedrock_Review_Prompt::earned(
		array(
			aicfab_days_ago( 9 ) => array( 'requests' => 0, 'embedding_requests' => 500 ),
			aicfab_days_ago( 1 ) => array( 'requests' => 25 ),
		),
		$today
	),
	'indexing alone does not start the clock, only answers do'
);
check_review( ! AI_Chat_Bedrock_Review_Prompt::earned( array( 'junk' => 'x', aicfab_days_ago( 9 ) => null ), $today ), 'malformed counters are ignored' );

// --- Where and to whom ---------------------------------------------------------

$GLOBALS['aicfab_days'] = array(
	aicfab_days_ago( 8 ) => array( 'requests' => 30 ),
);
check_review( AI_Chat_Bedrock_Review_Prompt::should_show( 'ai-chat-for-amazon-bedrock' ), 'an administrator on a plugin screen is asked' );

$GLOBALS['aicfab_screen'] = 'dashboard';
check_review( ! AI_Chat_Bedrock_Review_Prompt::should_show( 'ai-chat-for-amazon-bedrock' ), 'never on other admin screens' );
$GLOBALS['aicfab_screen'] = null;
check_review( ! AI_Chat_Bedrock_Review_Prompt::should_show( 'ai-chat-for-amazon-bedrock' ), 'never where the screen is unknown' );
$GLOBALS['aicfab_screen'] = 'ai-chat-bedrock_page_ai-chat-for-amazon-bedrock-settings';
check_review( ! AI_Chat_Bedrock_Review_Prompt::should_show( 'ai-chat-for-amazon-bedrock' ), 'not above the work on other plugin screens' );
$GLOBALS['aicfab_screen'] = 'toplevel_page_ai-chat-for-amazon-bedrock';

$GLOBALS['aicfab_can'] = false;
check_review( ! AI_Chat_Bedrock_Review_Prompt::should_show( 'ai-chat-for-amazon-bedrock' ), 'never to someone who cannot manage options' );
$GLOBALS['aicfab_can'] = true;

$GLOBALS['aicfab_filters']['ai_chat_bedrock_review_prompt'] = '__return_false_review';
function __return_false_review() { return false; }
check_review( ! AI_Chat_Bedrock_Review_Prompt::should_show( 'ai-chat-for-amazon-bedrock' ), 'a filter can turn it off' );
unset( $GLOBALS['aicfab_filters']['ai_chat_bedrock_review_prompt'] );

// --- What it says --------------------------------------------------------------

ob_start();
AI_Chat_Bedrock_Review_Prompt::render( 'ai-chat-for-amazon-bedrock' );
$html = ob_get_clean();
check_review( false !== strpos( $html, 'is-dismissible' ), 'the prompt can be closed' );
check_review( false !== strpos( $html, AI_Chat_Bedrock_Review_Prompt::REVIEW_URL ), 'it links to the review form on WordPress.org' );
check_review( false !== strpos( $html, AI_Chat_Bedrock_Review_Prompt::SUPPORT_URL ), 'it offers the support forum alongside' );
check_review( ! preg_match( '/discount|coupon|free|unlock|premium|5[ -]?star/i', $html ), 'nothing is offered in return, and no rating is suggested' );
check_review( 1 === count( $GLOBALS['aicfab_scripts'] ) && false !== strpos( $GLOBALS['aicfab_scripts'][0], 'ai_chat_bedrock_dismiss_review_prompt' ), 'closing it or following a link is remembered' );

// --- Dismissal is for good -----------------------------------------------------

$GLOBALS['aicfab_nonce_ok'] = false;
AI_Chat_Bedrock_Review_Prompt::ajax_dismiss();
check_review( 'error 403' === $GLOBALS['aicfab_json'] && '' === get_user_meta( 7, AI_Chat_Bedrock_Review_Prompt::META, true ), 'a dismissal without the nonce is refused' );

$GLOBALS['aicfab_nonce_ok'] = true;
AI_Chat_Bedrock_Review_Prompt::ajax_dismiss();
check_review( 'success' === $GLOBALS['aicfab_json'], 'a dismissal with the nonce is accepted' );
check_review( ! AI_Chat_Bedrock_Review_Prompt::should_show( 'ai-chat-for-amazon-bedrock' ), 'once dismissed it does not return' );

$GLOBALS['aicfab_days'][ aicfab_days_ago( 1 ) ] = array( 'requests' => 1000 );
check_review( ! AI_Chat_Bedrock_Review_Prompt::should_show( 'ai-chat-for-amazon-bedrock' ), 'not even after much more use' );

$uninstall = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
check_review( false !== strpos( $uninstall, "'" . AI_Chat_Bedrock_Review_Prompt::META . "'" ), 'uninstalling removes the dismissal' );

$main = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock.php' );
check_review( false !== strpos( $main, "'wp_ajax_ai_chat_bedrock_dismiss_review_prompt', 'AI_Chat_Bedrock_Review_Prompt', 'ajax_dismiss'" ), 'the dismissal route is registered' );

if ( $failures ) {
	echo "\n{$failures} review prompt check(s) failed\n";
	exit( 1 );
}
echo "\nAll review prompt checks passed\n";
