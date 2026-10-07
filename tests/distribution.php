<?php
/**
 * Standalone tests for the record of where posts are published, and Bilibili embeds.
 *
 * Run: php tests/distribution.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['aicfab_options']  = array();
$GLOBALS['aicfab_meta']     = array();
$GLOBALS['aicfab_posts']    = array();
$GLOBALS['aicfab_can']      = array();
$GLOBALS['aicfab_abilities'] = array();
$GLOBALS['aicfab_embeds']   = array();
$GLOBALS['aicfab_page']     = array();

class WP_Error {
	private $code;
	public function __construct( $code, $message = '', $data = array() ) {
		$this->code = $code;
	}
	public function get_error_code() {
		return $this->code;
	}
}
class WP_Post {
	public $ID;
	public $post_type   = 'post';
	public $post_status = 'publish';
	public $post_title;
	public $post_content = '';
	public $post_modified_gmt = '2026-10-05 00:00:00';
	public $post_password = '';
	public $post_excerpt  = '';
	public function __construct( $id, $title, $status = 'publish' ) {
		$this->ID          = $id;
		$this->post_title  = $title;
		$this->post_status = $status;
	}
}
function get_transient( $key ) {
	return isset( $GLOBALS['aicfab_transients'][ $key ] ) ? $GLOBALS['aicfab_transients'][ $key ] : false;
}
function delete_transient( $key ) {
	unset( $GLOBALS['aicfab_transients'][ $key ] );
	return true;
}
function get_current_user_id() {
	return 1;
}
function wp_nonce_field( $action, $name ) {
	echo '<input type="hidden" name="' . $name . '" value="nonce-' . $action . '">';
}
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function get_option( $name, $fallback = false ) {
	return 'ai_chat_bedrock_settings' === $name ? $GLOBALS['aicfab_options'] : $fallback;
}
function get_post( $id = null ) {
	if ( null === $id ) {
		$id = isset( $GLOBALS['aicfab_page']['post'] ) ? $GLOBALS['aicfab_page']['post'] : 0;
	}
	return isset( $GLOBALS['aicfab_posts'][ (int) $id ] ) ? $GLOBALS['aicfab_posts'][ (int) $id ] : null;
}
function get_post_meta( $id, $key, $single = false ) {
	return isset( $GLOBALS['aicfab_meta'][ $id ][ $key ] ) ? $GLOBALS['aicfab_meta'][ $id ][ $key ] : '';
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['aicfab_meta'][ $id ][ $key ] = $value;
	return true;
}
function current_user_can( $capability, $id = 0 ) {
	return in_array( $capability . ( $id ? ':' . $id : '' ), $GLOBALS['aicfab_can'], true );
}
function get_posts( $args ) {
	return array_keys( $GLOBALS['aicfab_meta'] );
}
function get_the_title( $post ) {
	$post = is_object( $post ) ? $post : get_post( $post );
	return $post ? $post->post_title : '';
}
function get_permalink( $post ) {
	$post = is_object( $post ) ? $post : get_post( $post );
	return 'https://example.test/?p=' . $post->ID;
}
function get_the_excerpt( $post ) {
	return 'An excerpt.';
}
function get_the_post_thumbnail_url( $post, $size ) {
	return 'https://example.test/cover.png';
}
function wp_get_post_terms( $id, $taxonomy, $args ) {
	return 'post_tag' === $taxonomy ? array( 'robots', 'kinematics' ) : array( 'Lessons' );
}
function absint( $value ) {
	return abs( (int) $value );
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $value ) ) );
}
function sanitize_textarea_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function esc_url_raw( $url, $protocols = null ) {
	$url    = trim( (string) $url );
	$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
	return in_array( $scheme, (array) $protocols, true ) ? $url : '';
}
function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES );
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_html__( $text, $domain = null ) {
	return esc_html( $text );
}
function esc_html_e( $text, $domain = null ) {
	echo esc_html( $text );
}
function __( $text, $domain = null ) {
	return $text;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function wp_parse_str( $input, &$output ) {
	parse_str( $input, $output );
}
function add_query_arg( $args, $url ) {
	return $url . '?' . http_build_query( $args );
}
function apply_filters( $hook, $value, ...$args ) {
	return $value;
}
function do_action( $hook, ...$args ) {}
function post_type_supports( $type, $feature ) {
	return 'post' === $type;
}
function add_meta_box( $id, $title, $callback, $screen, $context, $priority ) {
	$GLOBALS['aicfab_box'] = $id;
}
function wp_register_ability( $name, $args ) {
	$GLOBALS['aicfab_abilities'][ $name ] = $args;
}
function wp_embed_register_handler( $id, $regex, $callback ) {
	$GLOBALS['aicfab_embeds'][ $id ] = array( $regex, $callback );
}
function is_feed() {
	return false;
}
function is_singular() {
	return ! empty( $GLOBALS['aicfab_page']['singular'] );
}
function in_the_loop() {
	return true;
}
function is_main_query() {
	return true;
}
function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}

class AI_Chat_Bedrock_Security {
	public static function string_substr( $value, $start, $length ) {
		return mb_substr( (string) $value, $start, $length );
	}
	public static function string_length( $value ) {
		return mb_strlen( (string) $value );
	}
}
class AI_Chat_Bedrock_Abilities {
	const CATEGORY = 'ai-chat-bedrock';
}
class AI_Chat_Bedrock_Content {
	public static $text = array();
	public static function public_text( $post ) {
		return isset( self::$text[ $post->ID ] ) ? self::$text[ $post->ID ] : '';
	}
	public static function language( $post ) {
		return isset( $GLOBALS['aicfab_languages'][ $post->ID ] ) ? $GLOBALS['aicfab_languages'][ $post->ID ] : '';
	}
	public static function title( $post ) {
		return $post->post_title;
	}
	public static function is_rendering() {
		return false;
	}
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-distribution.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-bilibili.php';

$failures = array();
function check_dist( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

$GLOBALS['aicfab_posts'][7] = new WP_Post( 7, 'Lesson 1.1: kinematics' );
$GLOBALS['aicfab_posts'][8] = new WP_Post( 8, 'A draft lesson', 'draft' );
AI_Chat_Bedrock_Content::$text[7] = "Lesson 1.1: kinematics\n\nThe public part.";
$GLOBALS['aicfab_languages']      = array( 7 => 'zh' );

// --- Off by default -----------------------------------------------------------------------------

check_dist( ! AI_Chat_Bedrock_Distribution::enabled() && ! AI_Chat_Bedrock_Distribution::links_enabled(), 'The record and its links are off by default.' );
( new AI_Chat_Bedrock_Distribution() )->register_abilities();
check_dist( array() === $GLOBALS['aicfab_abilities'], 'No abilities are offered while it is off.' );
check_dist( ! AI_Chat_Bedrock_Distribution::links_enabled( array( 'distribution_links' => true ) ), 'Links need the record on too.' );
$GLOBALS['aicfab_options'] = array( 'distribution_enabled' => true );

// --- Recording ------------------------------------------------------------------------------------

$saved = AI_Chat_Bedrock_Distribution::record( 7, array( 'platform' => 'bilibili', 'item_id' => 'BV1xx411c7mD', 'url' => 'https://www.bilibili.com/video/BV1xx411c7mD/', 'account' => '1733311373', 'language' => 'zh', 'status' => 'submitted', 'version' => str_repeat( 'a', 64 ) ) );
check_dist( is_array( $saved ) && 'bilibili:BV1xx411c7mD' === $saved['key'] && 'submitted' === $saved['status'] && 'agent' === $saved['source'], 'A Bilibili submission is recorded under its platform and BV ID.' );
$updated = AI_Chat_Bedrock_Distribution::record( 7, array( 'platform' => 'bilibili', 'item_id' => 'BV1xx411c7mD', 'status' => 'public' ) );
$entries = AI_Chat_Bedrock_Distribution::entries( 7 );
check_dist( 1 === count( $entries ) && 'public' === $entries[0]['status'] && '1733311373' === $entries[0]['account'] && str_repeat( 'a', 64 ) === $entries[0]['version'], 'Reporting the same item again updates it, keeping what was not resent.' );
check_dist( 'https://www.bilibili.com/video/BV1xx411c7mD/' === $entries[0]['url'], 'The address given first is kept.' );
$youtube = AI_Chat_Bedrock_Distribution::record( 7, array( 'platform' => 'youtube', 'item_id' => 'dQw4w9WgXcQ', 'status' => 'public', 'language' => 'zh' ) );
check_dist( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' === $youtube['url'], 'An item without an address gets the platform\'s usual one.' );

foreach ( array(
	'an unknown platform'        => array( 'platform' => 'myspace', 'item_id' => 'x' ),
	'a malformed BV ID'          => array( 'platform' => 'bilibili', 'item_id' => 'av170001' ),
	'a malformed YouTube ID'     => array( 'platform' => 'youtube', 'item_id' => 'short' ),
	'an address on another host' => array( 'platform' => 'youtube', 'item_id' => 'dQw4w9WgXcQ', 'url' => 'https://evil.example/watch?v=dQw4w9WgXcQ' ),
	'an http address'            => array( 'platform' => 'youtube', 'item_id' => 'dQw4w9WgXcQ', 'url' => 'http://www.youtube.com/watch?v=dQw4w9WgXcQ' ),
	'a javascript address'       => array( 'platform' => 'bilibili', 'item_id' => 'BV1xx411c7mD', 'url' => 'javascript:alert(1)' ),
	'an unknown status'          => array( 'platform' => 'youtube', 'item_id' => 'dQw4w9WgXcQ', 'status' => 'viral' ),
) as $aicfab_what => $aicfab_input ) {
	check_dist( is_wp_error_like( AI_Chat_Bedrock_Distribution::record( 7, $aicfab_input ) ), ucfirst( $aicfab_what ) . ' is refused.' );
}
function is_wp_error_like( $value ) {
	return $value instanceof WP_Error;
}
check_dist( 2 === count( AI_Chat_Bedrock_Distribution::entries( 7 ) ), 'Refused records change nothing.' );
check_dist( 'public' === AI_Chat_Bedrock_Distribution::entries( 7 )[1]['status'], 'Not even the status of the item they named.' );
AI_Chat_Bedrock_Distribution::record( 7, array( 'platform' => 'bilibili', 'item_id' => 'BV1xx411c7mD', 'note' => 'Cover replaced.' ) );
check_dist( 'public' === AI_Chat_Bedrock_Distribution::entries( 7 )[1]['status'] && 'Cover replaced.' === AI_Chat_Bedrock_Distribution::entries( 7 )[1]['note'], 'An update without a status keeps the status.' );
check_dist( is_wp_error_like( AI_Chat_Bedrock_Distribution::record( 404, array( 'platform' => 'youtube', 'item_id' => 'dQw4w9WgXcQ' ) ) ), 'A missing post is refused.' );
$note = AI_Chat_Bedrock_Distribution::record( 7, array( 'platform' => 'xiaohongshu', 'item_id' => '64f1c2e3a4b5c6d7e8f90123', 'title' => '<b>Kinematics</b> in 60 seconds', 'note' => str_repeat( 'n', 900 ), 'status' => 'planned' ) );
check_dist( 'Kinematics in 60 seconds' === $note['title'] && 500 === mb_strlen( $note['note'] ) && 'https://www.xiaohongshu.com/explore/64f1c2e3a4b5c6d7e8f90123' === $note['url'], 'A Xiaohongshu note is recorded, with markup stripped and the note bounded.' );

// A new edition replaces the old one.
$new = AI_Chat_Bedrock_Distribution::record( 7, array( 'platform' => 'youtube', 'item_id' => 'aBcDeFgHiJ0', 'status' => 'public', 'language' => 'zh', 'replaces' => 'youtube:dQw4w9WgXcQ' ) );
$byid = array();
foreach ( AI_Chat_Bedrock_Distribution::entries( 7 ) as $aicfab_entry ) {
	$byid[ $aicfab_entry['key'] ] = $aicfab_entry;
}
check_dist( 'replaced' === $byid['youtube:dQw4w9WgXcQ']['status'] && 'youtube:aBcDeFgHiJ0' === $byid['youtube:dQw4w9WgXcQ']['replaced_by'], 'A new edition marks the old one replaced and points to it.' );
AI_Chat_Bedrock_Distribution::record( 7, array( 'platform' => 'youtube', 'item_id' => 'dQw4w9WgXcQ', 'status' => 'private' ) );
$byid = array();
foreach ( AI_Chat_Bedrock_Distribution::entries( 7 ) as $aicfab_entry ) {
	$byid[ $aicfab_entry['key'] ] = $aicfab_entry;
}
check_dist( 'private' === $byid['youtube:dQw4w9WgXcQ']['status'] && 'youtube:aBcDeFgHiJ0' === $byid['youtube:dQw4w9WgXcQ']['replaced_by'], 'Once made private on the platform, the old edition is recorded as private and still points to its successor.' );

for ( $aicfab_i = 0; $aicfab_i < 60; $aicfab_i++ ) {
	AI_Chat_Bedrock_Distribution::record( 8, array( 'platform' => 'youtube', 'item_id' => sprintf( 'v%010d', $aicfab_i ), 'status' => 'private' ) );
}
check_dist( AI_Chat_Bedrock_Distribution::MAX_ENTRIES === count( AI_Chat_Bedrock_Distribution::entries( 8 ) ) && 'v0000000059' === AI_Chat_Bedrock_Distribution::entries( 8 )[0]['item_id'], 'A post keeps its newest 50 records.' );

// --- The package ---------------------------------------------------------------------------------

$package = AI_Chat_Bedrock_Distribution::package( 7 );
check_dist( 'Lesson 1.1: kinematics' === $package['title'] && 'https://example.test/?p=7' === $package['url'] && 'zh' === $package['language'], 'The package names the post, its address and language.' );
check_dist( "Lesson 1.1: kinematics\n\nThe public part." === $package['text'], 'Its text is what a signed-out visitor reads, so a members-only section is never published elsewhere.' );
check_dist( array( 'robots', 'kinematics' ) === $package['tags'] && 'https://example.test/cover.png' === $package['image'] && 4 === count( $package['published'] ), 'It carries the tags, the image and where the post is already published.' );
check_dist( is_wp_error_like( AI_Chat_Bedrock_Distribution::package( 404 ) ), 'A missing post has no package.' );
check_dist( 'The public part.' === $package['excerpt'], 'Without a written excerpt, the excerpt is made from what a guest reads, not from the whole content.' );
get_post( 7 )->post_excerpt = 'Written <b>by hand</b>.';
check_dist( 'Written by hand.' === AI_Chat_Bedrock_Distribution::package( 7 )['excerpt'], 'A written excerpt is used as it is.' );
get_post( 7 )->post_excerpt = '';
if ( ! function_exists( 'pll_get_post' ) ) {
	function pll_get_post( $id, $language ) {
		return 'ja' === $language ? 8 : $id;
	}
}
$GLOBALS['aicfab_can'] = array( 'edit_post:7' );
check_dist( is_wp_error_like( AI_Chat_Bedrock_Distribution::package( 7, 'ja' ) ), 'A translation the user may not edit is not handed over.' );
$GLOBALS['aicfab_can'][] = 'edit_post:8';
check_dist( 8 === AI_Chat_Bedrock_Distribution::package( 7, 'ja' )['id'], 'One they may edit is.' );
$GLOBALS['aicfab_can'] = array();

// --- Search and permissions ----------------------------------------------------------------------

$GLOBALS['aicfab_can'] = array( 'edit_post:7', 'edit_posts' );
$found                 = AI_Chat_Bedrock_Distribution::search( array( 'platform' => 'youtube', 'status' => 'public' ) );
check_dist( 1 === count( $found['publications'] ) && 'aBcDeFgHiJ0' === $found['publications'][0]['item_id'] && 7 === $found['publications'][0]['post_id'], 'Records are found by platform and status.' );
check_dist( 0 === count( array_filter( AI_Chat_Bedrock_Distribution::search( array() )['publications'], function ( $row ) { return 8 === $row['post_id']; } ) ), 'Records of posts the account cannot edit are not listed.' );
check_dist( 2 === count( AI_Chat_Bedrock_Distribution::search( array( 'limit' => 2 ) )['publications'] ), 'The list is limited as asked.' );
$record = new AI_Chat_Bedrock_Distribution();
check_dist( $record->can_edit_input_post( array( 'post_id' => 7 ) ) && ! $record->can_edit_input_post( array( 'post_id' => 8 ) ) && ! $record->can_edit_input_post( array() ), 'Abilities about a post need permission to edit that post.' );

$record->register_abilities();
check_dist( array( 'ai-chat-bedrock/get-publish-package', 'ai-chat-bedrock/record-publication', 'ai-chat-bedrock/list-publications' ) === array_keys( $GLOBALS['aicfab_abilities'] ), 'Three abilities are offered when the record is on.' );
$write = $GLOBALS['aicfab_abilities']['ai-chat-bedrock/record-publication'];
check_dist( false === $write['meta']['annotations']['readonly'] && false === $write['meta']['annotations']['destructive'] && true === $write['meta']['annotations']['idempotent'], 'Recording is declared a write that destroys nothing and can be repeated.' );
check_dist( array( $record, 'can_edit_input_post' ) === $write['permission_callback'], 'Recording checks the post\'s permission.' );
check_dist( true === $GLOBALS['aicfab_abilities']['ai-chat-bedrock/get-publish-package']['meta']['annotations']['readonly'], 'The package is read only.' );

// --- Editing screen and links ----------------------------------------------------------------------

$record->add_meta_box( 'post' );
check_dist( 'aicfab-distribution' === $GLOBALS['aicfab_box'], 'The record shows on the editing screen.' );
ob_start();
$record->render_meta_box( get_post( 7 ) );
$box = ob_get_clean();
check_dist( false !== strpos( $box, 'https://www.bilibili.com/video/BV1xx411c7mD/' ) && false !== strpos( $box, 'Replaced by youtube:aBcDeFgHiJ0' ), 'The box lists each item with its link and replacement.' );

$GLOBALS['aicfab_page'] = array( 'singular' => true, 'post' => 7 );
check_dist( '<p>Body</p>' === AI_Chat_Bedrock_Distribution::add_links( '<p>Body</p>' ), 'Without the links setting nothing is added.' );
$GLOBALS['aicfab_options']['distribution_links'] = true;
$html = AI_Chat_Bedrock_Distribution::add_links( '<p>Body</p>' );
check_dist( false !== strpos( $html, 'Also on' ) && false !== strpos( $html, 'BV1xx411c7mD' ) && false !== strpos( $html, 'aBcDeFgHiJ0' ), 'The post links to its public items: ' . $html );
check_dist( false === strpos( $html, 'dQw4w9WgXcQ' ) && false === strpos( $html, 'xiaohongshu' ), 'Not to replaced, private or planned ones.' );
$GLOBALS['aicfab_languages'][7] = 'en';
check_dist( '<p>Body</p>' === AI_Chat_Bedrock_Distribution::add_links( '<p>Body</p>' ), 'Nor to items in another language than the post.' );
$GLOBALS['aicfab_languages'][7] = 'zh';
$GLOBALS['aicfab_page']['singular'] = false;
check_dist( '<p>Body</p>' === AI_Chat_Bedrock_Distribution::add_links( '<p>Body</p>' ), 'Not on archives.' );

// --- MCP and bootstrap ----------------------------------------------------------------------------------

$root      = dirname( __DIR__ );
$mcp       = (string) file_get_contents( $root . '/includes/class-ai-chat-bedrock-wp-mcp-server.php' );
$bootstrap = (string) file_get_contents( $root . '/includes/class-ai-chat-bedrock.php' );
check_dist( false !== strpos( $mcp, '$this->distribution_tools()' ) && false !== strpos( $mcp, "case 'record_publication':" ) && false !== strpos( $mcp, '$record->can_edit_input_post( $arguments )' ), 'The MCP server offers the record, with the post\'s permission checked.' );
check_dist( false !== strpos( $bootstrap, "add_action( 'wp_abilities_api_init', \$distribution, 'register_abilities' )" ) && false !== strpos( $bootstrap, "add_action( 'add_meta_boxes', \$distribution, 'add_meta_box' )" ) && false !== strpos( $bootstrap, "add_action( 'init', 'AI_Chat_Bedrock_Bilibili', 'register' )" ), 'Abilities, the box and Bilibili embeds are hooked.' );

// --- Bilibili embeds ----------------------------------------------------------------------------------

AI_Chat_Bedrock_Bilibili::register();
check_dist( array() === $GLOBALS['aicfab_embeds'], 'Bilibili links are not embedded unless the site turns it on.' );
$GLOBALS['aicfab_options']['bilibili_embeds'] = true;
AI_Chat_Bedrock_Bilibili::register();
check_dist( isset( $GLOBALS['aicfab_embeds']['aicfab-bilibili'] ), 'Turned on, WordPress learns the Bilibili address.' );
$pattern = $GLOBALS['aicfab_embeds']['aicfab-bilibili'][0];
foreach ( array( 'https://www.bilibili.com/video/BV1xx411c7mD', 'https://www.bilibili.com/video/BV1xx411c7mD/?p=2&t=30', 'https://m.bilibili.com/video/BV1xx411c7mD' ) as $aicfab_url ) {
	check_dist( 1 === preg_match( $pattern, $aicfab_url ), $aicfab_url . ' is embedded.' );
}
foreach ( array( 'https://www.bilibili.com/read/cv123', 'https://evil.example/www.bilibili.com/video/BV1xx411c7mD', 'https://www.bilibili.com/video/av170001' ) as $aicfab_url ) {
	check_dist( 1 !== preg_match( $pattern, $aicfab_url ), $aicfab_url . ' is not.' );
}
preg_match( $pattern, 'https://www.bilibili.com/video/BV1xx411c7mD/?p=2&t=30', $aicfab_matches );
$player = AI_Chat_Bedrock_Bilibili::embed( $aicfab_matches, array(), 'https://www.bilibili.com/video/BV1xx411c7mD/?p=2&t=30' );
check_dist( false !== strpos( $player, 'https://player.bilibili.com/player.html?bvid=BV1xx411c7mD&amp;page=2&amp;autoplay=0&amp;danmaku=0&amp;high_quality=1&amp;t=30' ), 'The player opens the part and time linked, paused and without danmaku: ' . $player );
check_dist( false !== strpos( $player, 'sandbox="allow-scripts allow-same-origin allow-popups allow-presentation"' ) && false !== strpos( $player, 'loading="lazy"' ) && false !== strpos( $player, 'title="Bilibili video BV1xx411c7mD"' ) && false !== strpos( $player, 'aspect-ratio:16/9' ), 'It is sandboxed, lazy, titled for screen readers and sized 16:9.' );

// --- Only the plugin says a post sits in a WeChat draft --------------------------------

$aicfab_draft = array( 'platform' => 'wechat', 'item_id' => 'Hb8Ss9pqQ1x2y3z4A5b6C7d8', 'status' => 'planned', 'version' => 'idx:0' );
$aicfab_forged = AI_Chat_Bedrock_Distribution::record( 7, $aicfab_draft, 'agent' );
check_dist( is_wp_error( $aicfab_forged ) && 'aicfab_distribution_wechat_draft' === $aicfab_forged->get_error_code(), 'An agent cannot record a WeChat draft, whose media_id later updates and deletes would follow.' );
check_dist( is_wp_error( AI_Chat_Bedrock_Distribution::record( 7, $aicfab_draft, 'manual' ) ), 'Nor can it be entered by hand.' );
check_dist( ! is_wp_error( AI_Chat_Bedrock_Distribution::record( 7, $aicfab_draft, 'wechat' ) ), 'The plugin records the drafts it sends.' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: publishing record checks passed\n";
