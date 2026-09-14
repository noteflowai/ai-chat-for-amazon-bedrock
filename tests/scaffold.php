<?php
/**
 * Standalone tests for the site page scaffold.
 *
 * The point of these is the boundary, not the prose: drafts only, never touching what
 * already exists, capability enforced, and a cap on how much one run can create.
 *
 * Run: php tests/scaffold.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['aicfab_caps']     = array( 'edit_pages' => true );
$GLOBALS['aicfab_posts']    = array();
$GLOBALS['aicfab_inserted'] = array();
$GLOBALS['aicfab_meta']     = array();
$GLOBALS['aicfab_next_id']  = 100;

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
class WP_Post {
	public $ID          = 0;
	public $post_title  = '';
	public $post_status = '';
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function current_user_can( $cap ) {
	return ! empty( $GLOBALS['aicfab_caps'][ $cap ] );
}
function get_current_user_id() {
	return 7;
}
function sanitize_text_field( $value ) {
	$value = preg_replace( '/[\r\n\t]+/', ' ', (string) $value );
	return trim( preg_replace( '/<[^>]*>/', '', $value ) );
}
function sanitize_textarea_field( $value ) {
	return trim( preg_replace( '/<[^>]*>/', '', (string) $value ) );
}
function wp_strip_all_tags( $value ) {
	return preg_replace( '/<[^>]*>/', '', (string) $value );
}
function esc_html( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}
function apply_filters( $hook, $value ) {
	return $value;
}
function __( $text, $domain = null ) {
	return $text;
}
function get_posts( $args ) {
	$ids = array();
	foreach ( $GLOBALS['aicfab_posts'] as $post ) {
		if ( isset( $args['title'] ) && $post->post_title === $args['title'] ) {
			$ids[] = $post->ID;
		}
	}
	return $ids;
}
function wp_insert_post( $args, $wp_error = false ) {
	$GLOBALS['aicfab_inserted'][] = $args;
	$id                           = $GLOBALS['aicfab_next_id']++;
	$post                         = new WP_Post();
	$post->ID                     = $id;
	$post->post_title             = $args['post_title'];
	$post->post_status            = $args['post_status'];
	$GLOBALS['aicfab_posts'][]    = $post;
	return $id;
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['aicfab_meta'][ $id ][ $key ] = $value;
	return true;
}
function get_edit_post_link( $id, $context = 'display' ) {
	return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit';
}
function OBJECT() {
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

class AI_Chat_Bedrock_Security {
	public static function string_substr( $value, $start, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $value, $start, $length ) : substr( (string) $value, $start, $length );
	}
}

require_once __DIR__ . '/../includes/class-ai-chat-bedrock-scaffold.php';

/**
 * A scaffold whose model replies are supplied by the test.
 */
class Test_Scaffold extends AI_Chat_Bedrock_Scaffold {
	public $reply  = '';
	public $error  = null;
	public $asked  = array();

	protected function ask_model( $system, $user, $max_tokens ) {
		$this->asked[] = array(
			'system' => $system,
			'user'   => $user,
			'tokens' => $max_tokens,
		);
		return null !== $this->error ? $this->error : $this->reply;
	}
}

