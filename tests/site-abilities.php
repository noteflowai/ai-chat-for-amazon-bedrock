<?php
/** Standalone tests for the controlled site abilities. */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['aicfab_options']  = array( 'ai_chat_bedrock_site_abilities' => true, 'ai_chat_bedrock_mcp_capability' => 'edit_posts' );
$GLOBALS['aicfab_caps']     = array( 'read' => true, 'edit_posts' => true );
$GLOBALS['aicfab_logged_in'] = true;
$GLOBALS['aicfab_inserted'] = array();

class WP_Error {
	private $code; private $message;
	public function __construct( $code, $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
class WP_Post {
	public $ID; public $post_title; public $post_content; public $post_status; public $post_password; public $post_type;
	public function __construct( $id, $title, $content, $status = 'publish', $password = '', $type = 'post' ) {
		$this->ID = $id; $this->post_title = $title; $this->post_content = $content;
		$this->post_status = $status; $this->post_password = $password; $this->post_type = $type;
	}
}
class WP_Query {
	public $posts = array();
	public function __construct( $args = array() ) {
		$this->posts = array();
		foreach ( $GLOBALS['aicfab_posts'] as $post ) {
			$types = isset( $args['post_type'] ) ? (array) $args['post_type'] : array( 'post' );
			if ( ! in_array( $post->post_type, $types, true ) ) { continue; }
			if ( isset( $args['s'] ) && '' !== $args['s'] && false === stripos( $post->post_title . ' ' . $post->post_content, $args['s'] ) ) { continue; }
			$this->posts[] = $post;
		}
		$limit = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 5;
		$this->posts = array_slice( $this->posts, 0, $limit );
	}
}
$GLOBALS['aicfab_posts'] = array(
	new WP_Post( 21, 'Published guide', 'Public content about widgets.', 'publish' ),
	new WP_Post( 22, 'Draft guide', 'Unpublished content.', 'draft' ),
	new WP_Post( 23, 'Locked guide', 'Secret content.', 'publish', 'hunter2' ),
	new WP_Post( 24, 'Blue widget', 'A published product.', 'publish', '', 'product' ),
);

function get_option( $name, $default = false ) { return isset( $GLOBALS['aicfab_options'][ $name ] ) ? $GLOBALS['aicfab_options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['aicfab_options'][ $name ] = $value; return true; }
function apply_filters( $hook, $value ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function __( $message, $domain = null ) { return $message; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function current_user_can( $capability ) { return ! empty( $GLOBALS['aicfab_caps'][ $capability ] ); }
function is_user_logged_in() { return (bool) $GLOBALS['aicfab_logged_in']; }
function get_current_user_id() { return 7; }
function get_post( $id ) {
	foreach ( $GLOBALS['aicfab_posts'] as $post ) { if ( (int) $post->ID === (int) $id ) { return $post; } }
	return null;
}
function get_the_title( $post ) { return $post->post_title; }
function get_permalink( $post ) { return 'https://example.test/?p=' . $post->ID; }
function get_edit_post_link( $id, $context = '' ) { return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit'; }
function get_post_type_object( $type ) { return (object) array( 'public' => in_array( $type, array( 'post', 'page', 'product' ), true ) ); }
function wp_strip_all_tags( $text ) { return strip_tags( (string) $text ); }
function strip_shortcodes( $text ) { return (string) $text; }
function wp_kses_post( $text ) { return (string) $text; }
function wp_reset_postdata() {}
function wp_insert_post( $args, $wp_error = false ) {
	$GLOBALS['aicfab_inserted'][] = $args;
	return 99;
}
function wp_salt() { return 'site-abilities-salt'; }

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-tool-policy.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-site-abilities.php';

$failures = array();
function check_site( $condition, $message ) { global $failures; if ( ! $condition ) { $failures[] = $message; } }

$abilities = new AI_Chat_Bedrock_Site_Abilities();

// Read-only search excludes drafts and password-protected posts.
$search = $abilities->search_content( array( 'query' => 'content', 'per_page' => 10 ) );
$ids    = array_map( function ( $item ) { return $item['id']; }, $search['results'] );
check_site( in_array( 21, $ids, true ), 'Published posts must be searchable.' );
check_site( ! in_array( 22, $ids, true ), 'Draft posts must never be returned.' );
check_site( ! in_array( 23, $ids, true ), 'Password-protected posts must never be returned.' );
check_site( count( $search['results'] ) === $search['count'], 'Result count must match the returned rows.' );

// Reading a single post honours the same boundary.
$post = $abilities->get_post_content( array( 'id' => 21 ) );
check_site( is_array( $post ) && 'Published guide' === $post['title'], 'Published posts must be readable.' );
check_site( is_wp_error( $abilities->get_post_content( array( 'id' => 22 ) ) ), 'Draft posts must not be readable.' );
check_site( is_wp_error( $abilities->get_post_content( array( 'id' => 23 ) ) ), 'Password-protected posts must not be readable.' );
check_site( is_wp_error( $abilities->get_post_content( array( 'id' => 0 ) ) ), 'Invalid post IDs must be rejected.' );

// Draft creation never publishes and never touches existing posts.
$draft = $abilities->create_draft( array( 'title' => 'AI suggested title', 'content' => 'Body text' ) );
check_site( is_array( $draft ) && 'draft' === $draft['status'] && false === $draft['published'], 'Created content must stay a draft.' );
check_site( 1 === count( $GLOBALS['aicfab_inserted'] ), 'Exactly one post must be inserted.' );
$inserted = $GLOBALS['aicfab_inserted'][0];
check_site( 'draft' === $inserted['post_status'], 'Insert must request draft status.' );
check_site( ! isset( $inserted['ID'] ), 'Draft creation must never update an existing post.' );
check_site( is_wp_error( $abilities->create_draft( array( 'title' => '', 'content' => '' ) ) ), 'Empty drafts must be rejected.' );

$GLOBALS['aicfab_caps']['edit_posts'] = false;
check_site( false === $abilities->can_draft(), 'Draft permission must require edit_posts.' );
check_site( is_wp_error( $abilities->create_draft( array( 'title' => 'x', 'content' => 'y' ) ) ), 'Draft creation must be refused without edit_posts.' );
check_site( 1 === count( $GLOBALS['aicfab_inserted'] ), 'Refused drafts must not insert posts.' );
$GLOBALS['aicfab_caps']['edit_posts'] = true;

// SEO suggestions never save anything.
$seo = $abilities->suggest_seo_meta( array( 'id' => 21 ) );
check_site( is_array( $seo ) && false === $seo['saved'], 'SEO suggestions must not be saved.' );
check_site( $seo['word_count'] > 0 && '' !== $seo['suggested_description'], 'SEO suggestions must be derived from the post content.' );
check_site( 1 === count( $GLOBALS['aicfab_inserted'] ), 'SEO analysis must not write posts.' );

// Read permission requires a signed-in user with the configured capability.
check_site( true === $abilities->can_read(), 'Signed-in users with the capability may read.' );
$GLOBALS['aicfab_logged_in'] = false;
check_site( false === $abilities->can_read(), 'Anonymous visitors must not use content abilities.' );
$GLOBALS['aicfab_logged_in'] = true;

// WooCommerce lookups are inert when WooCommerce is absent.
$products = $abilities->get_products( array( 'query' => 'widget' ) );
check_site( array() === $products['results'] && 0 === $products['count'], 'Product lookups must be empty without WooCommerce.' );

// Disabling the feature prevents registration.
$GLOBALS['aicfab_options']['ai_chat_bedrock_site_abilities'] = false;
check_site( false === AI_Chat_Bedrock_Site_Abilities::enabled(), 'Site abilities must be opt-in.' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: controlled site ability boundaries passed\n";
