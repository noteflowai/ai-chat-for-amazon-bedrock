<?php
/**
 * Standalone tests for the golden set and its programmatic evaluation.
 *
 * The properties worth locking are structural rather than numeric. A verdict must come from
 * the check records and nothing else, a check that does not apply must not count as a pass, and
 * a category must not be able to appear that the report does not know about. Those are the three
 * ways an evaluation comes to look better than it is.
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['aicfab_options'] = array();

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message = '' ) {
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

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_options'] ) ? $GLOBALS['aicfab_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['aicfab_options'][ $name ] = $value;
	return true;
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function sanitize_text_field( $text ) {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', wp_strip_all_tags( (string) $text ) ) );
}
function sanitize_textarea_field( $text ) {
	return trim( wp_strip_all_tags( (string) $text ) );
}
function wp_strip_all_tags( $text ) {
	return strip_tags( (string) $text );
}
function absint( $value ) {
	return abs( (int) $value );
}
function __( $text, $domain = '' ) {
	return $text;
}
$GLOBALS['aicfab_logging'] = true;
$GLOBALS['aicfab_gaps']    = array();
$GLOBALS['aicfab_rated']   = array();
$GLOBALS['aicfab_caps']    = true;

function current_user_can( $capability ) {
	return (bool) $GLOBALS['aicfab_caps'];
}

/** Stands in for the conversation log. */
class AI_Chat_Bedrock_Conversations {
	public static function enabled() {
		return (bool) $GLOBALS['aicfab_logging'];
	}
	public static function query( $args = array() ) {
		return array( 'entries' => $GLOBALS['aicfab_rated'], 'total' => count( $GLOBALS['aicfab_rated'] ) );
	}
}

/**
 * Stands in for the insight layer.
 *
 * Returns a flat list of groups, which is what the real one returns. Reading a wrapper key here
 * is the mistake this stub exists to keep out: it produced no proposals at all and nothing
 * failed, because an empty result looks the same as nothing to propose.
 */
class AI_Chat_Bedrock_Insights {
	public static function content_gaps( $args = array() ) {
		return $GLOBALS['aicfab_gaps'];
	}
}

