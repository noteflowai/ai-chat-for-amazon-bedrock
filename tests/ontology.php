<?php
/**
 * Site description checks, against the real class.
 *
 * The description is published to agents as the rules of the site, so what it claims is
 * asserted: only public content is described, IDs match the ones Yoast SEO and WooCommerce
 * print, and the sensitivity policy is the one the plugin enforces elsewhere.
 *
 * The site starts with one language and no shop. Polylang and WooCommerce are declared part
 * way through, the way a site adds them, since PHP cannot take a function back.
 *
 * @package AI_Chat_Bedrock
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, Squiz.Commenting, Generic.Commenting, WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride, Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['aicfab_options']   = array(
	'ai_chat_bedrock_settings'     => array( 'site_ontology' => true ),
	'ai_chat_bedrock_mcp_capability' => 'edit_posts',
);
$GLOBALS['aicfab_caps']      = array(
	'read'       => true,
	'edit_posts' => true,
);
$GLOBALS['aicfab_logged_in'] = true;
$GLOBALS['aicfab_filters']   = array();
$GLOBALS['aicfab_abilities'] = array();

class WP_Error {
	private $code;
	public function __construct( $code, $message = '' ) {
		$this->code = $code;
	}
	public function get_error_code() {
		return $this->code;
	}
}
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
class WP_Term {
	public $term_id;
	public $name;
	public $slug;
	public function __construct( $id, $name, $slug ) {
		$this->term_id = $id;
		$this->name    = $name;
		$this->slug    = $slug;
	}
}

$GLOBALS['aicfab_posts'] = array(
	new WP_Post( 10, 'Getting started &#038; setup' ),
	new WP_Post( 11, 'About us', 'page' ),
	new WP_Post( 12, 'Draft plan', 'post', 'draft' ),
	new WP_Post( 13, 'Locked notes', 'post', 'publish', 'secret' ),
	new WP_Post( 14, 'はじめに' ),
	new WP_Post( 15, '下書き', 'post', 'draft' ),
	new WP_Post( 16, 'Event', 'event' ),
	new WP_Post( 20, 'Blue widget', 'product' ),
	new WP_Post( 21, 'Hidden widget', 'product' ),
);
$GLOBALS['aicfab_terms'] = array(
	10 => array( 'category' => array( new WP_Term( 3, 'Guides', 'guides' ) ) ),
	20 => array( 'product_cat' => array( new WP_Term( 7, 'Widgets', 'widgets' ) ) ),
);

function get_option( $name, $default = false ) {
	return isset( $GLOBALS['aicfab_options'][ $name ] ) ? $GLOBALS['aicfab_options'][ $name ] : $default;
}
function apply_filters( $hook, $value ) {
	$args = func_get_args();
	if ( isset( $GLOBALS['aicfab_filters'][ $hook ] ) ) {
		$args[1] = $value;
		return call_user_func_array( $GLOBALS['aicfab_filters'][ $hook ], array_slice( $args, 1 ) );
	}
	return $value;
}
function has_filter( $hook ) {
	return false;
}
function __( $text, $domain = null ) {
	return $text;
}
function absint( $value ) {
	return abs( (int) $value );
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function wp_strip_all_tags( $text ) {
	return strip_tags( (string) $text );
}
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function is_user_logged_in() {
	return (bool) $GLOBALS['aicfab_logged_in'];
}
function current_user_can( $capability ) {
	return ! empty( $GLOBALS['aicfab_caps'][ $capability ] );
}
function get_bloginfo( $what ) {
	$info = array(
		'name'        => 'Example <b>Lab</b>',
		'description' => 'Robots, explained',
		'language'    => 'en-US',
	);
	return isset( $info[ $what ] ) ? $info[ $what ] : '';
}
function home_url( $path = '' ) {
	return 'https://example.test' . $path;
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
	return 'https://example.test/' . $post->post_type . '/' . $post->ID . '/';
}
function get_post_time( $format, $gmt, $post ) {
	return '2026-10-01T08:00:00+00:00';
}
function get_post_modified_time( $format, $gmt, $post ) {
	return '2026-10-02T09:30:00+00:00';
}
function is_post_type_viewable( $type ) {
	return in_array( $type, array( 'post', 'page', 'product', 'event' ), true );
}
function get_post_types( $args, $output ) {
	$types = array( 'post', 'page', 'attachment', 'event' );
	if ( class_exists( 'WooCommerce' ) ) {
		$types[] = 'product';
	}
	return $types;
}
function get_taxonomies( $args, $output ) {
	$taxonomies = array( 'category', 'post_tag', 'post_format' );
	if ( class_exists( 'WooCommerce' ) ) {
		$taxonomies[] = 'product_cat';
	}
	return $taxonomies;
}
function get_the_terms( $post, $taxonomy ) {
	return isset( $GLOBALS['aicfab_terms'][ $post->ID ][ $taxonomy ] ) ? $GLOBALS['aicfab_terms'][ $post->ID ][ $taxonomy ] : false;
}
function get_term_link( $term ) {
	return 'https://example.test/term/' . $term->slug . '/';
}
function wp_count_posts( $type ) {
	$counts = array(
		'post'    => 3,
		'page'    => 1,
		'event'   => 1,
		'product' => 2,
	);
	return (object) array( 'publish' => isset( $counts[ $type ] ) ? $counts[ $type ] : 0 );
}
function wp_register_ability( $name, $args ) {
	$GLOBALS['aicfab_abilities'][ $name ] = $args;
}

class AI_Chat_Bedrock_Abilities {
	const CATEGORY = 'ai-chat-bedrock';
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-tool-policy.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-content.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-ontology.php';

$failures = array();
function check_onto( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}
function onto_type( $description, $name ) {
	foreach ( $description['types'] as $type ) {
		if ( $name === $type['name'] ) {
			return $type;
		}
	}
	return null;
}
function onto_relation( $description, $subject, $predicate, $object ) {
	foreach ( $description['relations'] as $relation ) {
		if ( array( $subject, $predicate, $object ) === array( $relation['subject'], $relation['predicate'], $relation['object'] ) ) {
			return $relation;
		}
	}
	return null;
}

// --- Policy ----------------------------------------------------------------------

check_onto( AI_Chat_Bedrock_Ontology::may( 'public', 'visitor_chat' ) && AI_Chat_Bedrock_Ontology::may( 'public', 'index' ), 'Public content may reach the model and the index.' );
foreach ( array( 'member', 'personal', 'financial' ) as $aicfab_class ) {
	check_onto( ! AI_Chat_Bedrock_Ontology::may( $aicfab_class, 'index' ), $aicfab_class . ' data is never indexed.' );
	check_onto( ! AI_Chat_Bedrock_Ontology::may( $aicfab_class, 'agent' ), $aicfab_class . ' data is never returned to an agent as is.' );
}
check_onto( 'no' === AI_Chat_Bedrock_Ontology::rule( 'member', 'visitor_chat' ), 'Members-only text never reaches the chat model.' );
check_onto( 'own' === AI_Chat_Bedrock_Ontology::rule( 'personal', 'visitor_chat' ), 'Personal data reaches a chat only for the person it is about.' );
check_onto( 'no' === AI_Chat_Bedrock_Ontology::rule( 'financial', 'visitor_chat' ) && 'aggregate' === AI_Chat_Bedrock_Ontology::rule( 'financial', 'agent' ), 'Store figures reach an agent only as totals, never a visitor chat.' );
check_onto( 'no' === AI_Chat_Bedrock_Ontology::rule( 'secret', 'analytics' ) && 'no' === AI_Chat_Bedrock_Ontology::rule( 'public', 'email' ), 'An unknown class or use is refused.' );
check_onto( AI_Chat_Bedrock_Ontology::may_show_count( 0 ) && AI_Chat_Bedrock_Ontology::may_show_count( 5 ) && AI_Chat_Bedrock_Ontology::may_show_count( 120 ), 'Zero and groups of five or more may be shown.' );
check_onto( ! AI_Chat_Bedrock_Ontology::may_show_count( 1 ) && ! AI_Chat_Bedrock_Ontology::may_show_count( 4 ), 'Groups of one to four people are withheld.' );

// --- One language, no shop -------------------------------------------------------

$aicfab_site = AI_Chat_Bedrock_Ontology::describe();
check_onto( '1' === $aicfab_site['ontology_version'] && 'https://schema.org/' === $aicfab_site['vocabulary'], 'The description names its version and vocabulary.' );
check_onto( 'https://example.test/#website' === $aicfab_site['site']['@id'] && 'https://example.test/#organization' === $aicfab_site['site']['publisher']['@id'], 'Site and publisher IDs match the ones Yoast SEO prints.' );
check_onto( 'Example Lab' === $aicfab_site['site']['name'], 'The site name is plain text.' );
check_onto( array( 'en-US' ) === $aicfab_site['site']['inLanguage'] && 1 === count( $aicfab_site['languages'] ) && true === $aicfab_site['languages'][0]['default'], 'A single-language site reports its locale.' );
check_onto( null === onto_type( $aicfab_site, 'Product' ) && null === onto_type( $aicfab_site, 'Order' ), 'Without WooCommerce there are no products or orders.' );
check_onto( null === onto_relation( $aicfab_site, 'Article', 'workTranslation', 'Article' ), 'Without a translation plugin there are no translation links.' );
check_onto( null === onto_relation( $aicfab_site, 'Product', 'offers', 'Offer' ), 'Relations to absent types are left out.' );

$aicfab_article = onto_type( $aicfab_site, 'Article' );
check_onto( 'https://schema.org/Article' === $aicfab_article['iri'] && array( 'post' ) === $aicfab_article['post_types'] && 3 === $aicfab_article['count'], 'Posts are Articles with their published count.' );
check_onto( ! isset( $aicfab_article['count_by_language'] ), 'One language has no per-language counts.' );
$aicfab_props = array_column( $aicfab_article['properties'], 'sensitivity', 'name' );
check_onto( 'public' === $aicfab_props['headline'] && 'member' === $aicfab_props['hasPart'], 'Each property carries its sensitivity; members-only parts are marked.' );
check_onto( in_array( 'search_posts', $aicfab_article['tools'], true ), 'A type names the tools that read it.' );

$aicfab_other = onto_type( $aicfab_site, 'CreativeWork' );
check_onto( array( 'event' ) === $aicfab_other['post_types'] && 1 === $aicfab_other['count'], 'A public custom post type is described as CreativeWork.' );
foreach ( $aicfab_site['types'] as $aicfab_type ) {
	check_onto( ! in_array( 'attachment', isset( $aicfab_type['post_types'] ) ? $aicfab_type['post_types'] : array(), true ), 'Attachments are not content types.' );
}
$aicfab_term_type = onto_type( $aicfab_site, 'DefinedTerm' );
check_onto( array( 'category', 'post_tag' ) === $aicfab_term_type['taxonomies'], 'Public taxonomies are terms; post formats are not.' );

$aicfab_gap = onto_type( $aicfab_site, 'ContentGap' );
check_onto( '' === $aicfab_gap['iri'] && '' === $aicfab_gap['properties'][0]['iri'], 'A plugin concept claims no schema.org IRI.' );
check_onto( 'personal' === onto_type( $aicfab_site, 'Question' )['sensitivity'] && array() === onto_type( $aicfab_site, 'Question' )['tools'], 'Visitor questions are personal and no tool returns them.' );
$aicfab_gap_rel = onto_relation( $aicfab_site, 'ContentGap', 'groups', 'Question' );
check_onto( null !== $aicfab_gap_rel && '' === $aicfab_gap_rel['iri'], 'A plugin relation claims no schema.org IRI.' );
check_onto( 'https://schema.org/about' === onto_relation( $aicfab_site, 'Article', 'about', 'DefinedTerm' )['iri'], 'A schema.org relation names its IRI.' );

$aicfab_classes = array_column( $aicfab_site['sensitivity'], null, 'class' );
check_onto( array( 'public', 'member', 'personal', 'financial' ) === array_keys( $aicfab_classes ) && 'own' === $aicfab_classes['personal']['visitor_chat'], 'The policy is published with every class.' );
check_onto( array() === $aicfab_site['metrics'], 'No metrics are listed until something provides them.' );
check_onto( 1 === preg_match( '/^\d{4}-\d\d-\d\dT/', $aicfab_site['generated_at'] ), 'The description is timestamped.' );
check_onto( false === strpos( json_encode( $aicfab_site ), 'Draft plan' ) && false === strpos( json_encode( $aicfab_site ), 'Locked notes' ), 'No post title leaks into the site description.' );

// --- One entity ------------------------------------------------------------------

$aicfab_one = AI_Chat_Bedrock_Ontology::describe( array( 'id' => 10 ) );
$aicfab_node = $aicfab_one['entity'];
check_onto( 'https://example.test/post/10/#article' === $aicfab_node['@id'] && 'Article' === $aicfab_node['@type'], 'A post is the Article node Yoast SEO prints.' );
check_onto( 'Getting started & setup' === $aicfab_node['name'], 'The name is decoded text.' );
check_onto( array( '@id' => 'https://example.test/#website' ) === $aicfab_node['isPartOf'], 'An entity is part of the site.' );
check_onto( '2026-10-01T08:00:00+00:00' === $aicfab_node['datePublished'] && '2026-10-02T09:30:00+00:00' === $aicfab_node['dateModified'], 'Dates are ISO 8601 in UTC.' );
check_onto( 'https://example.test/term/guides/' === $aicfab_node['about'][0]['@id'] && 'category' === $aicfab_node['about'][0]['inDefinedTermSet'], 'Terms are linked by their archive URL.' );
check_onto( ! isset( $aicfab_node['inLanguage'] ) && ! isset( $aicfab_node['workTranslation'] ), 'One language adds no language or translations.' );
check_onto( 'https://example.test/page/11/' === AI_Chat_Bedrock_Ontology::describe( array( 'id' => 11 ) )['entity']['@id'], 'A page is its own URL, as Yoast SEO has it.' );
check_onto( 'CreativeWork' === AI_Chat_Bedrock_Ontology::describe( array( 'id' => 16 ) )['entity']['@type'], 'A custom type is a CreativeWork.' );

foreach ( array( 12, 13, 999, 0 ) as $aicfab_id ) {
	$aicfab_refused = AI_Chat_Bedrock_Ontology::describe( array( 'id' => $aicfab_id ) );
	if ( 0 === $aicfab_id ) {
		check_onto( isset( $aicfab_refused['types'] ), 'No ID describes the whole site.' );
		continue;
	}
	check_onto( is_wp_error( $aicfab_refused ) && 'aicfab_post_not_available' === $aicfab_refused->get_error_code(), 'Post ' . $aicfab_id . ' is not described: drafts, protected and missing posts are refused.' );
}
$GLOBALS['aicfab_filters']['ai_chat_bedrock_is_public_post'] = function ( $public, $post ) {
	return 10 === $post->ID ? false : $public;
};
check_onto( is_wp_error( AI_Chat_Bedrock_Ontology::describe( array( 'id' => 10 ) ) ), 'A post the site keeps from answers is not described either.' );
unset( $GLOBALS['aicfab_filters']['ai_chat_bedrock_is_public_post'] );

// --- Passages ----------------------------------------------------------------------

$aicfab_passages = array(
	array(
		'source'  => 'wordpress',
		'post_id' => 11,
		'title'   => 'About us',
	),
	array(
		'source' => 'knowledge_base',
		'title'  => 'KB',
	),
	array(
		'source'  => 'semantic',
		'post_id' => 999,
	),
);
$aicfab_annotated = AI_Chat_Bedrock_Ontology::annotate_passages( $aicfab_passages );
check_onto( 'WebPage' === $aicfab_annotated[0]['entity_type'] && ! isset( $aicfab_annotated[0]['language'] ), 'A site passage gets its type.' );
check_onto( $aicfab_passages[1] === $aicfab_annotated[1] && $aicfab_passages[2] === $aicfab_annotated[2], 'Knowledge base passages and vanished posts are left as they are.' );

// --- Switched off ------------------------------------------------------------------

$GLOBALS['aicfab_options']['ai_chat_bedrock_settings'] = array();
check_onto( ! AI_Chat_Bedrock_Ontology::enabled(), 'The site description is off by default.' );
check_onto( $aicfab_passages === AI_Chat_Bedrock_Ontology::annotate_passages( $aicfab_passages ), 'Off, passages are not changed.' );
$aicfab_ontology = new AI_Chat_Bedrock_Ontology();
$aicfab_ontology->register();
check_onto( array() === $GLOBALS['aicfab_abilities'], 'Off, no ability is registered.' );
$GLOBALS['aicfab_filters']['ai_chat_bedrock_ontology_enabled'] = '__return_true_for_test';
function __return_true_for_test() {
	return true;
}
check_onto( AI_Chat_Bedrock_Ontology::enabled(), 'A filter can turn it on.' );
unset( $GLOBALS['aicfab_filters']['ai_chat_bedrock_ontology_enabled'] );
$GLOBALS['aicfab_options']['ai_chat_bedrock_settings'] = array( 'site_ontology' => true );

// --- The ability -------------------------------------------------------------------

$aicfab_ontology->register();
$aicfab_ontology->register();
$aicfab_ability = isset( $GLOBALS['aicfab_abilities']['ai-chat-bedrock/describe-site'] ) ? $GLOBALS['aicfab_abilities']['ai-chat-bedrock/describe-site'] : array();
check_onto( 1 === count( $GLOBALS['aicfab_abilities'] ) && ! empty( $aicfab_ability ), 'The ability is registered once.' );
check_onto( 'ai-chat-bedrock' === $aicfab_ability['category'], 'The ability is in the plugin category.' );
check_onto( true === $aicfab_ability['meta']['annotations']['readonly'] && true === $aicfab_ability['meta']['annotations']['idempotent'] && ! isset( $aicfab_ability['meta']['annotations']['destructive'] ), 'The ability declares it only reads.' );
check_onto( ! isset( $aicfab_ability['input_schema']['required'] ), 'The post ID is optional.' );
check_onto( isset( call_user_func( $aicfab_ability['execute_callback'], array() )['types'] ), 'The ability returns the description.' );
check_onto( true === call_user_func( $aicfab_ability['permission_callback'] ), 'An account with the tool capability may call it.' );
$GLOBALS['aicfab_logged_in'] = false;
check_onto( false === call_user_func( $aicfab_ability['permission_callback'] ), 'A signed-out caller may not.' );
$GLOBALS['aicfab_logged_in'] = true;
$GLOBALS['aicfab_caps']      = array( 'read' => true );
check_onto( false === call_user_func( $aicfab_ability['permission_callback'] ), 'An account without the tool capability may not.' );
$GLOBALS['aicfab_caps'] = array(
	'read'       => true,
	'edit_posts' => true,
);

// --- Polylang ------------------------------------------------------------------------

$GLOBALS['aicfab_languages']    = array(
	'zh' => array( 10, 11, 16 ),
	'en' => array( 14 ),
	'ja' => array(),
);
$GLOBALS['aicfab_translations'] = array( 10 => 1, 14 => 1, 15 => 1 );
if ( ! function_exists( 'pll_languages_list' ) ) {
	function pll_languages_list( $args ) {
		return 'name' === $args['fields'] ? array( '中文', 'English', '日本語' ) : array( 'zh', 'en', 'ja' );
	}
	function pll_default_language( $field ) {
		return 'en';
	}
	function pll_get_post_language( $post_id, $field ) {
		foreach ( $GLOBALS['aicfab_languages'] as $slug => $ids ) {
			if ( in_array( (int) $post_id, $ids, true ) ) {
				return $slug;
			}
		}
		return false;
	}
	function pll_get_post_translations( $post_id ) {
		// 10 (zh) is translated as 14 (en), with a Japanese draft 15 that must not show.
		return isset( $GLOBALS['aicfab_translations'][ $post_id ] ) ? array(
			'zh' => 10,
			'en' => 14,
			'ja' => 15,
		) : array();
	}
	function pll_is_translated_post_type( $type ) {
		return in_array( $type, array( 'post', 'page' ), true );
	}
	function pll_count_posts( $lang, $args ) {
		$counts = array(
			'zh' => array(
				'post' => 2,
				'page' => 1,
			),
			'en' => array(
				'post' => 1,
				'page' => 0,
			),
		);
		return isset( $counts[ $lang ][ $args['post_type'] ] ) ? $counts[ $lang ][ $args['post_type'] ] : 0;
	}
}

$aicfab_site = AI_Chat_Bedrock_Ontology::describe();
check_onto( array( 'en', 'zh', 'ja' ) === array_column( $aicfab_site['languages'], 'code' ) && '中文' === $aicfab_site['languages'][1]['name'], 'Languages come from Polylang, the default first.' );
check_onto( array( 'en', 'zh', 'ja' ) === $aicfab_site['site']['inLanguage'], 'The site lists every language.' );
check_onto(
	array(
		'en' => 1,
		'zh' => 2,
		'ja' => 0,
	) === onto_type( $aicfab_site, 'Article' )['count_by_language'],
	'Articles are counted per language.'
);
check_onto( ! isset( onto_type( $aicfab_site, 'CreativeWork' )['count_by_language'] ), 'An untranslated type has no per-language counts.' );
check_onto( null !== onto_relation( $aicfab_site, 'Article', 'workTranslation', 'Article' ), 'Translations are a relation.' );

$aicfab_node = AI_Chat_Bedrock_Ontology::describe( array( 'id' => 10 ) )['entity'];
check_onto( 'zh' === $aicfab_node['inLanguage'], 'An entity has its language.' );
check_onto(
	array(
		array(
			'@id'        => 'https://example.test/post/14/#article',
			'inLanguage' => 'en',
		),
	) === $aicfab_node['workTranslation'],
	'Only published translations are linked, never the post itself or a draft.'
);
$aicfab_annotated = AI_Chat_Bedrock_Ontology::annotate_passages( array( array( 'post_id' => 14 ) ) );
check_onto( 'Article' === $aicfab_annotated[0]['entity_type'] && 'en' === $aicfab_annotated[0]['language'], 'A passage gets its language.' );

// --- WooCommerce -----------------------------------------------------------------------

if ( ! class_exists( 'WooCommerce' ) ) {
	class WooCommerce {}
	class AICFAB_Test_Product {
		public $id;
		public function __construct( $id ) {
			$this->id = $id;
		}
		public function get_price() {
			return '19.50';
		}
		public function get_sku() {
			return 'BW-1';
		}
		public function get_stock_status() {
			return 'onbackorder';
		}
	}
	class AI_Chat_Bedrock_WooCommerce {
		public static function is_listable( $product ) {
			return 21 !== $product->id;
		}
	}
	function wc_get_product( $id ) {
		return new AICFAB_Test_Product( $id );
	}
	function get_woocommerce_currency() {
		return 'USD';
	}
}

$aicfab_site = AI_Chat_Bedrock_Ontology::describe();
check_onto( 2 === onto_type( $aicfab_site, 'Product' )['count'] && array( 'event' ) === onto_type( $aicfab_site, 'CreativeWork' )['post_types'], 'Products are their own type, not CreativeWork.' );
check_onto( array( 'category', 'post_tag', 'product_cat' ) === onto_type( $aicfab_site, 'DefinedTerm' )['taxonomies'], 'Product categories are terms.' );
$aicfab_order = onto_type( $aicfab_site, 'Order' );
check_onto( 'personal' === $aicfab_order['sensitivity'] && array() === $aicfab_order['tools'] && ! isset( $aicfab_order['count'] ), 'Orders are personal, uncounted and read by no tool.' );
check_onto( 'financial' === array_column( $aicfab_order['properties'], 'sensitivity', 'name' )['totalPaymentDue'], 'Order totals are financial.' );
check_onto( null !== onto_relation( $aicfab_site, 'Product', 'offers', 'Offer' ) && null !== onto_relation( $aicfab_site, 'Order', 'orderedItem', 'Product' ), 'Shop relations appear with the shop.' );

$aicfab_product = AI_Chat_Bedrock_Ontology::describe( array( 'id' => 20 ) )['entity'];
check_onto( 'https://example.test/product/20/#product' === $aicfab_product['@id'] && 'Product' === $aicfab_product['@type'], 'A product is the Product node WooCommerce prints.' );
check_onto( 'BW-1' === $aicfab_product['sku'], 'A product has its SKU.' );
check_onto(
	array(
		'@type'         => 'Offer',
		'price'         => '19.50',
		'priceCurrency' => 'USD',
		'availability'  => 'https://schema.org/BackOrder',
	) === $aicfab_product['offers'],
	'A product has its offer.'
);
check_onto( 'https://example.test/term/widgets/' === $aicfab_product['category'][0]['@id'] && ! isset( $aicfab_product['about'] ), 'Product categories fill category, not about.' );
check_onto( is_wp_error( AI_Chat_Bedrock_Ontology::describe( array( 'id' => 21 ) ) ), 'A product the shop hides is not described.' );

// --- Extending -------------------------------------------------------------------------

$GLOBALS['aicfab_filters']['ai_chat_bedrock_ontology_types'] = function ( $types ) {
	$types['Event'] = array(
		'label'       => 'Events',
		'sensitivity' => 'public',
		'post_types'  => array( 'event' ),
		'properties'  => array( 'startDate' => 'public' ),
		'tools'       => array(),
	);
	return $types;
};
$aicfab_site = AI_Chat_Bedrock_Ontology::describe();
check_onto( array( 'event' ) === onto_type( $aicfab_site, 'Event' )['post_types'] && array() === onto_type( $aicfab_site, 'CreativeWork' )['post_types'] && 0 === onto_type( $aicfab_site, 'CreativeWork' )['count'], 'A filter can map a custom post type to its own type.' );
check_onto( 'Event' === AI_Chat_Bedrock_Ontology::describe( array( 'id' => 16 ) )['entity']['@type'], 'The entity follows the mapping.' );
unset( $GLOBALS['aicfab_filters']['ai_chat_bedrock_ontology_types'] );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: ontology checks passed\n";
