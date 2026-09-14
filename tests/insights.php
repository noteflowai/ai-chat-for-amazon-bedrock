<?php
/**
 * Standalone tests for content gap reporting.
 *
 * Also guards the defect class that has bitten this plugin twice: prune() rebuilds each
 * stored entry from an explicit list of keys, so any field added to record() and not to
 * prune() is silently lost on the next write. Rather than asserting one field survives,
 * the test below asserts that every field record() writes survives.
 *
 * Run: php tests/insights.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['aicfab_options'] = array();
$GLOBALS['aicfab_user']    = 3;

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
function get_current_user_id() {
	return (int) $GLOBALS['aicfab_user'];
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function sanitize_text_field( $value ) {
	return trim( preg_replace( '/<[^>]*>/', '', (string) $value ) );
}
function wp_strip_all_tags( $value ) {
	return preg_replace( '/<[^>]*>/', '', (string) $value );
}
function absint( $value ) {
	return abs( (int) $value );
}
function apply_filters( $hook, $value ) {
	return $value;
}
function __( $text, $domain = null ) {
	return $text;
}
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}
function add_query_arg( $args, $url ) {
	// Mirrors WordPress: build_query() calls _http_build_query() with encoding disabled,
	// so values are inserted as given. Using http_build_query here would encode them and
	// hide the very defect this file asserts against.
	$pairs = array();
	foreach ( $args as $key => $value ) {
		$pairs[] = $key . '=' . $value;
	}
	return $url . '?' . implode( '&', $pairs );
}
function wp_json_encode( $value ) {
	return json_encode( $value );
}

class AI_Chat_Bedrock_Security {
	public static function string_substr( $value, $start, $length ) {
		// Mirrors the real helper, which falls back when mbstring is absent.
		return function_exists( 'mb_substr' )
			? mb_substr( (string) $value, $start, $length, 'UTF-8' )
			: substr( (string) $value, $start, $length );
	}
}

require_once __DIR__ . '/../includes/class-ai-chat-bedrock-conversations.php';
require_once __DIR__ . '/../includes/class-ai-chat-bedrock-insights.php';

$failures = array();
function check_insight( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

$GLOBALS['aicfab_options'][ AI_Chat_Bedrock_Conversations::OPTION_ENABLED ] = 1;

/**
 * Write one exchange directly, so the tests control grounding, rating and time.
 */
function seed( $question, $grounded, $rating = 0, $age_days = 1, $source = 'chat' ) {
	$log     = get_option( AI_Chat_Bedrock_Conversations::OPTION_LOG, array() );
	$log     = is_array( $log ) ? $log : array();
	$log[]   = array(
		'id'            => str_pad( (string) count( $log ), 24, 'a', STR_PAD_LEFT ),
		'time'          => time() - (int) ( $age_days * DAY_IN_SECONDS ),
		'user'          => 0,
		'source'        => $source,
		'model'         => 'test-model',
		'question'      => $question,
		'answer'        => 'An answer.',
		'input_tokens'  => 1,
		'output_tokens' => 1,
		'rating'        => (int) $rating,
		'rating_time'   => 0,
		'grounded'      => $grounded ? 1 : 0,
	);
	update_option( AI_Chat_Bedrock_Conversations::OPTION_LOG, $log );
}

// --- Fingerprinting ----------------------------------------------------------

$a = AI_Chat_Bedrock_Insights::fingerprint( 'Do you ship to Germany?' );
$b = AI_Chat_Bedrock_Insights::fingerprint( 'do you ship to germany' );
check_insight( $a === $b, 'case and punctuation do not split a gap' );

$c = AI_Chat_Bedrock_Insights::fingerprint( 'Germany shipping' );
$d = AI_Chat_Bedrock_Insights::fingerprint( 'shipping Germany' );
check_insight( $c === $d, 'word order does not split a gap' );