class AI_Chat_Bedrock_Security {
	public static function string_substr( $text, $start, $length ) {
		return substr( (string) $text, $start, $length );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-eval.php';

$failures = array();
function check_eval( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

/**
 * Build a check record using the class's own constructor.
 *
 * Deliberately not a local double. A hand-written record would mean the verdict tests below
 * exercise this file rather than the code, and the property they are protecting lives in how
 * `check()` records an inapplicable outcome.
 *
 * @param string    $category Category name.
 * @param bool      $applies  Whether it applies.
 * @param bool|null $passed   Outcome.
 * @return array
 */
function eval_check( $category, $applies, $passed ) {
	static $method = null;
	if ( null === $method ) {
		$method = new ReflectionMethod( 'AI_Chat_Bedrock_Eval', 'check' );
		$method->setAccessible( true );
	}
	return $method->invoke( null, $category, $applies, $passed, '' );
}

// An inapplicable check records neither a pass nor a failure. Recording it as a pass is how a
// report comes to claim coverage it does not have, so the value itself is asserted.
check_eval( null === eval_check( 'grounding', false, true )['passed'], 'An inapplicable check must record a null outcome, not a pass.' );
check_eval( null === eval_check( 'grounding', false, false )['passed'], 'An inapplicable check must record null regardless of the outcome passed in.' );
check_eval( true === eval_check( 'grounding', true, true )['passed'], 'An applicable passing check must record true.' );
check_eval( false === eval_check( 'grounding', true, false )['passed'], 'An applicable failing check must record false.' );

// --- The verdict comes from the checks, and from nothing else -------------------

/*
 * This is the property the deployed judge in arXiv:2606.10315 lacked. Its own notes described a
 * defect in 113 of 114 rounds and the score still read something else, because the gate was
 * wired to a different signal than the rubric. Here the verdict has no other input, so a check
 * that reports a failure always changes it. Every category is tried, because a gate that
 * happens to read most of them is the same bug with a smaller blast radius.
 */
foreach ( AI_Chat_Bedrock_Eval::CATEGORIES as $category ) {
	$all_pass = array(
		eval_check( 'delivery', true, true ),
		eval_check( $category, true, true ),
	);
	check_eval( true === AI_Chat_Bedrock_Eval::verdict( $all_pass ), sprintf( 'A case whose %s check passes must pass.', $category ) );

	$one_fails = array(
		eval_check( 'delivery', true, true ),
		eval_check( $category, true, false ),
	);
	check_eval(
		false === AI_Chat_Bedrock_Eval::verdict( $one_fails ),
		sprintf( 'A failing %s check must flip the verdict; a detected defect cannot fail to reach the gate.', $category )
	);
}

// A check that does not apply is not a pass, and cannot carry a case on its own.
check_eval(
	false === AI_Chat_Bedrock_Eval::verdict( array( eval_check( 'grounding', false, true ) ) ),
	'A case with nothing applicable must not be reported as passing.'
);
check_eval(
	false === AI_Chat_Bedrock_Eval::verdict( array() ),
	'A case with no checks at all must not be reported as passing.'
);
// Inapplicable checks must not mask an applicable failure either.
check_eval(
	false === AI_Chat_Bedrock_Eval::verdict(
		array(
			eval_check( 'grounding', false, true ),
			eval_check( 'citation', true, false ),
			eval_check( 'budget', false, true ),
		)
	),
	'An applicable failure must outweigh any number of inapplicable checks.'
);

// --- Cases that cannot be run are dropped where it is visible ------------------

$stored = AI_Chat_Bedrock_Eval::save_cases(
	array(
		array( 'question' => 'Kept.', 'expect' => 'grounded' ),
		array( 'question' => '', 'expect' => 'grounded' ),
		array( 'question' => 'Unknown expectation.', 'expect' => 'vibes' ),
		array( 'question' => 'No expectation at all.' ),
		'not an array',
		array( 'id' => 'dup', 'question' => 'First with this id.', 'expect' => 'unsupported' ),
		array( 'id' => 'dup', 'question' => 'Second with the same id.', 'expect' => 'unsupported' ),
	)
);
check_eval( 3 === count( $stored ), 'Only runnable cases may be stored; got ' . count( $stored ) . '.' );
$ids = array_column( $stored, 'id' );
check_eval( count( $ids ) === count( array_unique( $ids ) ), 'Case ids must be unique, or results cannot be attributed.' );

// A run with no cases is an error rather than an empty pass.
$GLOBALS['aicfab_options'][ AI_Chat_Bedrock_Eval::SET_OPTION ] = array();
$empty = AI_Chat_Bedrock_Eval::run( array() );
check_eval( is_wp_error( $empty ), 'Running an empty set must be an error, not a vacuous pass.' );

// --- The report says what it did not look at -----------------------------------

$report = AI_Chat_Bedrock_Eval::summarize(
	array(
		array(
			'id'     => 'a',
			'passed' => true,
			'checks' => array(
				eval_check( 'delivery', true, true ),
				eval_check( 'grounding', true, true ),
			),
		),
		array(
			'id'     => 'b',
			'passed' => false,
			'checks' => array(
				eval_check( 'delivery', true, true ),
				eval_check( 'grounding', true, false ),
			),
		),
	)
);
check_eval( 2 === $report['cases'] && 1 === $report['passed'], 'The report must count cases and passes.' );
check_eval( 2 === $report['categories']['grounding']['checked'], 'Category check counts must be per check, not per case.' );
check_eval( array( 'b' ) === $report['categories']['grounding']['failed'], 'A failing category must name the case.' );
// The categories nobody looked at are named, so a pass is not read as coverage.
check_eval( in_array( 'citation', $report['unchecked'], true ), 'A category with no checks must be named as unchecked.' );
check_eval( ! in_array( 'grounding', $report['unchecked'], true ), 'A checked category must not be listed as unchecked.' );

// --- A comparison refuses to read a moved denominator as progress ---------------

$before = array(
	'model'      => 'a',
	'categories' => array(
		'grounding' => array( 'checked' => 4, 'passed' => 2 ),
		'citation'  => array( 'checked' => 2, 'passed' => 2 ),
	),
);
$after = array(
	'model'      => 'b',
	'categories' => array(
		'grounding' => array( 'checked' => 4, 'passed' => 4 ),
		'citation'  => array( 'checked' => 1, 'passed' => 1 ),
	),
);
$diff = AI_Chat_Bedrock_Eval::compare( $before, $after );
check_eval( 2 === $diff['categories']['grounding']['change'], 'A real improvement must be reported as one.' );
check_eval( true === $diff['categories']['grounding']['comparable'], 'An unchanged check count is comparable.' );
/*
 * Dropping a case raises the pass rate without improving anything. Marking the category
 * uncomparable is the same guard as counting distinct cases rather than runs: it stops a
 * smaller denominator reading as progress.
 */
check_eval( false === $diff['categories']['citation']['comparable'], 'A changed check count must be marked uncomparable.' );

// --- No judge model ------------------------------------------------------------

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-eval.php' );
check_eval(
	false === strpos( $source, 'judge' ) || false !== strpos( $source, 'no judge model' ),
	'If a judge is mentioned it must be to say none is used.'
);
// Exactly one Bedrock call per case, for the answer itself. A second would be a judge.
check_eval(
	1 === substr_count( $source, 'handle_chat_message' ),
	'The runner must send one request per case; a second call would be an unscored judge.'
);
check_eval(
	false === strpos( $source, 'helpful' ) || false !== strpos( $source, 'nothing here scores style, tone or helpfulness' ),
	'The method statement must disclaim scoring style, tone or helpfulness.'
);

// Categories are a closed set: an unknown one is filed under delivery rather than invented.
$reflect = new ReflectionMethod( 'AI_Chat_Bedrock_Eval', 'check' );
$reflect->setAccessible( true );
$odd = $reflect->invoke( null, 'vibes', true, true, '' );
check_eval( 'delivery' === $odd['category'], 'An unknown category must not enter the report under its own name.' );

// --- Proposals come from what the site was actually asked -----------------------

$GLOBALS['aicfab_options'][ AI_Chat_Bedrock_Eval::SET_OPTION ] = array();
$GLOBALS['aicfab_gaps'] = array(
	array( 'question' => 'What time does the shop close?', 'asked' => 2, 'ungrounded' => 2, 'disliked' => 0 ),
	array( 'question' => 'Do you repair cargo bikes?', 'asked' => 1, 'ungrounded' => 1, 'disliked' => 0 ),
);
$GLOBALS['aicfab_rated'] = array(
	array( 'question' => 'Can I return a used bike?', 'grounded' => 1 ),
	array( 'question' => 'Where are you based?', 'grounded' => 0 ),
);
$proposed = AI_Chat_Bedrock_Eval::propose();
check_eval( 4 === count( $proposed['cases'] ), 'Both sources must contribute proposals; got ' . count( $proposed['cases'] ) . '.' );

/*
 * The shape guard. The insight layer returns a flat list, and reading a wrapper key from it
 * returned nothing while looking like success, because no proposals and nothing to propose are
 * the same output. This asserts a gap question really arrives.
 */
$questions = array_column( $proposed['cases'], 'question' );
check_eval( in_array( 'What time does the shop close?', $questions, true ), 'A content gap must reach the proposals.' );
check_eval( in_array( 'Can I return a used bike?', $questions, true ), 'An unhelpful rating must reach the proposals.' );

// The expectation follows from the record: a grounded answer that was disliked still expects
// grounding, an ungrounded one does not.
$by_question = array_combine( $questions, $proposed['cases'] );
check_eval( 'unsupported' === $by_question['What time does the shop close?']['expect'], 'A gap must be proposed as unsupported.' );
check_eval( 'grounded' === $by_question['Can I return a used bike?']['expect'], 'A disliked grounded answer must still expect grounding.' );
check_eval( 'unsupported' === $by_question['Where are you based?']['expect'], 'A disliked ungrounded answer must expect unsupported.' );

/*
 * A proposal must not invent the ground truth. Required and forbidden text is what "good" means
 * for a question, and no record contains it, so the author states it. Filling it in here would
 * make the set agree with whatever the site already does.
 */
foreach ( $proposed['cases'] as $proposal ) {
	check_eval( ! isset( $proposal['must_include'] ), 'A proposal must not invent required text.' );
	check_eval( ! isset( $proposal['must_not_include'] ), 'A proposal must not invent forbidden text.' );
	check_eval( ! empty( $proposal['todo'] ), 'A proposal must say what the author still has to state.' );
}

// A question already in the set is not offered twice.
AI_Chat_Bedrock_Eval::save_cases( array( array( 'question' => 'Do you repair cargo bikes?', 'expect' => 'unsupported' ) ) );
$again = AI_Chat_Bedrock_Eval::propose();
check_eval(
	! in_array( 'Do you repair cargo bikes?', array_column( $again['cases'], 'question' ), true ),
	'A question already in the set must not be proposed again.'
);

// With logging off there is nothing recorded to propose from, and the reason is given rather
// than an empty list that looks like a clean bill of health.
$GLOBALS['aicfab_logging'] = false;
$off = AI_Chat_Bedrock_Eval::propose();
check_eval( array() === $off['cases'], 'Proposals require recorded questions.' );
check_eval( ! empty( $off['skipped'] ), 'An empty proposal list must explain itself.' );
$GLOBALS['aicfab_logging'] = true;

// Authoring and running are an administrator's job: a run spends money and the cases define
// what good means for the whole site.
check_eval( 'manage_options' === AI_Chat_Bedrock_Eval::CAPABILITY, 'Evaluation must require manage_options.' );

if ( $failures ) {
	echo "FAILED\n";
	foreach ( $failures as $failure ) {
		echo "- $failure\n";
	}
	exit( 1 );
}
echo "OK: evaluation checks passed\n";
