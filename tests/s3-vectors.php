<?php
/**
 * Standalone tests for the Amazon S3 Vectors index.
 *
 * The AWS client is replaced by a recorder, so these check the requests the plugin makes and
 * what it does with the answers: what goes into the index, what a search may quote, and that
 * deletions are not lost.
 *
 * Run: php tests/s3-vectors.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['aicfab_options'] = array();
$GLOBALS['aicfab_meta']    = array();
$GLOBALS['aicfab_posts']   = array();
$GLOBALS['aicfab_calls']   = array();
$GLOBALS['aicfab_replies'] = array();
$GLOBALS['aicfab_fail']    = array();
$GLOBALS['aicfab_home']    = 'https://example.test';

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_options'] ) ? $GLOBALS['aicfab_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['aicfab_options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['aicfab_options'][ $name ] );
	return true;
}
function apply_filters( $hook, $value ) {
	return $value;
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
function sanitize_text_field( $value ) {
	return trim( (string) $value );
}
function wp_strip_all_tags( $value ) {
	return strip_tags( (string) $value );
}
function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/' );
}
function home_url( $path = '' ) {
	return $GLOBALS['aicfab_home'] . $path;
}
function get_current_blog_id() {
	return 1;
}
function get_post( $id ) {
	return isset( $GLOBALS['aicfab_posts'][ $id ] ) ? $GLOBALS['aicfab_posts'][ $id ] : null;
}
function get_the_title( $post ) {
	return $post->post_title;
}
function get_permalink( $post ) {
	return 'https://example.test/?p=' . $post->ID;
}
function get_post_meta( $id, $key, $single = false ) {
	return isset( $GLOBALS['aicfab_meta'][ $id ][ $key ] ) ? $GLOBALS['aicfab_meta'][ $id ][ $key ] : '';
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['aicfab_meta'][ $id ][ $key ] = $value;
	return true;
}
function delete_post_meta( $id, $key ) {
	unset( $GLOBALS['aicfab_meta'][ $id ][ $key ] );
	return true;
}
function delete_post_meta_by_key( $key ) {
	foreach ( array_keys( $GLOBALS['aicfab_meta'] ) as $id ) {
		unset( $GLOBALS['aicfab_meta'][ $id ][ $key ] );
	}
	return true;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function pll_get_post_language( $id, $field ) {
	return isset( $GLOBALS['aicfab_posts'][ $id ]->lang ) ? $GLOBALS['aicfab_posts'][ $id ]->lang : '';
}

class WP_Post {
	public $ID                = 0;
	public $post_title        = '';
	public $post_content      = '';
	public $post_status       = 'publish';
	public $post_password     = '';
	public $post_type         = 'post';
	public $post_modified_gmt = '';
	public $lang              = '';
}

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

class WP_Query {
	public $posts       = array();
	public $found_posts = 0;
	public function __construct( $args = array() ) {
		$GLOBALS['aicfab_queries'][] = $args;
		$key = isset( $args['meta_query'][0]['key'] ) ? $args['meta_query'][0]['key'] : '';
		foreach ( $GLOBALS['aicfab_meta'] as $id => $meta ) {
			if ( '' !== $key && isset( $meta[ $key ] ) ) {
				$this->posts[] = $id;
			}
		}
		$this->found_posts = count( $this->posts );
	}
}

class AI_Chat_Bedrock_Retrieval {
	const MAX_PASSAGES      = 8;
	const MAX_PASSAGE_CHARS = 1200;
}

class AI_Chat_Bedrock_Embeddings {
	const META_STATE = '_aicfab_index_state';
	public static $last = '';
	public static function failed( $error ) {
		self::$last = $error->get_error_message();
		return $error->get_error_code();
	}
	public static function model( $options = null ) {
		return 'amazon.titan-embed-text-v2:0';
	}
	public static function dimension( $model ) {
		return 'amazon.titan-embed-text-v2:0' === $model ? 1024 : 0;
	}
	public static function post_types( $options = null ) {
		return array( 'post', 'page' );
	}
}

class AI_Chat_Bedrock_AWS {
	public function embed( $text, $model, $purpose = 'document' ) {
		if ( false !== strpos( $text, 'EMBED_FAILS' ) ) {
			return new WP_Error( 'aicfab_throttled', 'Too many requests' );
		}
		return array( 0.1, 0.2, 0.3 );
	}
	public function s3_vectors( $operation, $payload, $region ) {
		$GLOBALS['aicfab_calls'][] = array(
			'op'      => $operation,
			'payload' => $payload,
			'region'  => $region,
		);
		if ( in_array( $operation, $GLOBALS['aicfab_fail'], true ) ) {
			return new WP_Error( 'aicfab_s3v_ServiceUnavailableException', 'Unavailable' );
		}
		if ( ! empty( $GLOBALS['aicfab_replies'][ $operation ] ) ) {
			return array_shift( $GLOBALS['aicfab_replies'][ $operation ] );
		}
		return array();
	}
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-content.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-s3-vectors.php';

$failures = array();
function check_s3v( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

function s3v_post( $id, $title, $content, $lang = 'en', $status = 'publish' ) {
	$post                           = new WP_Post();
	$post->ID                       = $id;
	$post->post_title               = $title;
	$post->post_content             = $content;
	$post->post_status              = $status;
	$post->lang                     = $lang;
	$post->post_modified_gmt        = (string) microtime( true );
	$GLOBALS['aicfab_posts'][ $id ] = $post;
	return $post;
}

function s3v_calls( $operation ) {
	return array_values(
		array_filter(
			$GLOBALS['aicfab_calls'],
			function ( $call ) use ( $operation ) {
				return $call['op'] === $operation;
			}
		)
	);
}

$options = array(
	'aws_region'        => 'us-east-1',
	'vector_store'      => 's3_vectors',
	's3_vectors_bucket' => 'site-vectors',
	's3_vectors_index'  => 'posts',
	's3_vectors_region' => 'ap-northeast-1',
);
$model   = 'amazon.titan-embed-text-v2:0';
$site    = AI_Chat_Bedrock_S3_Vectors::site_id();

// --- Configuration -------------------------------------------------------------

check_s3v( AI_Chat_Bedrock_S3_Vectors::enabled( $options ), 'A bucket and index enable the store.' );
check_s3v( ! AI_Chat_Bedrock_S3_Vectors::enabled( array_merge( $options, array( 'vector_store' => 'post_meta' ) ) ), 'Choosing post meta disables it.' );
check_s3v( ! AI_Chat_Bedrock_S3_Vectors::enabled( array_merge( $options, array( 's3_vectors_index' => '' ) ) ), 'A missing index disables it.' );
check_s3v( ! AI_Chat_Bedrock_S3_Vectors::enabled( array_merge( $options, array( 's3_vectors_bucket' => 'Bad_Name' ) ) ), 'An invalid bucket name disables it.' );
foreach ( array( 'ab', '-abc', 'abc-', 'ABC', 'a_b_c', str_repeat( 'a', 64 ) ) as $bad ) {
	check_s3v( ! AI_Chat_Bedrock_S3_Vectors::valid_name( $bad ), 'Invalid name refused: ' . $bad );
}
foreach ( array( 'abc', 'site-vectors', 'posts.v1', str_repeat( 'a', 63 ) ) as $good ) {
	check_s3v( AI_Chat_Bedrock_S3_Vectors::valid_name( $good ), 'Valid name accepted: ' . $good );
}
$config = AI_Chat_Bedrock_S3_Vectors::config( array_merge( $options, array( 's3_vectors_region' => '' ) ) );
check_s3v( 'us-east-1' === $config['region'], 'Without its own Region the index is in the Bedrock Region.' );
check_s3v( 1 === preg_match( '/^[a-z0-9]{1,32}$/', $site ), 'The site identifier is short and safe: ' . $site );
check_s3v( $site . ':12#3' === AI_Chat_Bedrock_S3_Vectors::key( 12, 3 ), 'Keys name the site, post and passage.' );

// --- Indexing ------------------------------------------------------------------

$long = '';
for ( $i = 1; $i <= 6; $i++ ) {
	$long .= '<p>Section ' . $i . ' ' . str_repeat( 'Shipping takes three days. ', 20 ) . '</p>';
}
$post = s3v_post( 1, 'Shipping', $long, 'en' );
check_s3v( 'indexed' === AI_Chat_Bedrock_S3_Vectors::index_post( $post, $model, false, $options ), 'A public post is indexed.' );
$puts = s3v_calls( 'PutVectors' );
check_s3v( 1 === count( $puts ), 'One PutVectors request per batch.' );
$vectors = $puts[0]['payload']['vectors'];
check_s3v( count( $vectors ) > 1, 'A long post becomes several passages.' );
check_s3v( 'ap-northeast-1' === $puts[0]['region'], 'Requests go to the vector Region.' );
check_s3v( 'site-vectors' === $puts[0]['payload']['vectorBucketName'] && 'posts' === $puts[0]['payload']['indexName'], 'Requests name the bucket and index.' );
check_s3v( $site . ':1#0' === $vectors[0]['key'] && $site . ':1#1' === $vectors[1]['key'], 'Passages are keyed in order.' );
$meta = $vectors[1]['metadata'];
check_s3v( $site === $meta['site'] && 1 === $meta['post_id'] && 'en' === $meta['lang'] && 1 === $meta['chunk'], 'Metadata carries site, post, language and passage.' );
check_s3v( 'Shipping' === $meta['title'] && '' !== $meta['text'], 'The title and passage text are stored for quoting.' );
check_s3v( array( 0.1, 0.2, 0.3 ) === $vectors[0]['data']['float32'], 'The vector is sent as float32.' );
check_s3v( count( $vectors ) === (int) get_post_meta( 1, AI_Chat_Bedrock_S3_Vectors::META_CHUNKS, true ), 'The passage count is recorded.' );
check_s3v( AI_Chat_Bedrock_S3_Vectors::reference( $model, $options ) === get_post_meta( 1, AI_Chat_Bedrock_Embeddings::META_STATE, true ), 'The post is marked done for this index and model.' );

$GLOBALS['aicfab_calls'] = array();
check_s3v( 'skipped' === AI_Chat_Bedrock_S3_Vectors::index_post( $post, $model, false, $options ), 'An unchanged post is skipped.' );
check_s3v( array() === $GLOBALS['aicfab_calls'], 'A skipped post costs no request.' );
check_s3v( 'indexed' === AI_Chat_Bedrock_S3_Vectors::index_post( $post, $model, true, $options ), 'Force re-indexes an unchanged post.' );

// A shorter version deletes the passages it no longer has.
$old_count = (int) get_post_meta( 1, AI_Chat_Bedrock_S3_Vectors::META_CHUNKS, true );
$GLOBALS['aicfab_calls'] = array();
$post = s3v_post( 1, 'Shipping', '<p>Shipping takes three days.</p>', 'en' );
AI_Chat_Bedrock_S3_Vectors::index_post( $post, $model, false, $options );
$deletes = s3v_calls( 'DeleteVectors' );
check_s3v( 1 === count( $deletes ) && count( $deletes[0]['payload']['keys'] ) === $old_count - 1, 'Stale passages of a shortened post are deleted.' );
check_s3v( ! in_array( $site . ':1#0', $deletes[0]['payload']['keys'], true ), 'The passage still in use is kept.' );

// A post that is no longer public is removed rather than indexed.
$GLOBALS['aicfab_calls'] = array();
$draft = s3v_post( 1, 'Shipping', '<p>Now a draft.</p>', 'en', 'draft' );
check_s3v( 'unsupported' === AI_Chat_Bedrock_S3_Vectors::index_post( $draft, $model, false, $options ), 'A draft is not indexed.' );
check_s3v( 1 === count( s3v_calls( 'DeleteVectors' ) ) && array() === s3v_calls( 'PutVectors' ), 'A post that became a draft has its passages deleted.' );
check_s3v( '' === get_post_meta( 1, AI_Chat_Bedrock_S3_Vectors::META_REF, true ), 'Its index record is forgotten.' );

// A failed embedding stops the post and reports why.
$GLOBALS['aicfab_calls'] = array();
$bad = s3v_post( 2, 'Broken', '<p>EMBED_FAILS here</p>' );
check_s3v( 'aicfab_throttled' === AI_Chat_Bedrock_S3_Vectors::index_post( $bad, $model, false, $options ), 'An embedding error is returned as its code.' );
check_s3v( 'Too many requests' === AI_Chat_Bedrock_Embeddings::$last, 'The error message is kept for the admin.' );
check_s3v( array() === s3v_calls( 'PutVectors' ) && '' === get_post_meta( 2, AI_Chat_Bedrock_Embeddings::META_STATE, true ), 'Nothing is written for a failed post.' );

// A failed upload leaves the post not done, so it is retried.
$GLOBALS['aicfab_fail'] = array( 'PutVectors' );
$post3 = s3v_post( 3, 'Returns', '<p>Returns are free.</p>' );
check_s3v( 'aicfab_s3v_ServiceUnavailableException' === AI_Chat_Bedrock_S3_Vectors::index_post( $post3, $model, false, $options ), 'A PutVectors error is returned.' );
check_s3v( '' === get_post_meta( 3, AI_Chat_Bedrock_Embeddings::META_STATE, true ), 'A post whose upload failed is not marked done.' );
$GLOBALS['aicfab_fail'] = array();

// --- Deletions are not lost ----------------------------------------------------

$GLOBALS['aicfab_fail'] = array( 'DeleteVectors' );
check_s3v( false === AI_Chat_Bedrock_S3_Vectors::delete_keys( array( 'a', 'b' ), $options ), 'A failed deletion is reported.' );
check_s3v( array( 'a', 'b' ) === get_option( AI_Chat_Bedrock_S3_Vectors::QUEUE_OPTION ), 'Failed keys are queued.' );
check_s3v( 2 === AI_Chat_Bedrock_S3_Vectors::process_queue( $options ), 'Keys stay queued while deletion keeps failing.' );
$GLOBALS['aicfab_fail'] = array();
check_s3v( 0 === AI_Chat_Bedrock_S3_Vectors::process_queue( $options ), 'The queue drains once deletion works.' );
check_s3v( array() === get_option( AI_Chat_Bedrock_S3_Vectors::QUEUE_OPTION, array() ), 'The queue option is removed.' );

// --- Search --------------------------------------------------------------------

$GLOBALS['aicfab_meta']  = array();
$GLOBALS['aicfab_calls'] = array();
$reference               = AI_Chat_Bedrock_S3_Vectors::reference( $model, $options );
s3v_post( 10, 'Refunds', '<p>Refunds within 30 days.</p>', 'en' );
s3v_post( 11, 'Members', '<p>Secret.</p>', 'en', 'private' );
s3v_post( 12, 'Returns', '<p>Returns policy.</p>', 'en' );
update_post_meta( 10, AI_Chat_Bedrock_Embeddings::META_STATE, $reference );
update_post_meta( 12, AI_Chat_Bedrock_Embeddings::META_STATE, $reference );

$GLOBALS['aicfab_replies']['QueryVectors'] = array(
	array(
		'vectors' => array(
			array( 'key' => $site . ':11#0', 'distance' => 0.05, 'metadata' => array( 'site' => $site, 'post_id' => 11, 'text' => 'Secret members text' ) ),
			array( 'key' => 'other:10#0', 'distance' => 0.01, 'metadata' => array( 'site' => 'other', 'post_id' => 10, 'text' => 'Another site' ) ),
			array( 'key' => $site . ':10#1', 'distance' => 0.20, 'metadata' => array( 'site' => $site, 'post_id' => 10, 'text' => 'Refunds within 30 days.' ) ),
			array( 'key' => $site . ':10#0', 'distance' => 0.25, 'metadata' => array( 'site' => $site, 'post_id' => 10, 'text' => 'Refunds, second passage' ) ),
			array( 'key' => $site . ':12#0', 'distance' => 0.30, 'metadata' => array( 'site' => $site, 'post_id' => 12, 'text' => 'Returns policy.' ) ),
			array( 'key' => $site . ':12#1', 'distance' => 0.95, 'metadata' => array( 'site' => $site, 'post_id' => 12, 'text' => 'Weak match' ) ),
		),
	),
);
$hits  = AI_Chat_Bedrock_S3_Vectors::search( array( 0.1, 0.2, 0.3 ), 'refund?', 5, $options, 'en' );
$query = s3v_calls( 'QueryVectors' );
check_s3v( 1 === count( $query ), 'One query when the language has hits.' );
check_s3v( array( '$and' => array( array( 'site' => array( '$eq' => $site ) ), array( 'lang' => array( '$eq' => 'en' ) ) ) ) === $query[0]['payload']['filter'], 'The query filters by site and language.' );
check_s3v( true === $query[0]['payload']['returnMetadata'] && true === $query[0]['payload']['returnDistance'], 'The query asks for metadata and distance.' );
$json = json_encode( $hits );
check_s3v( false === strpos( $json, 'Secret' ), 'A hit on a post that is no longer public is dropped.' );
check_s3v( false === strpos( $json, 'Another site' ), 'A hit belonging to another site is dropped.' );
check_s3v( 2 === count( $hits ), 'One passage per post: ' . count( $hits ) );
check_s3v( 'Refunds' === $hits[0]['title'] && 'Refunds within 30 days.' === $hits[0]['excerpt'], 'The best passage of the best post is quoted.' );
check_s3v( abs( 0.8 - $hits[0]['score'] ) < 0.0001, 'The score is one minus the cosine distance.' );
check_s3v( 'semantic' === $hits[0]['source'] && 'https://example.test/?p=10' === $hits[0]['url'], 'Hits carry source and link.' );
check_s3v( false === strpos( $json, 'Weak match' ), 'A weak runner-up is dropped.' );

// A post edited since indexing is quoted from its live text, not the stored passage.
delete_post_meta( 10, AI_Chat_Bedrock_Embeddings::META_STATE );
$GLOBALS['aicfab_replies']['QueryVectors'] = array(
	array( 'vectors' => array( array( 'key' => $site . ':10#0', 'distance' => 0.1, 'metadata' => array( 'site' => $site, 'post_id' => 10, 'text' => 'Old stored text' ) ) ) ),
);
$hits = AI_Chat_Bedrock_S3_Vectors::search( array( 0.1 ), 'refunds', 3, $options );
check_s3v( 1 === count( $hits ) && false !== strpos( $hits[0]['excerpt'], 'Refunds within 30 days' ), 'A stale passage is replaced by the live text.' );

// No hit in the visitor's language falls back to any language.
$GLOBALS['aicfab_calls']                   = array();
$GLOBALS['aicfab_replies']['QueryVectors'] = array(
	array( 'vectors' => array() ),
	array( 'vectors' => array( array( 'key' => $site . ':12#0', 'distance' => 0.2, 'metadata' => array( 'site' => $site, 'post_id' => 12, 'text' => 'Returns policy.' ) ) ) ),
);
$hits  = AI_Chat_Bedrock_S3_Vectors::search( array( 0.1 ), 'returns', 3, $options, 'ja' );
$query = s3v_calls( 'QueryVectors' );
check_s3v( 2 === count( $query ) && array( 'site' => array( '$eq' => $site ) ) === $query[1]['payload']['filter'], 'The second query drops the language filter.' );
check_s3v( 1 === count( $hits ), 'The fallback finds the other-language page.' );

$GLOBALS['aicfab_replies']['QueryVectors'] = array(
	array( 'vectors' => array( array( 'key' => $site . ':12#0', 'distance' => 0.95, 'metadata' => array( 'site' => $site, 'post_id' => 12, 'text' => 'Returns policy.' ) ) ) ),
);
check_s3v( array() === AI_Chat_Bedrock_S3_Vectors::search( array( 0.1 ), 'x', 3, $options ), 'A best match below the floor returns nothing.' );
$GLOBALS['aicfab_fail'] = array( 'QueryVectors' );
check_s3v( array() === AI_Chat_Bedrock_S3_Vectors::search( array( 0.1 ), 'x', 3, $options ), 'A query error returns nothing, so keyword search can run.' );
$GLOBALS['aicfab_fail'] = array();

// --- Index checks --------------------------------------------------------------

$GLOBALS['aicfab_replies']['GetIndex'] = array(
	array( 'index' => array( 'indexArn' => 'arn:aws:s3vectors:ap-northeast-1:1:bucket/site-vectors/index/posts', 'dimension' => 1024, 'distanceMetric' => 'cosine', 'metadataConfiguration' => array( 'nonFilterableMetadataKeys' => array( 'text', 'title' ) ) ) ),
	array( 'index' => array( 'dimension' => 1536, 'distanceMetric' => 'euclidean', 'metadataConfiguration' => array( 'nonFilterableMetadataKeys' => array( 'text' ) ) ) ),
);
$good = AI_Chat_Bedrock_S3_Vectors::describe_index( $model, $options );
check_s3v( is_array( $good ) && array() === $good['problems'] && 1024 === $good['dimension'], 'A matching index has no problems.' );
$bad = AI_Chat_Bedrock_S3_Vectors::describe_index( $model, $options );
check_s3v( is_array( $bad ) && 3 === count( $bad['problems'] ), 'Dimension, metric and metadata problems are each reported: ' . ( is_array( $bad ) ? count( $bad['problems'] ) : 'error' ) );

$GLOBALS['aicfab_calls'] = array();
AI_Chat_Bedrock_S3_Vectors::create_index( $model, $options );
$create = s3v_calls( 'CreateIndex' );
check_s3v( 1 === count( $create ) && 1024 === $create[0]['payload']['dimension'] && 'cosine' === $create[0]['payload']['distanceMetric'], 'The index is created for the model with cosine distance.' );
check_s3v( array( 'text', 'title' ) === $create[0]['payload']['metadataConfiguration']['nonFilterableMetadataKeys'], 'Passage text and title are non-filterable.' );
check_s3v( is_wp_error( AI_Chat_Bedrock_S3_Vectors::create_index( 'unknown.model', $options ) ), 'An unknown dimension is refused.' );

// --- Clearing only touches this site's vectors ---------------------------------

$GLOBALS['aicfab_calls']                  = array();
$GLOBALS['aicfab_meta']                   = array( 10 => array( AI_Chat_Bedrock_S3_Vectors::META_REF => $reference, AI_Chat_Bedrock_S3_Vectors::META_CHUNKS => 1 ) );
$GLOBALS['aicfab_replies']['ListVectors'] = array(
	array(
		'vectors'   => array( array( 'key' => $site . ':10#0' ), array( 'key' => 'other:10#0' ) ),
		'nextToken' => 'page2',
	),
	array( 'vectors' => array( array( 'key' => $site . ':99#0' ), array( 'key' => 'legacy', 'metadata' => array( 'site' => $site ) ) ) ),
);
$cleared = AI_Chat_Bedrock_S3_Vectors::clear( $options );
$lists   = s3v_calls( 'ListVectors' );
check_s3v( 2 === count( $lists ) && 'page2' === $lists[1]['payload']['nextToken'], 'Listing follows the pagination token.' );
$deletes = s3v_calls( 'DeleteVectors' );
check_s3v( 1 === count( $deletes ) && array( $site . ':10#0', $site . ':99#0', 'legacy' ) === $deletes[0]['payload']['keys'], 'Only this site\'s vectors are deleted, including orphans.' );
check_s3v( 1 === $cleared && '' === get_post_meta( 10, AI_Chat_Bedrock_S3_Vectors::META_REF, true ), 'Index records are removed.' );

// --- Every language ------------------------------------------------------------
// Polylang limits a query to the request's language unless told otherwise, so the settings
// screen counted and cleared only the posts of the admin's language.
$aicfab_scoped = array_filter(
	$GLOBALS['aicfab_queries'],
	function ( $args ) {
		return ! array_key_exists( 'lang', $args ) || '' !== $args['lang'];
	}
);
check_s3v( count( $GLOBALS['aicfab_queries'] ) > 0 && array() === $aicfab_scoped, 'Every query covers all languages: ' . count( $aicfab_scoped ) . ' of ' . count( $GLOBALS['aicfab_queries'] ) . ' do not.' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: S3 Vectors checks passed\n";