check_insight(
	AI_Chat_Bedrock_Insights::fingerprint( 'What is your refund policy?' ) === AI_Chat_Bedrock_Insights::fingerprint( 'your refund policy' ),
	'stop words do not split a gap'
);
check_insight(
	AI_Chat_Bedrock_Insights::fingerprint( 'refund policy' ) !== AI_Chat_Bedrock_Insights::fingerprint( 'delivery times' ),
	'different subjects are different gaps'
);
check_insight( '' === AI_Chat_Bedrock_Insights::fingerprint( '   ' ), 'blank input has no fingerprint' );
check_insight( '' === AI_Chat_Bedrock_Insights::fingerprint( '???' ), 'punctuation alone has no fingerprint' );

// A question made only of stop words still has to group with itself.
$only_stop = AI_Chat_Bedrock_Insights::fingerprint( 'what is it' );
check_insight( '' !== $only_stop, 'a question of only stop words still fingerprints' );

// Non-Latin scripts must survive, since the plugin is translated.
// preg with /u handles this without mbstring, so the assertion holds either way.
$cjk = AI_Chat_Bedrock_Insights::fingerprint( '你们支持退货吗？' );
check_insight( '' !== $cjk, 'a CJK question fingerprints, got ' . var_export( $cjk, true ) );
check_insight(
	AI_Chat_Bedrock_Insights::fingerprint( '你们支持退货吗' ) === $cjk,
	'a full-width question mark does not split a CJK gap'
);

// --- Gap reporting -----------------------------------------------------------

seed( 'Do you ship to Germany?', false );
seed( 'do you ship to Germany', false );
seed( 'Shipping to Germany please', false );
seed( 'What is your refund policy?', true, -1 );
seed( 'When are you open?', true );

$gaps = AI_Chat_Bedrock_Insights::content_gaps();
check_insight( 3 === count( $gaps ), 'only ungrounded or disliked questions are reported, got ' . count( $gaps ) );

// Grouping is deliberately conservative: there is no stemming, so "ship" and "shipping"
// are separate gaps. Merging them would need language-specific rules, and for a report
// whose action is "write a page about this", listing one subject twice is far less harmful
// than merging two unrelated subjects into one. This asserts the choice so it is not
// changed by accident.
check_insight(
	AI_Chat_Bedrock_Insights::fingerprint( 'Do you ship to Germany?' ) !== AI_Chat_Bedrock_Insights::fingerprint( 'Shipping to Germany please' ),
	'an inflected word is not merged, which keeps grouping conservative'
);

$first = $gaps[0];
check_insight( 2 === $first['asked'], 'the two identical shipping questions group, got ' . $first['asked'] );
check_insight( 2 === $first['ungrounded'], 'both are counted as ungrounded' );
check_insight( 0 === $first['disliked'], 'neither was marked unhelpful' );

$questions = array_column( $gaps, 'question' );
check_insight( ! in_array( 'When are you open?', $questions, true ), 'a grounded, unrated question is not a gap' );
check_insight( in_array( 'What is your refund policy?', $questions, true ), 'a grounded but disliked answer is a gap' );

$refund = null;
foreach ( $gaps as $gap ) {
	if ( 'What is your refund policy?' === $gap['question'] ) {
		$refund = $gap;
	}
}
check_insight( null !== $refund && 1 === $refund['disliked'], 'the complaint is counted' );
check_insight( null !== $refund && 0 === $refund['ungrounded'], 'a grounded answer is not counted as ungrounded' );

// Most asked first.
check_insight( $gaps[0]['asked'] >= $gaps[count( $gaps ) - 1]['asked'], 'the most asked gap is first' );

// --- Windows and filters ----------------------------------------------------

seed( 'Ancient question nobody asks now', false, 0, 200 );
$gaps = AI_Chat_Bedrock_Insights::content_gaps( array( 'days' => 30 ) );
check_insight(
	! in_array( 'Ancient question nobody asks now', array_column( $gaps, 'question' ), true ),
	'an old question falls outside the window'
);
$gaps = AI_Chat_Bedrock_Insights::content_gaps( array( 'days' => 365 ) );
check_insight(
	in_array( 'Ancient question nobody asks now', array_column( $gaps, 'question' ), true ),
	'a wider window includes it'
);