$failures = array();
function check_scaffold( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

// --- Parsing a plan ----------------------------------------------------------

$specs = AI_Chat_Bedrock_Scaffold::parse_plan( '[{"title":"About","purpose":"Who we are"},{"title":"Contact","purpose":"How to reach us"}]' );
check_scaffold( 2 === count( $specs ), 'a clean JSON array is read, got ' . count( $specs ) );
check_scaffold( 'About' === $specs[0]['title'], 'the title is kept' );

// Models wrap JSON in prose or fences often enough that it must be tolerated.
$specs = AI_Chat_Bedrock_Scaffold::parse_plan( "Sure! Here you go:\n```json\n[{\"title\":\"Home\",\"purpose\":\"Landing\"}]\n```\nLet me know." );
check_scaffold( 1 === count( $specs ) && 'Home' === $specs[0]['title'], 'JSON wrapped in prose and a fence is still read' );

check_scaffold( array() === AI_Chat_Bedrock_Scaffold::parse_plan( 'I cannot help with that.' ), 'a reply with no array yields nothing' );
check_scaffold( array() === AI_Chat_Bedrock_Scaffold::parse_plan( '[not json]' ), 'malformed JSON yields nothing' );
check_scaffold( array() === AI_Chat_Bedrock_Scaffold::parse_plan( '[{"purpose":"no title"}]' ), 'an entry without a title is dropped' );

$long = array();
for ( $i = 0; $i < 40; $i++ ) {
	$long[] = array(
		'title'   => 'Page ' . $i,
		'purpose' => 'x',
	);
}
$specs = AI_Chat_Bedrock_Scaffold::parse_plan( json_encode( $long ) );
check_scaffold(
	AI_Chat_Bedrock_Scaffold::MAX_PAGES === count( $specs ),
	'a long plan is capped at ' . AI_Chat_Bedrock_Scaffold::MAX_PAGES . ', got ' . count( $specs )
);

$specs = AI_Chat_Bedrock_Scaffold::parse_plan( '[{"title":"<script>alert(1)</script>Contact","purpose":"<b>bold</b> text"}]' );
check_scaffold( false === strpos( $specs[0]['title'], '<' ), 'markup in a title is stripped, got ' . $specs[0]['title'] );
check_scaffold( false === strpos( $specs[0]['purpose'], '<b>' ), 'markup in a purpose is stripped' );

// --- Block conversion --------------------------------------------------------

$blocks = AI_Chat_Bedrock_Scaffold::to_blocks( "# Services\n\nWe repair bikes.\n\n## Pickup\n\nWe collect them.", 'Services' );
check_scaffold( false === strpos( $blocks, '<p># ' ), 'a markdown heading never becomes a literal paragraph' );
check_scaffold( false === strpos( $blocks, '>Services<' ), 'a heading repeating the page title is dropped' );
check_scaffold( 1 === substr_count( $blocks, '<!-- wp:heading -->' ), 'the remaining heading becomes one heading block' );
check_scaffold( 2 === substr_count( $blocks, '<!-- wp:paragraph -->' ), 'each paragraph becomes a block' );

// Every heading level is normalised, because models pick levels freely.
$blocks = AI_Chat_Bedrock_Scaffold::to_blocks( "### Deep\n\nText.", 'Title' );
check_scaffold( false !== strpos( $blocks, '<h2 class="wp-block-heading">Deep</h2>' ), 'a level three heading becomes an h2, got ' . $blocks );

$blocks = AI_Chat_Bedrock_Scaffold::to_blocks( "It isn't hard.", 'T' );
check_scaffold( false !== strpos( $blocks, "isn't" ), 'an apostrophe stays readable rather than becoming an entity' );

$blocks = AI_Chat_Bedrock_Scaffold::to_blocks( 'Angle < bracket & ampersand.', 'T' );
check_scaffold( false !== strpos( $blocks, '&lt;' ) && false !== strpos( $blocks, '&amp;' ), 'structural characters are still escaped' );

$blocks = AI_Chat_Bedrock_Scaffold::to_blocks( "Line one\nLine two", 'T' );
check_scaffold( 1 === substr_count( $blocks, '<!-- wp:paragraph -->' ), 'a single newline stays inside one paragraph' );
check_scaffold( false !== strpos( $blocks, '<br>' ), 'a single newline becomes a soft break' );

check_scaffold( '' === AI_Chat_Bedrock_Scaffold::to_blocks( "   \n\n  ", 'T' ), 'empty output produces no blocks' );

// --- Creating drafts ---------------------------------------------------------

$scaffold        = new Test_Scaffold();
$scaffold->reply = "We repair bikes.\n\n## Pickup\n\nWe collect them.";

$result = $scaffold->create_page( array( 'title' => 'Services', 'purpose' => 'What we do' ), 'A bike shop.' );
check_scaffold( ! is_wp_error( $result ), 'a page is created' );
check_scaffold( empty( $result['skipped'] ), 'the page was not skipped' );

$inserted = $GLOBALS['aicfab_inserted'][0];
check_scaffold( 'draft' === $inserted['post_status'], 'the page is inserted as a draft, got ' . $inserted['post_status'] );
check_scaffold( 'page' === $inserted['post_type'], 'the page is inserted as a page' );
check_scaffold( ! isset( $inserted['ID'] ), 'no ID is passed, so nothing existing can be overwritten' );
check_scaffold( ! isset( $inserted['post_date'] ), 'no publish date is forced' );
check_scaffold( 7 === $inserted['post_author'], 'the current user is recorded as the author' );
check_scaffold( isset( $GLOBALS['aicfab_meta'][ $result['id'] ]['_aicfab_scaffolded'] ), 'the draft is marked as machine-drafted' );

// The status must be a draft whatever else changes.
foreach ( $GLOBALS['aicfab_inserted'] as $args ) {
	check_scaffold( 'draft' === $args['post_status'], 'every insert is a draft' );
	check_scaffold( 'publish' !== $args['post_status'], 'nothing is ever published' );
}

// --- Existing content is never touched --------------------------------------

$before = count( $GLOBALS['aicfab_inserted'] );
$again  = $scaffold->create_page( array( 'title' => 'Services', 'purpose' => 'What we do' ), 'A bike shop.' );
check_scaffold( ! empty( $again['skipped'] ), 'a title that already exists is skipped' );
check_scaffold( 0 < AI_Chat_Bedrock_Scaffold::find_page_by_title( 'Services' ), 'the lookup finds an existing page without the deprecated helper' );
check_scaffold( 0 === AI_Chat_Bedrock_Scaffold::find_page_by_title( 'Nothing here' ), 'the lookup returns zero when there is no such page' );
check_scaffold( $before === count( $GLOBALS['aicfab_inserted'] ), 'skipping writes nothing' );
check_scaffold( ! empty( $again['reason'] ), 'the reason for skipping is reported' );

// --- Capability --------------------------------------------------------------

$GLOBALS['aicfab_caps'] = array();
$before                 = count( $GLOBALS['aicfab_inserted'] );
$denied                 = $scaffold->create_page( array( 'title' => 'Brand new', 'purpose' => 'x' ), 'A bike shop.' );
check_scaffold( is_wp_error( $denied ), 'a user without the capability is refused' );
check_scaffold( $before === count( $GLOBALS['aicfab_inserted'] ), 'a refused request writes nothing' );
check_scaffold( 'edit_pages' === AI_Chat_Bedrock_Scaffold::CAPABILITY, 'the capability required is edit_pages' );
$GLOBALS['aicfab_caps'] = array( 'edit_pages' => true );

// --- Model failures ----------------------------------------------------------

$scaffold->error = new WP_Error( 'aicfab_http_error', 'Bedrock said no.' );
$before          = count( $GLOBALS['aicfab_inserted'] );
$failed          = $scaffold->create_page( array( 'title' => 'Another page', 'purpose' => 'x' ), 'A bike shop.' );
check_scaffold( is_wp_error( $failed ), 'a model failure is returned rather than swallowed' );
check_scaffold( $before === count( $GLOBALS['aicfab_inserted'] ), 'a failed generation creates no empty draft' );
$scaffold->error = null;

$scaffold->reply = '   ';
$before          = count( $GLOBALS['aicfab_inserted'] );
$blank           = $scaffold->create_page( array( 'title' => 'Blank page', 'purpose' => 'x' ), 'A bike shop.' );
check_scaffold( is_wp_error( $blank ), 'an empty reply does not become an empty draft' );
check_scaffold( $before === count( $GLOBALS['aicfab_inserted'] ), 'nothing is written for an empty reply' );

$scaffold->reply = 'Fine.';
$no_title        = $scaffold->create_page( array( 'title' => '   ', 'purpose' => 'x' ), 'A bike shop.' );
check_scaffold( is_wp_error( $no_title ), 'a blank title is refused' );

// --- Prompts guard against fabrication --------------------------------------

$page_prompt = AI_Chat_Bedrock_Scaffold::page_prompt();
foreach ( array( 'prices', 'statistics', 'testimonials', 'placeholder' ) as $needle ) {
	check_scaffold( false !== stripos( $page_prompt, $needle ), "the page prompt addresses $needle" );
}
$plan_prompt = AI_Chat_Bedrock_Scaffold::plan_prompt();
check_scaffold( false !== stripos( $plan_prompt, 'do not invent' ), 'the planning prompt forbids invention' );
check_scaffold( false !== strpos( $plan_prompt, (string) AI_Chat_Bedrock_Scaffold::MAX_PAGES ), 'the planning prompt states the page cap' );

// --- Description handling ----------------------------------------------------

check_scaffold( '' === AI_Chat_Bedrock_Scaffold::clean_description( '   ' ), 'a blank description is empty' );
check_scaffold( 1200 >= strlen( AI_Chat_Bedrock_Scaffold::clean_description( str_repeat( 'a', 5000 ) ) ), 'a long description is truncated' );
check_scaffold(
	false === strpos( AI_Chat_Bedrock_Scaffold::clean_description( '<script>x</script>hello' ), '<script>' ),
	'markup in the description is stripped'
);

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: site scaffold checks passed\n";
