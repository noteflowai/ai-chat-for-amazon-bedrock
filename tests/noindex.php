<?php
/**
 * Standalone tests for leaving out pages that SEO plugins keep from search engines.
 *
 * Run: php tests/noindex.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['aicfab_meta']    = array();
$GLOBALS['aicfab_options'] = array();
$GLOBALS['aicfab_filters'] = array();
$GLOBALS['aicfab_aioseo']  = array();

function get_post_meta( $id, $key, $single = false ) {
	return isset( $GLOBALS['aicfab_meta'][ $id ][ $key ] ) ? $GLOBALS['aicfab_meta'][ $id ][ $key ] : '';
}
function get_option( $name, $fallback = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_options'] ) ? $GLOBALS['aicfab_options'][ $name ] : $fallback;
}
function add_filter( $hook, $callback ) {
	$GLOBALS['aicfab_filters'][ $hook ] = $callback;
}
function apply_filters( $hook, $value, ...$args ) {
	return isset( $GLOBALS['aicfab_filters'][ $hook ] ) ? call_user_func( $GLOBALS['aicfab_filters'][ $hook ], $value, ...$args ) : $value;
}
function is_post_type_viewable( $type ) {
	return true;
}

class WP_Post {
	public $ID;
	public $post_type;
	public $post_status   = 'publish';
	public $post_password = '';
	public function __construct( $id, $type = 'post' ) {
		$this->ID        = $id;
		$this->post_type = $type;
	}
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-content.php';

$failures = array();
function check_noindex( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}
function meta( $id, $key, $value ) {
	$GLOBALS['aicfab_meta'][ $id ][ $key ] = $value;
}

$post     = new WP_Post( 1 );
$page     = new WP_Post( 2, 'page' );
$thanks   = new WP_Post( 3, 'page' );
$private  = new WP_Post( 4 );
$private->post_status = 'private';

// --- Without an SEO plugin ------------------------------------------------------------

meta( 3, '_yoast_wpseo_meta-robots-noindex', '1' );
meta( 3, 'rank_math_robots', array( 'noindex' ) );
check_noindex( ! AI_Chat_Bedrock_Content::is_noindex( $thanks ), 'Settings of an SEO plugin that is not active are ignored.' );
$GLOBALS['aicfab_options']['blog_public'] = '0';
check_noindex( AI_Chat_Bedrock_Content::is_answerable( $post ), 'A site that discourages search engines, as a staging site does, still answers from its pages.' );
check_noindex( ! AI_Chat_Bedrock_Content::is_answerable( $private ), 'A post that is not public is never answerable.' );
check_noindex( '3' === AI_Chat_Bedrock_Content::index_version( array() ) && '3+noindex' === AI_Chat_Bedrock_Content::index_version( array( 'include_noindex' => true ) ), 'Stored indexes are built again when the setting changes.' );

// --- Yoast SEO --------------------------------------------------------------------------

define( 'WPSEO_VERSION', '26.0' );
check_noindex( AI_Chat_Bedrock_Content::is_noindex( $thanks ), 'Yoast SEO: a page set to noindex is noindex.' );
check_noindex( ! AI_Chat_Bedrock_Content::is_answerable( $thanks ), 'And the chat does not use it.' );
check_noindex( AI_Chat_Bedrock_Content::is_answerable( $thanks, array( 'include_noindex' => true ) ), 'Unless the site includes such pages.' );
check_noindex( ! AI_Chat_Bedrock_Content::is_noindex( $post ), 'Yoast SEO: a post on the post type default is indexed.' );
$GLOBALS['aicfab_options']['wpseo_titles'] = array( 'noindex-page' => true, 'noindex-post' => false );
check_noindex( AI_Chat_Bedrock_Content::is_noindex( $page ) && ! AI_Chat_Bedrock_Content::is_noindex( $post ), 'Yoast SEO: a post type hidden from search hides its posts.' );
meta( 2, '_yoast_wpseo_meta-robots-noindex', '2' );
check_noindex( ! AI_Chat_Bedrock_Content::is_noindex( $page ), 'Yoast SEO: a page set to index overrides its post type.' );
meta( 2, '_yoast_wpseo_meta-robots-noindex', '0' );
check_noindex( AI_Chat_Bedrock_Content::is_noindex( $page ), 'Yoast SEO: 0 means the post type default.' );
$GLOBALS['aicfab_options']['wpseo_titles'] = 'damaged';
check_noindex( ! AI_Chat_Bedrock_Content::is_noindex( $page ), 'Yoast SEO: damaged settings index everything.' );
$GLOBALS['aicfab_meta']                   = array();
$GLOBALS['aicfab_options']['wpseo_titles'] = array();

// --- Rank Math --------------------------------------------------------------------------

define( 'RANK_MATH_VERSION', '1.0.250' );
meta( 3, 'rank_math_robots', array( 'noindex', 'nofollow' ) );
check_noindex( AI_Chat_Bedrock_Content::is_noindex( $thanks ), 'Rank Math: a page set to noindex is noindex.' );
meta( 1, 'rank_math_robots', array( 'index', 'follow' ) );
$GLOBALS['aicfab_options']['rank-math-options-titles'] = array(
	'robots_global'          => array( 'noindex' ),
	'pt_page_custom_robots'  => 'on',
	'pt_page_robots'         => array( 'index' ),
	'pt_post_custom_robots'  => 'off',
	'pt_post_robots'         => array( 'index' ),
);
check_noindex( ! AI_Chat_Bedrock_Content::is_noindex( $post ), 'Rank Math: a post set to index is indexed whatever the defaults.' );
check_noindex( ! AI_Chat_Bedrock_Content::is_noindex( $page ), 'Rank Math: a post type with its own robots uses them.' );
$GLOBALS['aicfab_meta'][1] = array( 'rank_math_robots' => '' );
check_noindex( AI_Chat_Bedrock_Content::is_noindex( $post ), 'Rank Math: otherwise the global robots apply.' );
$GLOBALS['aicfab_meta'][1] = array( 'rank_math_robots' => 'index' );
check_noindex( AI_Chat_Bedrock_Content::is_noindex( $post ), 'Rank Math: robots that are not a list are read as unset.' );
$GLOBALS['aicfab_options']['rank-math-options-titles']['pt_page_robots'] = array( 'noindex' );
check_noindex( AI_Chat_Bedrock_Content::is_noindex( $page ), 'Rank Math: a post type set to noindex hides its posts.' );
$GLOBALS['aicfab_options']['rank-math-options-titles'] = array();
check_noindex( ! AI_Chat_Bedrock_Content::is_noindex( $page ), 'Rank Math: without settings everything is indexed.' );
$GLOBALS['aicfab_meta'] = array();

// --- SEOPress ---------------------------------------------------------------------------

define( 'SEOPRESS_VERSION', '9.0' );
meta( 3, '_seopress_robots_index', 'yes' );
check_noindex( AI_Chat_Bedrock_Content::is_noindex( $thanks ) && ! AI_Chat_Bedrock_Content::is_noindex( $page ), 'SEOPress: a page set to noindex is noindex.' );
$GLOBALS['aicfab_options']['seopress_titles_option_name'] = array( 'seopress_titles_single_titles' => array( 'page' => array( 'noindex' => '1' ) ) );
check_noindex( AI_Chat_Bedrock_Content::is_noindex( $page ) && ! AI_Chat_Bedrock_Content::is_noindex( $post ), 'SEOPress: a post type set to noindex hides its posts.' );
$GLOBALS['aicfab_options']['seopress_titles_option_name'] = array( 'seopress_titles_noindex' => '1' );
check_noindex( AI_Chat_Bedrock_Content::is_noindex( $post ), 'SEOPress: noindex for the whole site hides every post.' );
$GLOBALS['aicfab_options']['seopress_titles_option_name'] = array();
$GLOBALS['aicfab_meta']                                   = array();

// --- All in One SEO ---------------------------------------------------------------------

define( 'AIOSEO_FILE', '/plugins/all-in-one-seo-pack/all_in_one_seo_pack.php' );
check_noindex( ! AI_Chat_Bedrock_Content::is_noindex( $thanks ), 'All in One SEO: without its classes nothing is read, and nothing fails.' );
eval( 'namespace AIOSEO\Plugin\Common\Models; class Post { public static function getPost( $id ) { if ( 99 === $id ) { throw new \RuntimeException( "no table" ); } return isset( $GLOBALS["aicfab_aioseo"][ $id ] ) ? $GLOBALS["aicfab_aioseo"][ $id ] : (object) array( "robots_default" => 1, "robots_noindex" => 0 ); } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- declares a namespaced stub in a test.
$GLOBALS['aicfab_aioseo'][3] = (object) array( 'robots_default' => 0, 'robots_noindex' => 1 );
$GLOBALS['aicfab_aioseo'][2] = (object) array( 'robots_default' => 1, 'robots_noindex' => 1 );
check_noindex( AI_Chat_Bedrock_Content::is_noindex( $thanks ), 'All in One SEO: a page set to noindex is noindex.' );
check_noindex( ! AI_Chat_Bedrock_Content::is_noindex( $page ), 'All in One SEO: a page on the default settings is indexed.' );
check_noindex( ! AI_Chat_Bedrock_Content::is_noindex( new WP_Post( 99 ) ), 'All in One SEO: an error reading its data indexes the page.' );

// --- Filters ----------------------------------------------------------------------------

add_filter( 'ai_chat_bedrock_post_is_noindex', function ( $noindex, $item ) { return 1 === $item->ID ? true : $noindex; } );
check_noindex( AI_Chat_Bedrock_Content::is_noindex( $post ) && ! AI_Chat_Bedrock_Content::is_answerable( $post ), 'Another SEO plugin can say a post is noindex.' );
add_filter( 'ai_chat_bedrock_is_answerable_post', function ( $answerable, $item ) { return 3 === $item->ID ? true : $answerable; } );
check_noindex( AI_Chat_Bedrock_Content::is_answerable( $thanks ), 'A site can decide for a post itself.' );
check_noindex( ! AI_Chat_Bedrock_Content::is_answerable( $private ), 'But never for a post that is not public.' );

// --- Where it applies ---------------------------------------------------------------------

$root = dirname( __DIR__ );
foreach ( array(
	'includes/class-ai-chat-bedrock-retrieval.php'     => 'keyword search',
	'includes/class-ai-chat-bedrock-embeddings.php'    => 'semantic search',
	'includes/class-ai-chat-bedrock-s3-vectors.php'    => 'S3 Vectors search',
	'includes/class-ai-chat-bedrock-site-abilities.php' => 'the site content abilities',
) as $file => $what ) {
	$source = (string) file_get_contents( $root . '/' . $file );
	check_noindex( false !== strpos( $source, 'AI_Chat_Bedrock_Content::is_answerable(' ) && false === strpos( $source, 'AI_Chat_Bedrock_Content::is_public(' ), ucfirst( $what ) . ' leaves out pages hidden from search engines.' );
}
$speech = (string) file_get_contents( $root . '/includes/class-ai-chat-bedrock-speech.php' );
check_noindex( false === strpos( $speech, 'is_answerable' ), 'Reading a post aloud is not affected, since the visitor is already on it.' );
$embeddings = (string) file_get_contents( $root . '/includes/class-ai-chat-bedrock-embeddings.php' );
$vectors    = (string) file_get_contents( $root . '/includes/class-ai-chat-bedrock-s3-vectors.php' );
check_noindex( false !== strpos( $embeddings, 'AI_Chat_Bedrock_Content::index_version( $options )' ) && false !== strpos( $vectors, 'AI_Chat_Bedrock_Content::index_version( $options )' ), 'Both stored indexes are versioned by the setting.' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: noindex checks passed\n";