// Generated drafts are not questions a visitor asked.
seed( 'Write me a post about bicycle maintenance schedules', false, 0, 1, 'editor' );
$gaps = AI_Chat_Bedrock_Insights::content_gaps();
check_insight(
	! in_array( 'Write me a post about bicycle maintenance schedules', array_column( $gaps, 'question' ), true ),
	'an editor request is not reported as a visitor gap'
);

// Greetings are noise.
seed( 'hi', false );
$gaps = AI_Chat_Bedrock_Insights::content_gaps();
check_insight( ! in_array( 'hi', array_column( $gaps, 'question' ), true ), 'a greeting is too short to be a gap' );

$gaps = AI_Chat_Bedrock_Insights::content_gaps( array( 'limit' => 1 ) );
check_insight( 1 === count( $gaps ), 'the limit is honoured' );

for ( $i = 0; $i < 40; $i++ ) {
	seed( 'Unique subject number ' . $i . ' that nobody covered', false );
}
$gaps = AI_Chat_Bedrock_Insights::content_gaps();
check_insight(
	AI_Chat_Bedrock_Insights::MAX_GAPS >= count( $gaps ),
	'the report is capped at ' . AI_Chat_Bedrock_Insights::MAX_GAPS . ', got ' . count( $gaps )
);

// --- Summary ----------------------------------------------------------------

$summary = AI_Chat_Bedrock_Insights::summary( 30 );
check_insight( $summary['asked'] > 0, 'the summary counts questions' );
check_insight( $summary['ungrounded'] <= $summary['asked'], 'ungrounded never exceeds asked' );
check_insight( $summary['percent'] >= 0 && $summary['percent'] <= 100, 'the percentage is a percentage' );

$GLOBALS['aicfab_options'][ AI_Chat_Bedrock_Conversations::OPTION_LOG ] = array();
$empty = AI_Chat_Bedrock_Insights::summary( 30 );
check_insight( 0 === $empty['asked'] && 0 === $empty['percent'], 'an empty log does not divide by zero' );
check_insight( array() === AI_Chat_Bedrock_Insights::content_gaps(), 'an empty log reports no gaps' );

// --- The draft handoff ------------------------------------------------------

$link = AI_Chat_Bedrock_Insights::draft_link( 'Do you ship to Germany?' );
check_insight( false !== strpos( $link, 'ai-chat-for-amazon-bedrock-generator' ), 'the link opens the content generator' );
check_insight( false !== strpos( $link, 'aicfab_topic' ), 'the link carries the subject' );
check_insight( '' === AI_Chat_Bedrock_Insights::draft_link( '  ' ), 'a blank question has no link' );

// add_query_arg does not encode values. An unencoded ampersand would start a new
// parameter and silently truncate the subject at that point, which is what happened
// before this assertion existed.
$tricky = AI_Chat_Bedrock_Insights::draft_link( 'Do you ship to Aland & Curacao?' );
parse_str( (string) parse_url( $tricky, PHP_URL_QUERY ), $parsed );
check_insight(
	isset( $parsed['aicfab_topic'] ) && 'Do you ship to Aland & Curacao?' === $parsed['aicfab_topic'],
	'an ampersand and a question mark survive the link, got ' . var_export( $parsed['aicfab_topic'] ?? null, true )
);
check_insight( false === strpos( $tricky, '=Do you ship' ), 'the value is encoded rather than inlined raw' );
// Markup must not survive into a URL.
check_insight(
	false === strpos( AI_Chat_Bedrock_Insights::draft_link( '<script>x</script>Refunds' ), '<script>' ),
	'markup is stripped from the link'
);

