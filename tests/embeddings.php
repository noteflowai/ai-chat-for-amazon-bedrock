<?php
/**
 * Standalone tests for semantic search.
 *
 * Run: php tests/embeddings.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['aicfab_options'] = array( 'ai_chat_bedrock_settings' => array( 'embedding_model_id' => 'amazon.titan-embed-text-v2:0' ) );
$GLOBALS['aicfab_meta']    = array();
$GLOBALS['aicfab_posts']   = array();
$GLOBALS['aicfab_vectors'] = array();
$GLOBALS['aicfab_embed_calls'] = 0;

// --- WordPress stubs -------------------------------------------------------

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_options'] ) ? $GLOBALS['aicfab_options'][ $name ] : $default;
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
function strip_shortcodes( $value ) {
	return (string) $value;
}
function post_type_exists( $type ) {
	return in_array( $type, array( 'post', 'page' ), true );
}
function get_post( $id ) {
	return isset( $GLOBALS['aicfab_posts'][ $id ] ) ? $GLOBALS['aicfab_posts'][ $id ] : null;
}
function get_the_title( $post ) {
	return is_object( $post ) ? $post->post_title : '';
}
function get_permalink( $post ) {
	return 'https://example.test/?p=' . ( is_object( $post ) ? $post->ID : 0 );
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
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function wp_reset_postdata() {}
$GLOBALS['aicfab_cron'] = array();
function wp_next_scheduled( $hook ) {
	return isset( $GLOBALS['aicfab_cron'][ $hook ] ) ? $GLOBALS['aicfab_cron'][ $hook ] : false;
}
function wp_schedule_event( $timestamp, $recurrence, $hook ) {
	$GLOBALS['aicfab_cron'][ $hook ] = (int) $timestamp;
	return true;
}
function wp_unschedule_event( $timestamp, $hook ) {
	unset( $GLOBALS['aicfab_cron'][ $hook ] );
	return true;
}

class WP_Post {
	public $ID = 0;
	public $post_title = '';
	public $post_content = '';
	public $post_status = 'publish';
	public $post_password = '';
	public $post_type = 'post';
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
	public $posts = array();
	public $found_posts = 0;
	public function __construct( $args = array() ) {
		$ids = array_keys( $GLOBALS['aicfab_posts'] );
		$matched = array();
		foreach ( $ids as $id ) {
			$post = $GLOBALS['aicfab_posts'][ $id ];
			if ( 'publish' !== $post->post_status || '' !== $post->post_password ) {
				continue;
			}
			if ( isset( $args['meta_query'] ) ) {
				$has_vector = '' !== get_post_meta( $id, AI_Chat_Bedrock_Embeddings::META_VECTOR, true );
				if ( ! $has_vector ) {
					continue;
				}
			}
			$matched[] = $id;
		}
		$this->found_posts = count( $matched );
		$limit = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : -1;
		if ( $limit > 0 ) {
			$matched = array_slice( $matched, 0, $limit );
		}
		$this->posts = ( isset( $args['fields'] ) && 'ids' === $args['fields'] )
			? $matched
			: array_map( static function ( $id ) { return $GLOBALS['aicfab_posts'][ $id ]; }, $matched );
	}
}

class AI_Chat_Bedrock_Security {
	public static function string_substr( $value, $start, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $value, $start, $length ) : substr( (string) $value, $start, $length );
	}
}

class AI_Chat_Bedrock_Retrieval {
	const MAX_PASSAGES      = 8;
	const MAX_PASSAGE_CHARS = 1200;
}

class AI_Chat_Bedrock_AWS {
	public function embed( $text, $model ) {
		$GLOBALS['aicfab_embed_calls']++;
		$key = trim( (string) $text );
		foreach ( $GLOBALS['aicfab_vectors'] as $needle => $vector ) {
			if ( false !== stripos( $key, (string) $needle ) ) {
				return $vector;
			}
		}
		return new WP_Error( 'aicfab_no_embedding', 'no vector for this text' );
	}
}

require_once __DIR__ . '/../includes/class-ai-chat-bedrock-embeddings.php';

$failures = array();
function check_emb( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

function make_post( $id, $title, $content, $status = 'publish', $password = '', $type = 'post' ) {
	$post                = new WP_Post();
	$post->ID            = $id;
	$post->post_title    = $title;
	$post->post_content  = $content;
	$post->post_status   = $status;
	$post->post_password = $password;
	$post->post_type     = $type;
	$GLOBALS['aicfab_posts'][ $id ] = $post;
	return $post;
}

// --- Packing round trip ----------------------------------------------------

$vector = array( 0.5, -0.25, 0.125, 1.0 );
$packed = AI_Chat_Bedrock_Embeddings::pack( $vector );
check_emb( '' !== $packed && $packed === base64_encode( base64_decode( $packed, true ) ), 'A packed vector is valid base64.' );
$restored = AI_Chat_Bedrock_Embeddings::unpack( $packed );
check_emb( 4 === count( $restored ), 'Unpacking restores every component.' );
check_emb( abs( 0.5 - $restored[0] ) < 0.0001 && abs( -0.25 - $restored[1] ) < 0.0001, 'Unpacking restores the values.' );
check_emb( array() === AI_Chat_Bedrock_Embeddings::unpack( '' ), 'An empty payload unpacks to nothing.' );
check_emb( array() === AI_Chat_Bedrock_Embeddings::unpack( 'not base64 %%%' ), 'A corrupt payload unpacks to nothing.' );
check_emb( '' === AI_Chat_Bedrock_Embeddings::pack( array() ), 'An empty vector packs to an empty string.' );
check_emb( strlen( $packed ) < 40, 'Packing is compact: ' . strlen( $packed ) . ' chars for 4 floats.' );

// --- Cosine similarity -----------------------------------------------------

check_emb( abs( 1.0 - AI_Chat_Bedrock_Embeddings::cosine( array( 1, 2, 3 ), array( 1, 2, 3 ) ) ) < 0.0001, 'Identical vectors score 1.' );
check_emb( abs( AI_Chat_Bedrock_Embeddings::cosine( array( 1, 0 ), array( 0, 1 ) ) ) < 0.0001, 'Orthogonal vectors score 0.' );
check_emb( AI_Chat_Bedrock_Embeddings::cosine( array( 1, 0 ), array( -1, 0 ) ) < -0.99, 'Opposite vectors score -1.' );
check_emb( 0.0 === AI_Chat_Bedrock_Embeddings::cosine( array( 0, 0 ), array( 1, 1 ) ), 'A zero vector scores 0 rather than dividing by zero.' );
check_emb( abs( 1.0 - AI_Chat_Bedrock_Embeddings::cosine( array( 2, 4 ), array( 1, 2 ) ) ) < 0.0001, 'Similarity ignores magnitude.' );

// --- Enabling and model validation ----------------------------------------

check_emb( true === AI_Chat_Bedrock_Embeddings::enabled(), 'A configured model enables semantic search.' );
check_emb( 'amazon.titan-embed-text-v2:0' === AI_Chat_Bedrock_Embeddings::model(), 'The configured model is returned.' );
$GLOBALS['aicfab_options']['ai_chat_bedrock_settings']['embedding_model_id'] = 'bad model!!';
check_emb( '' === AI_Chat_Bedrock_Embeddings::model(), 'A malformed model identifier disables semantic search.' );
$GLOBALS['aicfab_options']['ai_chat_bedrock_settings']['embedding_model_id'] = '';
check_emb( false === AI_Chat_Bedrock_Embeddings::enabled(), 'No model means semantic search is off.' );
check_emb( array() === AI_Chat_Bedrock_Embeddings::search( 'anything', 3 ), 'Search returns nothing while disabled.' );
$GLOBALS['aicfab_options']['ai_chat_bedrock_settings']['embedding_model_id'] = 'amazon.titan-embed-text-v2:0';

// --- Indexing boundaries ---------------------------------------------------

$GLOBALS['aicfab_vectors'] = array(
	'Refund'  => array( 1.0, 0.0, 0.0 ),
	'Shipping' => array( 0.0, 1.0, 0.0 ),
	'refund window' => array( 0.98, 0.05, 0.0 ),
);

make_post( 1, 'Refund policy', 'Refund requests are accepted for 21 days.' );
make_post( 2, 'Shipping information', 'Shipping leaves the warehouse daily.' );
make_post( 3, 'Draft notes', 'Refund secrets that are not published.', 'draft' );
make_post( 4, 'Protected', 'Refund secrets behind a password.', 'publish', 'hunter2' );

check_emb( 'indexed' === AI_Chat_Bedrock_Embeddings::index_post( 1 ), 'A published post is indexed.' );
check_emb( '' !== get_post_meta( 1, AI_Chat_Bedrock_Embeddings::META_VECTOR, true ), 'The vector is stored in post meta.' );
check_emb( 'amazon.titan-embed-text-v2:0' === get_post_meta( 1, AI_Chat_Bedrock_Embeddings::META_MODEL, true ), 'The model used is stored alongside.' );

$before = $GLOBALS['aicfab_embed_calls'];
check_emb( 'skipped' === AI_Chat_Bedrock_Embeddings::index_post( 1 ), 'An unchanged post is skipped.' );
check_emb( $before === $GLOBALS['aicfab_embed_calls'], 'A skipped post costs no embedding request.' );

check_emb( 'unsupported' === AI_Chat_Bedrock_Embeddings::index_post( 3 ), 'Drafts are never indexed.' );
check_emb( 'unsupported' === AI_Chat_Bedrock_Embeddings::index_post( 4 ), 'Password protected posts are never indexed.' );
check_emb( 'unsupported' === AI_Chat_Bedrock_Embeddings::index_post( 999 ), 'A missing post is refused.' );

// Editing a post marks it for re-indexing.
AI_Chat_Bedrock_Embeddings::invalidate( 1 );
check_emb( '' === get_post_meta( 1, AI_Chat_Bedrock_Embeddings::META_HASH, true ), 'Invalidation clears the content hash.' );
check_emb( 'indexed' === AI_Chat_Bedrock_Embeddings::index_post( 1 ), 'An invalidated post is embedded again.' );

// A model change forces a re-index rather than mixing vector spaces.
update_post_meta( 1, AI_Chat_Bedrock_Embeddings::META_MODEL, 'cohere.embed-english-v3' );
check_emb( 'indexed' === AI_Chat_Bedrock_Embeddings::index_post( 1 ), 'Switching models re-embeds the post.' );

// --- Searching -------------------------------------------------------------

AI_Chat_Bedrock_Embeddings::index_post( 2 );

$hits = AI_Chat_Bedrock_Embeddings::search( 'refund window', 3 );
check_emb( ! empty( $hits ), 'A semantic search returns hits.' );
check_emb( 'Refund policy' === $hits[0]['title'], 'The closest passage ranks first: ' . ( isset( $hits[0]['title'] ) ? $hits[0]['title'] : 'none' ) );
check_emb( 'semantic' === $hits[0]['source'], 'Hits are labelled as semantic.' );
check_emb( isset( $hits[0]['score'] ) && $hits[0]['score'] > 0.9, 'The score reflects similarity.' );
check_emb( false === strpos( wp_json_encode_compat( $hits ), 'not published' ), 'Draft content never appears in results.' );
check_emb( false === strpos( wp_json_encode_compat( $hits ), 'behind a password' ), 'Protected content never appears in results.' );

$none = AI_Chat_Bedrock_Embeddings::search( 'completely unrelated topic', 3 );
check_emb( array() === $none, 'A question with no embedding available returns nothing rather than guessing.' );

// --- Score filtering ------------------------------------------------------

$GLOBALS['aicfab_posts'] = array();
$GLOBALS['aicfab_meta']  = array();
$GLOBALS['aicfab_vectors'] = array(
	'Alpha'   => array( 1.0, 0.0, 0.0 ),
	'Beta'    => array( 0.7, 0.7, 0.0 ),
	'Gamma'   => array( 0.0, 0.0, 1.0 ),
	'strong query' => array( 1.0, 0.0, 0.0 ),
	'weak query'   => array( 0.10, 0.0, 0.995 ),
);
make_post( 10, 'Alpha', 'Alpha body text' );
make_post( 11, 'Beta', 'Beta body text' );
AI_Chat_Bedrock_Embeddings::index_post( 10 );
AI_Chat_Bedrock_Embeddings::index_post( 11 );

$hits = AI_Chat_Bedrock_Embeddings::search( 'strong query', 5 );
check_emb( 2 === count( $hits ), 'Hits close to the best score are kept.' );
check_emb( 'Alpha' === $hits[0]['title'], 'The best hit ranks first.' );

// A question whose closest match is far below the floor returns nothing at all.
make_post( 12, 'Gamma', 'Gamma body text' );
AI_Chat_Bedrock_Embeddings::index_post( 12 );
$weak = AI_Chat_Bedrock_Embeddings::search( 'weak query', 5 );
check_emb( ! empty( $weak ) && 'Gamma' === $weak[0]['title'], 'A strong match is still returned when others are weak.' );
check_emb( 1 === count( $weak ), 'Weak runners-up are dropped rather than padding the context.' );

// --- Clearing --------------------------------------------------------------

$cleared = AI_Chat_Bedrock_Embeddings::clear();
check_emb( $cleared >= 2, 'Clearing removes every stored vector.' );
check_emb( '' === get_post_meta( 1, AI_Chat_Bedrock_Embeddings::META_VECTOR, true ), 'Vectors are gone after clearing.' );
check_emb( '' === get_post_meta( 1, AI_Chat_Bedrock_Embeddings::META_MODEL, true ), 'Model markers are gone after clearing.' );
check_emb( array() === AI_Chat_Bedrock_Embeddings::search( 'refund window', 3 ), 'Search returns nothing once the index is empty.' );

// --- Background indexing --------------------------------------------------

$GLOBALS['aicfab_cron'] = array();
$GLOBALS['aicfab_options']['ai_chat_bedrock_settings'] = array( 'embedding_model_id' => 'amazon.titan-embed-text-v2:0' );

check_emb( false === AI_Chat_Bedrock_Embeddings::background_enabled(), 'Background indexing is off by default.' );
AI_Chat_Bedrock_Embeddings::schedule();
check_emb( false === wp_next_scheduled( AI_Chat_Bedrock_Embeddings::CRON_HOOK ), 'Nothing is scheduled while background indexing is off.' );

$counts = AI_Chat_Bedrock_Embeddings::run_scheduled_index();
check_emb( 0 === $counts['indexed'] && 0 === $counts['failed'], 'A scheduled run does nothing while the option is off.' );

$GLOBALS['aicfab_options']['ai_chat_bedrock_settings']['embedding_background'] = true;
check_emb( true === AI_Chat_Bedrock_Embeddings::background_enabled(), 'The option enables background indexing.' );
AI_Chat_Bedrock_Embeddings::schedule();
check_emb( false !== wp_next_scheduled( AI_Chat_Bedrock_Embeddings::CRON_HOOK ), 'Enabling it schedules a run.' );

$first = wp_next_scheduled( AI_Chat_Bedrock_Embeddings::CRON_HOOK );
AI_Chat_Bedrock_Embeddings::schedule();
check_emb( $first === wp_next_scheduled( AI_Chat_Bedrock_Embeddings::CRON_HOOK ), 'Scheduling twice does not duplicate the event.' );

// Switching semantic search off must also stop the background run.
$GLOBALS['aicfab_options']['ai_chat_bedrock_settings']['embedding_model_id'] = '';
AI_Chat_Bedrock_Embeddings::schedule();
check_emb( false === wp_next_scheduled( AI_Chat_Bedrock_Embeddings::CRON_HOOK ), 'Disabling semantic search unschedules the run.' );
$GLOBALS['aicfab_options']['ai_chat_bedrock_settings']['embedding_model_id'] = 'amazon.titan-embed-text-v2:0';

AI_Chat_Bedrock_Embeddings::schedule();
AI_Chat_Bedrock_Embeddings::unschedule();
check_emb( false === wp_next_scheduled( AI_Chat_Bedrock_Embeddings::CRON_HOOK ), 'Unscheduling clears the event, for deactivation.' );

function wp_json_encode_compat( $value ) {
	return (string) json_encode( $value );
}
function wp_json_encode( $value ) {
	return json_encode( $value );
}

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: semantic search checks passed\n";
