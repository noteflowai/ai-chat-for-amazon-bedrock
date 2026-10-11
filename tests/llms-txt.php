<?php
/**
 * llms.txt checks, against the real class and the real site policy.
 *
 * The file is public and read by AI tools, so what it lists is asserted: only what the site
 * policy lets agents see, as valid Markdown links, and never in place of another llms.txt.
 *
 * @package AI_Chat_Bedrock
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, Squiz.Commenting, Generic.Commenting, WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride, Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO

define( 'ABSPATH', sys_get_temp_dir() . '/aicfab-llms-' . getmypid() . '/' );
@mkdir( ABSPATH, 0700, true );

$GLOBALS['aicfab_options']    = array( 'ai_chat_bedrock_settings' => array( 'llms_txt' => true ) );
$GLOBALS['aicfab_filters']    = array();
$GLOBALS['aicfab_transients'] = array();
$GLOBALS['aicfab_queries']    = 0;

class WP_Post {
	public $ID;
	public $post_title;
	public $post_status;
	public $post_password = '';
	public $post_type;
	public function __construct( $id, $title, $type = 'post', $status = 'publish', $password = '' ) {
		$this->ID            = $id;
		$this->post_title    = $title;
		$this->post_type     = $type;
		$this->post_status   = $status;
		$this->post_password = $password;
	}
}
class WooCommerce {
}
class AI_Chat_Bedrock_WooCommerce {
	public static function is_listable( $product ) {
		return is_object( $product ) && 31 !== $product->id;
	}
}

$GLOBALS['aicfab_posts'] = array(
	new WP_Post( 10, 'Getting started &#038; setup' ),
	new WP_Post( 11, 'About [us]', 'page' ),
	new WP_Post( 12, 'Draft plan', 'post', 'draft' ),
	new WP_Post( 13, 'Locked notes', 'post', 'publish', 'secret' ),
	new WP_Post( 14, 'Thank you for your order', 'page' ),
	new WP_Post( 15, 'Members area', 'page' ),
	new WP_Post( 30, 'Blue widget', 'product' ),
	new WP_Post( 31, 'Hidden widget', 'product' ),
);

function get_option( $name, $default = false ) {
	return isset( $GLOBALS['aicfab_options'][ $name ] ) ? $GLOBALS['aicfab_options'][ $name ] : $default;
}
function apply_filters( $hook, $value, ...$args ) {
	return isset( $GLOBALS['aicfab_filters'][ $hook ] ) ? call_user_func( $GLOBALS['aicfab_filters'][ $hook ], $value, ...$args ) : $value;
}
function has_filter( $hook ) {
	return false;
}
function __( $text, $domain = null ) {
	return $text;
}
function sanitize_key( $value ) {
	return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) );
}
function sanitize_text_field( $value ) {
	return trim( (string) $value );
}
function wp_unslash( $value ) {
	return $value;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}
function wp_list_pluck( $list, $field ) {
	return array_map(
		function ( $item ) use ( $field ) {
			return $item[ $field ];
		},
		$list
	);
}
function esc_url_raw( $url ) {
	return (string) $url;
}
function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}
function get_bloginfo( $what ) {
	$info = array(
		'name'        => 'Example <b>Lab</b>',
		'description' => "Robots,\n explained",
		'language'    => 'en-US',
	);
	return isset( $info[ $what ] ) ? $info[ $what ] : '';
}
function get_post( $id ) {
	foreach ( $GLOBALS['aicfab_posts'] as $post ) {
		if ( (int) $post->ID === (int) $id ) {
			return $post;
		}
	}
	return null;
}
function get_the_title( $post ) {
	return $post->post_title;
}
function get_permalink( $post ) {
	return 11 === $post->ID ? 'https://example.test/about (us)/' : 'https://example.test/?p=' . $post->ID;
}
function is_post_type_viewable( $type ) {
	return true;
}
function get_post_types( $args, $output ) {
	return array( 'post', 'page', 'attachment', 'product' );
}
function get_taxonomies( $args, $output ) {
	return array( 'category', 'post_tag', 'post_format' );
}
function get_posts( $args ) {
	$GLOBALS['aicfab_queries']++;
	$out = array();
	foreach ( $GLOBALS['aicfab_posts'] as $post ) {
		if ( $post->post_type === $args['post_type'] && $post->post_status === $args['post_status'] && ( ! isset( $args['has_password'] ) || false !== $args['has_password'] || '' === $post->post_password ) ) {
			$out[] = $post;
		}
	}
	return array_slice( $out, 0, $args['posts_per_page'] );
}
function wc_get_product( $id ) {
	return (object) array( 'id' => (int) $id );
}
function get_transient( $key ) {
	return isset( $GLOBALS['aicfab_transients'][ $key ] ) ? $GLOBALS['aicfab_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl ) {
	$GLOBALS['aicfab_transients'][ $key ] = $value;
	return true;
}
function is_404() {
	return $GLOBALS['aicfab_is_404'];
}
$GLOBALS['aicfab_is_404'] = true;
function delete_transient( $key ) {
	unset( $GLOBALS['aicfab_transients'][ $key ] );
	return true;
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-content.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-ontology.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-llms-txt.php';

$failures = array();
function check_llms( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

// A page hidden from search engines, and one the site keeps for members.
$GLOBALS['aicfab_filters']['ai_chat_bedrock_post_is_noindex'] = function ( $noindex, $post ) {
	return 14 === $post->ID ? true : $noindex;
};
$GLOBALS['aicfab_filters']['ai_chat_bedrock_is_public_post'] = function ( $public, $post ) {
	return 15 === $post->ID ? false : $public;
};

// --- What it lists -------------------------------------------------------------------

$text = AI_Chat_Bedrock_Llms_Txt::build();
check_llms( 0 === strpos( $text, "# Example Lab\n\n> Robots, explained\n" ), 'The file starts with the site name as the title and the tagline as the summary, as plain text.' );
check_llms( false !== strpos( $text, "## Posts\n\n- [Getting started & setup](https://example.test/?p=10)\n" ), 'A public post is listed under its type, with its title as plain text.' );
check_llms( false !== strpos( $text, '- [About (us)](https://example.test/about%20%28us%29/)' ), 'Brackets in a title and parentheses in an address cannot break the Markdown link.' );
check_llms( false !== strpos( $text, "## Products\n\n- [Blue widget](https://example.test/?p=30)" ), 'A listed product is listed under products.' );
foreach ( array( 'Draft plan' => 'a draft', 'Locked notes' => 'a password-protected post', 'Thank you for your order' => 'a page hidden from search engines', 'Members area' => 'members-only content', 'Hidden widget' => 'a product the shop does not list' ) as $title => $what ) {
	check_llms( false === strpos( $text, $title ), 'The file never names ' . $what . '.' );
}
check_llms( false === strpos( $text, 'Content is published in' ), 'A site in one language does not list languages.' );
check_llms( false === strpos( $text, 'attachment' ) && false === strpos( $text, '## The site' ), 'Media files and the site node are not listed as content.' );

$GLOBALS['aicfab_filters']['ai_chat_bedrock_ontology_allows'] = function ( $allowed, $post, $usage ) {
	return 30 === $post->ID ? false : $allowed;
};
check_llms( false === strpos( AI_Chat_Bedrock_Llms_Txt::build(), 'Blue widget' ), 'A post the site withholds from agents is not listed.' );
unset( $GLOBALS['aicfab_filters']['ai_chat_bedrock_ontology_allows'] );

// --- Languages ------------------------------------------------------------------------

// Declared here, not at the top, the way a site adds Polylang: a conditional declaration is
// not hoisted to the start of the file.
if ( ! function_exists( 'pll_languages_list' ) ) {
	function pll_languages_list( $args ) {
		return 'slug' === $args['fields'] ? array( 'en', 'ja' ) : array( 'English', '日本語' );
	}
	function pll_default_language( $field ) {
		return 'en';
	}
	function pll_get_post_language( $post_id, $field ) {
		return 10 === $post_id ? 'ja' : 'en';
	}
}
$text = AI_Chat_Bedrock_Llms_Txt::build();
check_llms( false !== strpos( $text, "Content is published in: English, 日本語.\n" ), 'A multilingual site says which languages it publishes in.' );
check_llms( false !== strpos( $text, '- [Getting started & setup](https://example.test/?p=10): 日本語' ), 'Each item names its language.' );

// --- Cache and serving ----------------------------------------------------------------

$GLOBALS['aicfab_queries'] = 0;
AI_Chat_Bedrock_Llms_Txt::flush();
$first  = AI_Chat_Bedrock_Llms_Txt::text();
$counts = $GLOBALS['aicfab_queries'];
$second = AI_Chat_Bedrock_Llms_Txt::text();
check_llms( $first === $second && $counts > 0 && $counts === $GLOBALS['aicfab_queries'], 'The file is cached, so a request does not query every post type again.' );
AI_Chat_Bedrock_Llms_Txt::flush();
check_llms( false === get_transient( AI_Chat_Bedrock_Llms_Txt::CACHE_KEY ), 'A change forgets the cached file.' );

$_SERVER['REQUEST_URI'] = '/llms.txt?x=1';
check_llms( AI_Chat_Bedrock_Llms_Txt::is_request(), 'The address at the site root is recognized, with a query string.' );
foreach ( array( '/blog/llms.txt', '/llms.txt.bak', '/llms-txt', '/' ) as $uri ) {
	$_SERVER['REQUEST_URI'] = $uri;
	check_llms( ! AI_Chat_Bedrock_Llms_Txt::is_request(), 'Another address is not the file: ' . $uri );
}
check_llms( 'https://example.test/llms.txt' === AI_Chat_Bedrock_Llms_Txt::url(), 'The address is the one at the site root.' );

check_llms( ! AI_Chat_Bedrock_Llms_Txt::file_exists(), 'Without a real file at the site root the plugin serves its own.' );
file_put_contents( ABSPATH . 'llms.txt', "# Hand written\n" );
$_SERVER['REQUEST_URI'] = '/llms.txt';
ob_start();
( new AI_Chat_Bedrock_Llms_Txt() )->serve();
$served = ob_get_clean();
check_llms( AI_Chat_Bedrock_Llms_Txt::file_exists() && '' === $served, 'A real llms.txt file is never replaced.' );
unlink( ABSPATH . 'llms.txt' );
rmdir( ABSPATH );

$GLOBALS['aicfab_is_404'] = false;
ob_start();
( new AI_Chat_Bedrock_Llms_Txt() )->serve();
check_llms( '' === ob_get_clean(), 'When a page, post or rewrite rule answers the address, it is left to answer.' );
$GLOBALS['aicfab_is_404']                                = true;
$GLOBALS['aicfab_filters']['ai_chat_bedrock_llms_txt_serve'] = '__return_false';
function __return_false() {
	return false;
}
ob_start();
( new AI_Chat_Bedrock_Llms_Txt() )->serve();
check_llms( '' === ob_get_clean(), 'A plugin that serves its own llms.txt later can claim the address.' );
unset( $GLOBALS['aicfab_filters']['ai_chat_bedrock_llms_txt_serve'] );

$GLOBALS['aicfab_options']['ai_chat_bedrock_settings']['llms_txt'] = false;
ob_start();
( new AI_Chat_Bedrock_Llms_Txt() )->serve();
check_llms( '' === ob_get_clean() && ! AI_Chat_Bedrock_Llms_Txt::enabled(), 'Nothing is served while the setting is off.' );

// --- Wiring ---------------------------------------------------------------------------

$root      = dirname( __DIR__ );
$bootstrap = (string) file_get_contents( $root . '/includes/class-ai-chat-bedrock.php' );
check_llms( false !== strpos( $bootstrap, "add_action( 'template_redirect', \$llms_txt, 'serve', PHP_INT_MAX )" ), 'It answers last on template_redirect, after any other handler there.' );
foreach ( array( 'save_post', 'deleted_post', 'update_option_ai_chat_bedrock_settings' ) as $hook ) {
	check_llms( false !== strpos( $bootstrap, "add_action( '{$hook}', 'AI_Chat_Bedrock_Llms_Txt', 'flush' )" ), 'The cache is forgotten on ' . $hook . '.' );
}
$admin = (string) file_get_contents( $root . '/admin/class-ai-chat-bedrock-admin.php' );
check_llms( false !== strpos( $admin, "\$output['llms_txt']            = ! empty( \$input['llms_txt'] );" ) && false !== strpos( $admin, "\t\t'llms_txt',\n" ), 'The setting is saved as a checkbox, off unless chosen.' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: llms.txt checks passed\n";