// --- The prune defect class -------------------------------------------------

$GLOBALS['aicfab_options'][ AI_Chat_Bedrock_Conversations::OPTION_LOG ] = array();
$id = AI_Chat_Bedrock_Conversations::record(
	'Do you deliver on Sundays?',
	'Not at the moment.',
	array(
		'usage'    => array(
			'input_tokens'  => 11,
			'output_tokens' => 22,
		),
		'source'   => 'stream',
		'model'    => 'test-model',
		'grounded' => true,
	)
);
check_insight( is_string( $id ) && 24 === strlen( $id ), 'record returns an identifier' );

// Writing a second entry runs prune() over the first one.
AI_Chat_Bedrock_Conversations::record( 'Another question entirely?', 'Another answer.', array( 'source' => 'chat' ) );
$log      = get_option( AI_Chat_Bedrock_Conversations::OPTION_LOG );
$survived = $log[0];

/**
 * Compare the field names record() writes with the ones prune() rebuilds.
 *
 * Comparing two stored entries cannot detect this: record() prunes as it writes, so a
 * field dropped by prune() is already missing from the entry that was just written. The
 * only place the difference is visible is the source, where the two lists must agree.
 *
 * @param string $method Method name to read keys from.
 * @return array Field names.
 */
function entry_keys_in( $method ) {
	$source = file_get_contents( __DIR__ . '/../includes/class-ai-chat-bedrock-conversations.php' );
	$start  = strpos( $source, 'function ' . $method . '(' );
	if ( false === $start ) {
		return array();
	}
	// Read to the end of the first array literal that assigns entry fields.
	$slice = substr( $source, $start, 3000 );
	preg_match_all( "/'([a-z_]+)'\s*=>/", $slice, $matches );
	$keys = array_values( array_unique( $matches[1] ) );
	// Fields of the entry only; nested usage keys and option names are not entry fields.
	return array_values(
		array_diff(
			$keys,
			array( 'usage', 'source_default', 'input_tokens_default' )
		)
	);
}

$record_keys = entry_keys_in( 'record' );
$prune_keys  = entry_keys_in( 'prune' );
check_insight( count( $record_keys ) > 5, 'the record fields were read from the source, got ' . count( $record_keys ) );
$lost = array_diff( $record_keys, $prune_keys );
check_insight(
	array() === $lost,
	'prune() rebuilds every field record() writes, missing: ' . implode( ', ', $lost )
);
check_insight( 1 === (int) $survived['grounded'], 'grounding survives a later write' );
check_insight( $id === $survived['id'], 'the identifier survives' );
check_insight( 11 === (int) $survived['input_tokens'], 'token counts survive' );
check_insight( 'stream' === $survived['source'], 'the source survives' );

// Rating an entry then writing again must keep the rating and the grounding together.
AI_Chat_Bedrock_Conversations::rate( $id, -1 );
AI_Chat_Bedrock_Conversations::record( 'A third question here?', 'A third answer.', array( 'source' => 'chat' ) );
$log = get_option( AI_Chat_Bedrock_Conversations::OPTION_LOG );
$rated = null;
foreach ( $log as $entry ) {
	if ( $entry['id'] === $id ) {
		$rated = $entry;
	}
}
check_insight( null !== $rated, 'the rated entry is still there' );
check_insight( null !== $rated && -1 === (int) $rated['rating'], 'the rating survives' );
check_insight( null !== $rated && 1 === (int) $rated['grounded'], 'grounding and rating survive together' );

// The CSV export must expose the signal too.
$rows = AI_Chat_Bedrock_Conversations::export_rows();
check_insight( in_array( 'grounded', $rows[0], true ), 'the CSV header names the grounding column' );
$column = array_search( 'grounded', $rows[0], true );
check_insight( false !== $column && in_array( (int) $rows[1][ $column ], array( 0, 1 ), true ), 'the CSV grounding value is 0 or 1' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: content gap checks passed\n";
