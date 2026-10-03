<?php
/**
 * Business metrics checks, against the real class.
 *
 * The figures are asserted exactly, and so are the rules around them: periods and comparisons
 * on calendar edges, figures from fewer than five questions or orders withheld together with
 * the one that would give them away, metrics built from personal data kept from agents, and
 * a question in words reaching Amazon Bedrock without any figure.
 *
 * The site starts without a shop. WooCommerce is declared part way through.
 *
 * Run: php tests/metrics.php
 *
 * @package AI_Chat_Bedrock
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, Squiz.Commenting, Generic.Commenting, WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride, Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO, WordPress.DateTime.RestrictedFunctions

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['aicfab_options']   = array(
	'ai_chat_bedrock_settings' => array(),
	'start_of_week'            => 1,
);
$GLOBALS['aicfab_caps']      = array(
	'read'           => true,
	'edit_posts'     => true,
	'manage_options' => true,
);
$GLOBALS['aicfab_logged_in'] = true;
$GLOBALS['aicfab_tz']        = 'UTC';
$GLOBALS['aicfab_types']     = array( 'post', 'page', 'attachment' );
$GLOBALS['aicfab_abilities'] = array();
$GLOBALS['aicfab_log_on']    = true;
$GLOBALS['aicfab_log']       = array();
$GLOBALS['aicfab_rest']      = array();
$GLOBALS['aicfab_rest_log']  = array();
$GLOBALS['aicfab_model']     = array();
$GLOBALS['aicfab_reply']     = '';
$GLOBALS['aicfab_rate_ok']   = true;

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_data() {
		return $this->data;
	}
}

$GLOBALS['aicfab_posts'] = array(
	array( 'post', 'publish', '2026-09-01 08:00:00', 'en' ),
	array( 'post', 'publish', '2026-09-01 23:59:59', 'ja' ),
	array( 'post', 'publish', '2026-09-15 12:00:00', 'en' ),
	array( 'page', 'publish', '2026-09-30 00:00:00', 'en' ),
	array( 'post', 'draft', '2026-09-10 10:00:00', 'en' ),
	array( 'post', 'publish', '2026-10-01 00:00:00', 'en' ),
	array( 'attachment', 'publish', '2026-09-05 10:00:00', '' ),
	array( 'post', 'publish', '2026-08-31 23:59:59', 'en' ),
);

class WP_Query {
	public $found_posts = 0;
	public function __construct( $args ) {
		$GLOBALS['aicfab_queries'][] = $args;
		$range                       = $args['date_query'][0];
		foreach ( $GLOBALS['aicfab_posts'] as $post ) {
			if ( ! in_array( $post[0], (array) $args['post_type'], true ) || $args['post_status'] !== $post[1] ) {
				continue;
			}
			if ( '' !== $args['lang'] && $args['lang'] !== $post[3] ) {
				continue;
			}
			if ( $post[2] >= $range['after'] && $post[2] <= $range['before'] ) {
				++$this->found_posts;
			}
		}
	}
}

class WP_REST_Request {
	private $route;
	private $params = array();
	public function __construct( $method, $route ) {
		$this->route = $route;
	}
	public function set_query_params( $params ) {
		$this->params = $params;
	}
	public function get_route() {
		return $this->route;
	}
	public function get_query_params() {
		return $this->params;
	}
}
class WP_REST_Response {
	private $data;
	private $status;
	private $headers;
	public function __construct( $data, $status = 200, $headers = array() ) {
		$this->data    = $data;
		$this->status  = $status;
		$this->headers = $headers;
	}
	public function get_data() {
		return $this->data;
	}
	public function get_status() {
		return $this->status;
	}
	public function get_headers() {
		return $this->headers;
	}
	public function is_error() {
		return $this->status >= 400;
	}
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_options'] ) ? $GLOBALS['aicfab_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['aicfab_options'][ $name ] = $value;
	return true;
}
function apply_filters( $hook, $value ) {
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
function sanitize_textarea_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function wp_strip_all_tags( $text ) {
	return strip_tags( (string) $text );
}
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
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
	return 'language' === $what ? 'en-US' : '';
}
function wp_timezone() {
	return new DateTimeZone( $GLOBALS['aicfab_tz'] );
}
function get_post_types( $args, $output ) {
	return $GLOBALS['aicfab_types'];
}
function get_post_type_object( $type ) {
	return (object) array( 'labels' => (object) array( 'name' => ucfirst( $type ) . 's' ) );
}
function wp_register_ability( $name, $args ) {
	$GLOBALS['aicfab_abilities'][ $name ] = $args;
}
function rest_do_request( $request ) {
	$GLOBALS['aicfab_rest_log'][] = $request;
	$handler                      = isset( $GLOBALS['aicfab_rest'][ $request->get_route() ] ) ? $GLOBALS['aicfab_rest'][ $request->get_route() ] : null;
	return $handler ? $handler( $request->get_query_params() ) : new WP_REST_Response( array(), 404 );
}
function wc_get_price_decimals() {
	return 2;
}
function get_woocommerce_currency() {
	return 'USD';
}

class AI_Chat_Bedrock_Abilities {
	const CATEGORY = 'ai-chat-bedrock';
}
class AI_Chat_Bedrock_Security {
	public static function string_substr( $text, $start, $length ) {
		return mb_substr( $text, $start, $length );
	}
	public static function check_rate_limit( $bucket, $limit, $window = 60 ) {
		$GLOBALS['aicfab_rate'][] = array( $bucket, $limit );
		return $GLOBALS['aicfab_rate_ok'];
	}
}
class AI_Chat_Bedrock_Conversations {
	const MAX_ENTRIES = 200;
	public static function enabled() {
		return $GLOBALS['aicfab_log_on'];
	}
	public static function retention_days() {
		return 30;
	}
	public static function recent( $limit ) {
		return $GLOBALS['aicfab_log'];
	}
}
class AI_Chat_Bedrock_AWS {
	public function __construct( $options = array() ) {
		$GLOBALS['aicfab_model']['options'] = $options;
	}
	public function handle_chat_message( $data ) {
		$GLOBALS['aicfab_model']['messages'] = $data['messages'];
		return array(
			'success' => true,
			'data'    => array( 'message' => $GLOBALS['aicfab_reply'] ),
		);
	}
}

require __DIR__ . '/../includes/class-ai-chat-bedrock-ontology.php';
require __DIR__ . '/../includes/class-ai-chat-bedrock-usage.php';
require __DIR__ . '/../includes/class-ai-chat-bedrock-metrics.php';

$failures = 0;
function check( $condition, $label ) {
	global $failures;
	if ( $condition ) {
		echo "PASS: $label\n";
	} else {
		echo "FAIL: $label\n";
		++$failures;
	}
}
function code_of( $value ) {
	return is_wp_error( $value ) ? $value->get_error_code() : '';
}
function status_of( $value ) {
	$data = is_wp_error( $value ) ? $value->get_error_data() : null;
	return is_array( $data ) && isset( $data['status'] ) ? $data['status'] : 0;
}
function enable_metrics( $on = true ) {
	$GLOBALS['aicfab_options']['ai_chat_bedrock_settings'] = array( 'business_metrics' => $on );
}
function custom( $metric, $after, $before, $more = array() ) {
	return array_merge(
		array(
			'metric' => $metric,
			'period' => 'custom',
			'after'  => $after,
			'before' => $before,
		),
		$more
	);
}
function d( $value ) {
	return AI_Chat_Bedrock_Metrics::date( $value );
}
function ymd( $pair ) {
	return $pair[0]->format( 'Y-m-d' ) . '..' . $pair[1]->format( 'Y-m-d' );
}

// Off by default.
check( false === AI_Chat_Bedrock_Metrics::enabled(), 'metrics are off until the setting is turned on' );
check( 'aicfab_metrics_disabled' === code_of( AI_Chat_Bedrock_Metrics::query( array( 'metric' => 'content_published' ) ) ), 'a query while off is refused' );
check( 'aicfab_metrics_disabled' === code_of( AI_Chat_Bedrock_Metrics::plan( 'How many posts?' ) ), 'a question while off is refused before any model call' );
check( empty( $GLOBALS['aicfab_model'] ), 'no model is called while off' );
$metrics = new AI_Chat_Bedrock_Metrics();
$metrics->register();
check( empty( $GLOBALS['aicfab_abilities'] ), 'no ability is registered while off' );
check( array() === AI_Chat_Bedrock_Metrics::describe_metrics( array() ), 'the site description lists no metric while off' );

enable_metrics();
check( true === AI_Chat_Bedrock_Metrics::enabled(), 'the setting turns metrics on' );

// The catalog, before there is a shop.
$catalog = AI_Chat_Bedrock_Metrics::catalog();
check( ! isset( $catalog['orders'] ), 'store metrics are not offered without WooCommerce' );
foreach ( $catalog as $id => $definition ) {
	foreach ( $definition['dimensions'] as $dimension ) {
		check( isset( AI_Chat_Bedrock_Metrics::dimensions()[ $dimension ] ), "$id: dimension $dimension has a name" );
	}
	check( in_array( $definition['sensitivity'], array( 'public', 'personal', 'financial' ), true ), "$id: has a sensitivity class" );
}

// Policy: personal metrics stay on the Insights screen.
$agent = AI_Chat_Bedrock_Metrics::available( 'agent' );
check( isset( $agent['content_published'], $agent['ai_tokens'] ), 'agents may read content and usage figures' );
check( ! isset( $agent['questions'] ) && ! isset( $agent['unanswered_share'] ) && ! isset( $agent['unhelpful_answers'] ), 'agents may not read figures built from questions' );
check( isset( AI_Chat_Bedrock_Metrics::available( 'analytics' )['questions'] ), 'the Insights screen may show question figures' );
$refused = AI_Chat_Bedrock_Metrics::query( array( 'metric' => 'questions' ), 'agent' );
check( 'aicfab_metric_restricted' === code_of( $refused ) && 403 === status_of( $refused ), 'an agent asking for questions is refused with 403' );
foreach ( AI_Chat_Bedrock_Metrics::catalog() as $id => $definition ) {
	$rule = AI_Chat_Bedrock_Ontology::rule( $definition['sensitivity'], 'agent' );
	check( isset( $agent[ $id ] ) === in_array( $rule, array( 'yes', 'aggregate' ), true ), "$id: agent access follows the published rule ($rule)" );
}

$GLOBALS['aicfab_caps']['manage_options'] = false;
$editor                                   = AI_Chat_Bedrock_Metrics::available( 'analytics' );
check( array( 'content_published' ) === array_keys( $editor ), 'an editor sees content figures only' );
check( 'aicfab_forbidden' === code_of( AI_Chat_Bedrock_Metrics::query( array( 'metric' => 'ai_tokens' ) ) ), 'an editor asking for AI usage is refused' );
$GLOBALS['aicfab_caps']['manage_options'] = true;

// Checking queries.
$bad = AI_Chat_Bedrock_Metrics::normalize( array( 'metric' => 'revenue' ), 'agent' );
check( 'aicfab_unknown_metric' === code_of( $bad ) && false !== strpos( $bad->get_error_message(), 'content_published' ) && false === strpos( $bad->get_error_message(), 'questions' ), 'an unknown metric lists the ones the caller may use' );
$bad = AI_Chat_Bedrock_Metrics::normalize( array( 'metric' => 'content_published', 'period' => 'yesterday' ) );
check( 'aicfab_bad_value' === code_of( $bad ) && false !== strpos( $bad->get_error_message(), 'last_7_days' ), 'an unknown period is refused, listing the periods' );
$bad = AI_Chat_Bedrock_Metrics::normalize( array( 'metric' => 'content_published', 'dimension' => 'model' ) );
check( 'aicfab_bad_value' === code_of( $bad ), 'a dimension the metric lacks is refused' );
$bad = AI_Chat_Bedrock_Metrics::normalize( array( 'metric' => 'content_published', 'compare' => array( 'x' ) ) );
check( 'aicfab_bad_value' === code_of( $bad ), 'a value that is not a string is refused' );
check( 'aicfab_bad_dates' === code_of( AI_Chat_Bedrock_Metrics::normalize( custom( 'content_published', '2026-02-31', '2026-03-05' ) ) ), 'an impossible date is refused, not rolled over' );
check( 'aicfab_bad_dates' === code_of( AI_Chat_Bedrock_Metrics::normalize( custom( 'content_published', '2026-9-01', '2026-09-05' ) ) ), 'a date not written as YYYY-MM-DD is refused' );
check( 'aicfab_bad_dates' === code_of( AI_Chat_Bedrock_Metrics::normalize( array( 'metric' => 'content_published', 'period' => 'custom' ) ) ), 'a custom period without dates is refused' );
check( 'aicfab_bad_dates' === code_of( AI_Chat_Bedrock_Metrics::normalize( custom( 'content_published', '2026-09-10', '2026-09-01' ) ) ), 'a period that ends before it starts is refused' );
check( 'aicfab_bad_dates' === code_of( AI_Chat_Bedrock_Metrics::normalize( custom( 'content_published', '2024-01-01', '2025-01-02' ) ) ), 'a period longer than 366 days is refused' );
check( ! is_wp_error( AI_Chat_Bedrock_Metrics::normalize( custom( 'content_published', '2024-01-01', '2024-12-31' ) ) ), 'a leap year fits' );
$tomorrow = ( new DateTimeImmutable( 'tomorrow', wp_timezone() ) )->format( 'Y-m-d' );
$today    = ( new DateTimeImmutable( 'today', wp_timezone() ) )->format( 'Y-m-d' );
check( 'aicfab_bad_dates' === code_of( AI_Chat_Bedrock_Metrics::normalize( custom( 'content_published', $tomorrow, $tomorrow ) ) ), 'a period that starts in the future is refused' );
$clipped = AI_Chat_Bedrock_Metrics::normalize( custom( 'content_published', $today, $tomorrow ) );
check( $today === $clipped['before'] && 1 === count( $clipped['notes'] ), 'a period ending in the future ends today, with a note' );
$defaults = AI_Chat_Bedrock_Metrics::normalize( array( 'metric' => 'content_published' ) );
check( 'last_30_days' === $defaults['period'] && 'none' === $defaults['compare'] && 'none' === $defaults['interval'] && 'none' === $defaults['dimension'] && 10 === $defaults['limit'], 'defaults: last 30 days, whole period, no comparison or breakdown, 10 rows' );
check( $today === $defaults['before'] && ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '-29 days' )->format( 'Y-m-d' ) === $defaults['after'], 'the last 30 days end today and include it' );
check( 20 === AI_Chat_Bedrock_Metrics::normalize( array( 'metric' => 'content_published', 'limit' => 500 ) )['limit'], 'limit is capped at 20' );
check( 1 === AI_Chat_Bedrock_Metrics::normalize( array( 'metric' => 'content_published', 'limit' => '0' ) )['limit'], 'limit is at least 1' );
check( 10 === AI_Chat_Bedrock_Metrics::normalize( array( 'metric' => 'content_published', 'limit' => '5; DROP' ) )['limit'], 'a limit that is not a number is ignored' );
$weekly = AI_Chat_Bedrock_Metrics::normalize( custom( 'content_published', '2026-01-01', '2026-06-30', array( 'interval' => 'day' ) ) );
check( 'week' === $weekly['interval'] && 1 === count( $weekly['notes'] ), 'more than 92 days by day is shown by week, with a note' );
check( 'day' === AI_Chat_Bedrock_Metrics::normalize( custom( 'content_published', '2026-07-01', '2026-09-30', array( 'interval' => 'day' ) ) )['interval'], '92 days can be shown by day' );

// Periods and comparisons on calendar edges.
$c = function ( $after, $before, $compare = 'previous_period' ) {
	return ymd( AI_Chat_Bedrock_Metrics::comparison( d( $after ), d( $before ), $compare ) );
};
check( '2026-07-01..2026-08-31' === $c( '2026-09-01', '2026-10-31' ), 'two whole months compare with the two months before' );
check( '2026-02-01..2026-02-28' === $c( '2026-03-01', '2026-03-31' ), 'March compares with all of February' );
check( '2026-02-01..2026-02-28' === $c( '2026-03-01', '2026-03-30' ), 'March to date compares with February to the same day, clipped' );
check( '2026-08-05..2026-09-03' === $c( '2026-09-04', '2026-10-03' ), 'thirty days compare with the thirty days before' );
check( '2025-01-01..2025-10-03' === $c( '2026-01-01', '2026-10-03' ), 'year to date compares with the same days last year' );
check( '2025-01-01..2025-12-31' === $c( '2026-01-01', '2026-12-31' ), 'a whole year compares with the year before' );
check( '2023-02-28..2023-02-28' === $c( '2024-02-29', '2024-02-29', 'previous_year' ), 'a year before 29 February is 28 February' );
check( '2025-09-04..2025-10-03' === $c( '2026-09-04', '2026-10-03', 'previous_year' ), 'the same days a year earlier' );
$last_month = AI_Chat_Bedrock_Metrics::resolve( 'last_month' );
check( '01' === $last_month[0]->format( 'd' ) && $last_month[1]->format( 't' ) === $last_month[1]->format( 'd' ), 'last month runs from its first to its last day' );
$last_year = AI_Chat_Bedrock_Metrics::resolve( 'last_year' );
check( '01-01' === $last_year[0]->format( 'm-d' ) && '12-31' === $last_year[1]->format( 'm-d' ), 'last year runs from 1 January to 31 December' );

$weeks = AI_Chat_Bedrock_Metrics::buckets( d( '2026-09-30' ), d( '2026-10-12' ), 'week' );
check( array( '2026-09-28', '2026-10-05', '2026-10-12' ) === array_keys( $weeks ), 'weeks start on the site\'s first day of the week' );
check( '2026-09-30' === $weeks['2026-09-28']['start']->format( 'Y-m-d' ) && '2026-10-04' === $weeks['2026-09-28']['end']->format( 'Y-m-d' ), 'a week is cut to the period' );
$GLOBALS['aicfab_options']['start_of_week'] = 0;
check( array( '2026-09-27', '2026-10-04', '2026-10-11' ) === array_keys( AI_Chat_Bedrock_Metrics::buckets( d( '2026-09-30' ), d( '2026-10-12' ), 'week' ) ), 'weeks can start on Sunday' );
$GLOBALS['aicfab_options']['start_of_week'] = 1;
check( array( '2026-01-01', '2026-02-01', '2026-03-01' ) === array_keys( AI_Chat_Bedrock_Metrics::buckets( d( '2026-01-15' ), d( '2026-03-02' ), 'month' ) ), 'months are calendar months' );

// Content, counted exactly.
$result = AI_Chat_Bedrock_Metrics::query( custom( 'content_published', '2026-09-01', '2026-09-30', array( 'dimension' => 'post_type' ) ) );
check( 4 === $result['value'], 'published items in September: drafts, attachments and other months left out' );
check( false === $result['hidden'], 'content figures are not withheld' );
check( array( 'Posts', 'Pages' ) === array_column( $result['breakdown']['rows'], 'label' ) && array( 3, 1 ) === array_column( $result['breakdown']['rows'], 'value' ), 'broken down by content type, largest first' );
check( '' === $GLOBALS['aicfab_queries'][0]['lang'], 'counts ask Polylang for every language' );
$result = AI_Chat_Bedrock_Metrics::query( custom( 'content_published', '2026-08-31', '2026-10-01', array( 'interval' => 'month', 'compare' => 'previous_period' ) ) );
check( array( 1, 4, 1 ) === array_column( $result['series'], 'value' ), 'by month, with the edge days counted in their months' );
check( '2026-07-30' === $result['compare']['after'] && 0 === $result['compare']['value'] && null === $result['compare']['change_percent'] && 6 === $result['compare']['change'], 'compared with an empty period: a change but no percentage' );
check( ! isset( $result['breakdown'] ), 'no breakdown unless asked' );
$by_day = AI_Chat_Bedrock_Metrics::query( custom( 'content_published', '2026-09-01', '2026-09-03', array( 'interval' => 'day' ) ) );
check( array( 2, 0, 0 ) === array_column( $by_day['series'], 'value' ) && '2026-09-02' === $by_day['series'][1]['start'], 'by day, with empty days shown as zero' );

// Languages with Polylang.
function pll_is_translated_post_type( $type ) {
	return 'post' === $type;
}
function pll_languages_list( $args ) {
	return 'slug' === $args['fields'] ? array( 'en', 'ja' ) : array( 'English', '日本語' );
}
function pll_default_language( $field ) {
	return 'en';
}
$result = AI_Chat_Bedrock_Metrics::query( custom( 'content_published', '2026-09-01', '2026-09-30', array( 'dimension' => 'language' ) ) );
$rows   = array_combine( array_column( $result['breakdown']['rows'], 'label' ), array_column( $result['breakdown']['rows'], 'value' ) );
check( array( 'English' => 2, '日本語' => 1, 'Not translated' => 1 ) === $rows, 'by language, with pages that are not translated apart' );

// Questions from the conversation log.
$now = time();
$day = 86400;
$log = array();
foreach ( array( 1, 1, 1, 1, 1, 1, 0, 0, 0 ) as $index => $grounded ) {
	$log[] = array(
		'time'     => $now - ( $index < 6 ? 0 : $day ),
		'source'   => 0 === $index ? 'stream' : 'chat',
		'grounded' => $grounded,
		'rating'   => $index < 2 ? -1 : ( 2 === $index ? 1 : 0 ),
		'question' => 'private question ' . $index,
	);
}
$log[]                = array(
	'time'     => $now,
	'source'   => 'editor',
	'grounded' => 0,
);
$GLOBALS['aicfab_log'] = $log;
$window                = array(
	'metric' => 'questions',
	'period' => 'last_7_days',
);
$result                = AI_Chat_Bedrock_Metrics::query( $window + array( 'interval' => 'day' ) );
check( 9 === $result['value'], 'questions from chat and stream are counted, editor requests are not' );
$shown = array_values( array_filter( $result['series'], static function ( $item ) {
	return null !== $item['value'];
} ) );
$hidden = array_values( array_filter( $result['series'], static function ( $item ) {
	return $item['hidden'];
} ) );
check( 2 === count( $hidden ) && array_sum( array_column( $shown, 'value' ) ) === 0, 'a day with three questions is withheld, and so is the day that would give it away' );
check( false === strpos( wp_json_encode( $result ), 'private question' ), 'no question text is returned' );
$result = AI_Chat_Bedrock_Metrics::query( $window + array( 'dimension' => 'grounding' ) );
check( array( true, true ) === array_column( $result['breakdown']['rows'], 'hidden' ), 'six with site content and three without: both withheld' );
$result = AI_Chat_Bedrock_Metrics::query( array( 'metric' => 'unanswered_share' ) + $window );
check( 33.3 === $result['value'] && 'percent' === $result['unit'], 'share without site content: 3 of 9 is 33.3%' );
$result = AI_Chat_Bedrock_Metrics::query( array( 'metric' => 'unhelpful_answers' ) + $window );
check( null === $result['value'] && true === $result['hidden'], 'two unhelpful answers are withheld' );
$found = false;
foreach ( $result['notes'] as $note ) {
	$found = $found || false !== strpos( $note, 'fewer than 5' );
}
check( $found, 'the note says why figures are withheld' );
$GLOBALS['aicfab_log_on'] = false;
$off                      = AI_Chat_Bedrock_Metrics::query( $window );
check( 'aicfab_metric_unavailable' === code_of( $off ) && 409 === status_of( $off ), 'with the log off, questions cannot be counted' );
$GLOBALS['aicfab_log_on'] = true;
$result                   = AI_Chat_Bedrock_Metrics::query( array( 'metric' => 'questions', 'period' => 'last_90_days' ) );
check( 1 === count( array_filter( $result['notes'], static function ( $note ) {
	return false !== strpos( $note, 'keeps 30 days' );
} ) ), 'a period longer than the log keeps says so' );

// AI usage.
$today_utc     = gmdate( 'Y-m-d' );
$yesterday_utc = gmdate( 'Y-m-d', time() - $day );
$GLOBALS['aicfab_options']['ai_chat_bedrock_usage'] = array(
	$today_utc     => array(
		'requests'      => 3,
		'input_tokens'  => 1000,
		'output_tokens' => 200,
		'models'        => array(
			'model-a' => array(
				'requests'      => 2,
				'input_tokens'  => 800,
				'output_tokens' => 100,
			),
			'model-b' => array(
				'requests'      => 1,
				'input_tokens'  => 200,
				'output_tokens' => 100,
			),
		),
		'failures'      => array( 'throttled' => 2 ),
	),
	$yesterday_utc => array(
		'requests'      => 1,
		'input_tokens'  => 50,
		'output_tokens' => 10,
		'models'        => array(
			'model-a' => array(
				'requests'      => 1,
				'input_tokens'  => 50,
				'output_tokens' => 10,
			),
		),
		'failures'      => array(
			'throttled' => 1,
			'network'   => 1,
		),
	),
	'2020-01-01'   => array( 'requests' => 99 ),
);
$result = AI_Chat_Bedrock_Metrics::query( array( 'metric' => 'ai_tokens', 'period' => 'last_7_days', 'dimension' => 'model' ) );
check( 1260 === $result['value'], 'tokens are input plus output over the period' );
check( array( 'model-a' => 960, 'model-b' => 300 ) === array_combine( array_column( $result['breakdown']['rows'], 'key' ), array_column( $result['breakdown']['rows'], 'value' ) ), 'tokens by model' );
check( 4 === AI_Chat_Bedrock_Metrics::query( array( 'metric' => 'ai_requests', 'period' => 'last_7_days' ) )['value'], 'requests over the period' );
$result = AI_Chat_Bedrock_Metrics::query( array( 'metric' => 'ai_failures', 'period' => 'last_7_days', 'dimension' => 'failure_type' ) );
check( 4 === $result['value'] && 'Throttled' === $result['breakdown']['rows'][0]['label'] && 3 === $result['breakdown']['rows'][0]['value'], 'failures by kind, with their names' );
check( 0 === count( $result['notes'] ), 'no notes for a short period in UTC' );
check( 1 === count( AI_Chat_Bedrock_Metrics::query( array( 'metric' => 'ai_requests', 'period' => 'last_90_days' ) )['notes'] ), 'a period longer than usage is kept says so' );
$GLOBALS['aicfab_tz'] = 'Asia/Tokyo';
check( in_array( 'AI usage is counted per day in UTC.', AI_Chat_Bedrock_Metrics::query( array( 'metric' => 'ai_requests', 'period' => 'last_7_days' ) )['notes'], true ), 'outside UTC, usage says its days are UTC days' );
$GLOBALS['aicfab_tz'] = 'UTC';

// The store.
if ( true ) {
	class WooCommerce {}
}
$GLOBALS['aicfab_types'][] = 'product';
$store_days                = array(
	'2026-09-01' => array( 3, 30.00, 4 ),
	'2026-09-02' => array( 0, 0, 0 ),
	'2026-09-03' => array( 6, 120.50, 9 ),
	'2026-09-04' => array( 5, 39.99, 4 ),
);
$GLOBALS['aicfab_rest']['/wc-analytics/reports/revenue/stats'] = function ( $params ) use ( &$store_days ) {
	$intervals = array();
	$day       = d( substr( $params['after'], 0, 10 ) );
	$end       = d( substr( $params['before'], 0, 10 ) );
	for ( ; $day <= $end; $day = $day->modify( '+1 day' ) ) {
		$key         = $day->format( 'Y-m-d' );
		$row         = isset( $store_days[ $key ] ) ? $store_days[ $key ] : array( 0, 0, 0 );
		$intervals[] = array(
			'interval'   => $key,
			'date_start' => $key . ' 00:00:00',
			'subtotals'  => array(
				'orders_count'   => $row[0],
				'net_revenue'    => $row[1],
				'num_items_sold' => $row[2],
			),
		);
	}
	$pages = (int) ceil( count( $intervals ) / $params['per_page'] );
	return new WP_REST_Response( array_slice( $intervals, ( $params['page'] - 1 ) * $params['per_page'], $params['per_page'] ), 200, array( 'X-WP-TotalPages' => $pages ) );
};
// The stub returns intervals as the list itself, the way the stats endpoint does not; wrap it.
$inner = $GLOBALS['aicfab_rest']['/wc-analytics/reports/revenue/stats'];
$GLOBALS['aicfab_rest']['/wc-analytics/reports/revenue/stats'] = function ( $params ) use ( $inner ) {
	$response = $inner( $params );
	return new WP_REST_Response( array( 'intervals' => $response->get_data() ), 200, $response->get_headers() );
};
$GLOBALS['aicfab_rest']['/wc-analytics/reports/products'] = function ( $params ) {
	$rows = array(
		array(
			'product_id'    => 20,
			'orders_count'  => 9,
			'net_revenue'   => 150.5,
			'items_sold'    => 12,
			'extended_info' => array( 'name' => 'Blue <b>widget</b>' ),
		),
		array(
			'product_id'    => 21,
			'orders_count'  => 2,
			'net_revenue'   => 39.99,
			'items_sold'    => 4,
			'extended_info' => array( 'name' => '=HYPERLINK("x")' ),
		),
		array(
			'product_id'    => 22,
			'orders_count'  => 6,
			'net_revenue'   => 20,
			'items_sold'    => 1,
			'extended_info' => array(),
		),
	);
	usort(
		$rows,
		static function ( $a, $b ) use ( $params ) {
			return $b[ $params['orderby'] ] <=> $a[ $params['orderby'] ];
		}
	);
	return new WP_REST_Response( $rows );
};
$GLOBALS['aicfab_caps']['view_woocommerce_reports'] = true;

$catalog = AI_Chat_Bedrock_Metrics::catalog();
check( isset( $catalog['orders'], $catalog['net_revenue'], $catalog['items_sold'], $catalog['average_order_value'] ), 'store metrics are offered with WooCommerce' );
check( isset( AI_Chat_Bedrock_Metrics::available( 'agent' )['net_revenue'] ), 'agents may read store totals, which the rules allow in aggregate' );
$september = custom( 'orders', '2026-09-01', '2026-09-04' );
$result    = AI_Chat_Bedrock_Metrics::query( $september + array( 'interval' => 'day' ) );
check( 14 === $result['value'], 'orders over the period' );
check( array( null, 0, 6, null ) === array_column( $result['series'], 'value' ), 'a day with three orders is withheld, with the smallest shown day, and a day without orders shows zero' );
$request = $GLOBALS['aicfab_rest_log'][0]->get_query_params();
check( 'day' === $request['interval'] && '2026-09-01T00:00:00' === $request['after'] && '2026-09-04T23:59:59' === $request['before'], 'WooCommerce Analytics is read by day, over whole days' );
$result = AI_Chat_Bedrock_Metrics::query( array( 'metric' => 'net_revenue' ) + $september );
check( 190.49 === $result['value'] && 'USD' === $result['currency'], 'net sales, rounded to the store\'s decimals, with the currency' );
$result = AI_Chat_Bedrock_Metrics::query( array( 'metric' => 'average_order_value' ) + $september );
check( 13.61 === $result['value'], 'average order value is net sales over orders: 190.49 / 14' );
$result = AI_Chat_Bedrock_Metrics::query( custom( 'net_revenue', '2026-09-01', '2026-09-01' ) );
check( null === $result['value'] && $result['hidden'], 'sales from three orders are withheld' );
$result = AI_Chat_Bedrock_Metrics::query( custom( 'net_revenue', '2026-09-03', '2026-09-04', array( 'compare' => 'previous_period' ) ) );
check( 160.49 === $result['value'] && null === $result['compare']['value'] && $result['compare']['hidden'] && null === $result['compare']['change'], 'an earlier period from too few orders is withheld, and so is the change' );
$result = AI_Chat_Bedrock_Metrics::query( array( 'dimension' => 'product' ) + $september );
$rows   = $result['breakdown']['rows'];
check( array( '20', '22', '21' ) === array_column( $rows, 'key' ), 'products by orders, the withheld one last' );
check( 'Blue widget' === $rows[0]['label'] && '#22' === $rows[1]['label'], 'product names without markup, or the ID when there is none' );
check( array( 9, null, null ) === array_column( $rows, 'value' ), 'a product with two orders is withheld, with the next smallest' );
check( in_array( 'Totals date each order by the date paid, the Date type chosen under Analytics > Settings.', $result['notes'], true ), 'the note says which date WooCommerce Analytics counts orders by' );
check( in_array( 'WooCommerce Analytics dates rows by product or category by when each order was placed, so they can differ from the total.', $result['notes'], true ), 'a breakdown says its rows are dated differently' );
check( 'aicfab_bad_value' === code_of( AI_Chat_Bedrock_Metrics::query( array( 'dimension' => 'product_category' ) + $september ) ), 'orders are not split by category, whose report counts refunds as orders' );
check( in_array( 'product_category', $catalog['net_revenue']['dimensions'], true ) && in_array( 'product_category', $catalog['items_sold']['dimensions'], true ), 'sales and items are split by category' );

// WooCommerce has returned report rows as objects too.
$as_arrays = $GLOBALS['aicfab_rest']['/wc-analytics/reports/revenue/stats'];
$GLOBALS['aicfab_rest']['/wc-analytics/reports/revenue/stats'] = function ( $params ) use ( $as_arrays ) {
	$response = $as_arrays( $params );
	$data     = $response->get_data();
	return new WP_REST_Response( array( 'intervals' => json_decode( wp_json_encode( $data['intervals'] ) ) ), 200, $response->get_headers() );
};
check( 14 === AI_Chat_Bedrock_Metrics::query( $september )['value'], 'daily figures are read from objects as well as arrays' );
$GLOBALS['aicfab_rest']['/wc-analytics/reports/revenue/stats'] = $as_arrays;

// The tool list for one account offers only the figures it may read.
$GLOBALS['aicfab_caps']['view_woocommerce_reports'] = false;
check( in_array( 'orders', AI_Chat_Bedrock_Metrics::input_schema( 'agent' )['properties']['metric']['enum'], true ), 'the ability schema lists every metric the rules allow' );
check( ! in_array( 'orders', AI_Chat_Bedrock_Metrics::input_schema( 'agent', true )['properties']['metric']['enum'], true ), 'a schema for the current account leaves out what it may not read' );
$GLOBALS['aicfab_caps']['view_woocommerce_reports'] = true;
$GLOBALS['aicfab_rest']['/wc-analytics/reports/products'] = function () {
	return new WP_REST_Response( array( 'code' => 'woocommerce_rest_cannot_view' ), 403 );
};
check( 'aicfab_forbidden' === code_of( AI_Chat_Bedrock_Metrics::query( array( 'dimension' => 'product' ) + $september ) ), 'WooCommerce refusing the request is reported as forbidden' );
$GLOBALS['aicfab_caps']['view_woocommerce_reports'] = false;
check( 'aicfab_forbidden' === code_of( AI_Chat_Bedrock_Metrics::query( $september ) ), 'without the store reports capability, store metrics are refused' );
$GLOBALS['aicfab_caps']['view_woocommerce_reports'] = true;
$long = AI_Chat_Bedrock_Metrics::query( custom( 'orders', '2025-10-01', '2026-09-30' ) );
check( 14 === $long['value'] && 4 === count( array_filter( $GLOBALS['aicfab_rest_log'], static function ( $request ) {
	return '2025-10-01T00:00:00' === $request->get_query_params()['after'];
} ) ), 'a year is read in pages of 100 days' );

// Download.
$result = AI_Chat_Bedrock_Metrics::query( array( 'interval' => 'day', 'compare' => 'previous_period' ) + custom( 'net_revenue', '2026-09-03', '2026-09-04' ) );
$rows   = AI_Chat_Bedrock_Metrics::export_rows( $result );
check( array( 'metric', 'unit', 'row', 'start', 'end', 'key', 'label', 'value', 'withheld' ) === $rows[0], 'the download has a header' );
check( array( 'net_revenue', 'USD', 'total', '2026-09-03', '2026-09-04', '', 'Net sales', 160.49, 0 ) === $rows[1], 'the total row' );
check( 'earlier' === $rows[2][2] && '' === $rows[2][7] && 1 === $rows[2][8], 'a withheld figure is empty and marked' );
check( 5 === count( $rows ), 'one row per day' );
check( 'ai-chat-bedrock-net-revenue-20260903-20260904.csv' === AI_Chat_Bedrock_Metrics::export_filename( $result ), 'file name' );

// Asking in words.
$GLOBALS['aicfab_reply'] = "Here you go:\n```json\n{\"metric\": \"net_revenue\", \"period\": \"last_month\", \"compare\": \"previous_year\", \"extra\": \"x\", \"limit\": 3}\n```";
$plan                    = AI_Chat_Bedrock_Metrics::plan( '<b>How</b> did sales do last month against a year ago? ' . str_repeat( 'x', 400 ) );
check( ! is_wp_error( $plan ) && 'net_revenue' === $plan['metric'] && 'last_month' === $plan['period'] && 'previous_year' === $plan['compare'] && 3 === $plan['limit'], 'a question becomes a checked query' );
check( ! isset( $plan['extra'] ), 'unknown keys from the model are dropped' );
$messages = $GLOBALS['aicfab_model']['messages'];
check( 'system' === $messages[0]['role'] && 0 === strpos( $messages[1]['content'], 'How did sales' ) && 300 === mb_strlen( $messages[1]['content'] ), 'the question is sent as the user\'s message, without markup and cut to 300 characters' );
check( false !== strpos( $messages[0]['content'], 'Today is ' . $today ) && false !== strpos( $messages[0]['content'], 'Monday' ), 'the model is told today\'s date and the first day of the week' );
check( false !== strpos( $messages[0]['content'], '"questions"' ) && false === strpos( $messages[0]['content'], '190.49' ) && false === strpos( $messages[0]['content'], '160.49' ), 'the model sees the metrics, never a figure' );
check( 0 === $GLOBALS['aicfab_model']['options']['temperature'], 'the planner asks for a steady answer' );
check( array( 'metrics-ask', 10 ) === end( $GLOBALS['aicfab_rate'] ), 'questions are rate limited' );
$GLOBALS['aicfab_reply'] = '{"metric": null}';
check( 'aicfab_not_a_metric' === code_of( AI_Chat_Bedrock_Metrics::plan( 'Write me a poem' ) ), 'a question no metric answers is said to be so' );
$GLOBALS['aicfab_reply'] = '{"metric": "net_revenue", "period": "fortnight"}';
check( 'aicfab_bad_value' === code_of( AI_Chat_Bedrock_Metrics::plan( 'Sales this fortnight' ) ), 'a plan with an unknown value is refused, not guessed' );
$GLOBALS['aicfab_reply'] = 'I cannot help with that.';
check( 'aicfab_bad_plan' === code_of( AI_Chat_Bedrock_Metrics::plan( 'Sales?' ) ), 'a reply without JSON is refused' );
check( 'aicfab_missing_question' === code_of( AI_Chat_Bedrock_Metrics::plan( '   ' ) ), 'an empty question is refused' );
$GLOBALS['aicfab_rate_ok'] = false;
check( 'aicfab_rate_limited' === code_of( AI_Chat_Bedrock_Metrics::plan( 'Sales?' ) ), 'too many questions are refused' );
$GLOBALS['aicfab_rate_ok'] = true;
check( 'Net sales · Last month · Same period a year earlier' === AI_Chat_Bedrock_Metrics::summarize( $plan ), 'the query is read back in words' );

// Agents.
$metrics->register();
$ability = isset( $GLOBALS['aicfab_abilities']['ai-chat-bedrock/query-metrics'] ) ? $GLOBALS['aicfab_abilities']['ai-chat-bedrock/query-metrics'] : null;
check( null !== $ability, 'the query-metrics ability is registered when on' );
check( true === $ability['meta']['annotations']['readonly'] && 'ai-chat-bedrock' === $ability['category'], 'the ability is read only, in the plugin\'s category' );
$enum = $ability['input_schema']['properties']['metric']['enum'];
check( in_array( 'net_revenue', $enum, true ) && ! in_array( 'questions', $enum, true ), 'the ability offers only metrics agents may read' );
check( 'aicfab_metric_restricted' === code_of( AI_Chat_Bedrock_Metrics::execute_ability( array( 'metric' => 'unanswered_share' ) ) ), 'the ability refuses question figures' );
check( 14 === AI_Chat_Bedrock_Metrics::execute_ability( $september )['value'], 'the ability returns store totals' );
check( true === $metrics->can_query(), 'a signed-in user with a metric may use the ability' );
$GLOBALS['aicfab_logged_in'] = false;
check( false === $metrics->can_query(), 'a visitor may not' );
$GLOBALS['aicfab_logged_in'] = true;
$GLOBALS['aicfab_caps']      = array( 'read' => true );
check( false === $metrics->can_query(), 'a subscriber may not' );
$GLOBALS['aicfab_caps'] = array(
	'read'                     => true,
	'edit_posts'               => true,
	'manage_options'           => true,
	'view_woocommerce_reports' => true,
);
$described = AI_Chat_Bedrock_Metrics::describe_metrics( array() );
$ids       = array_column( $described, 'id' );
check( in_array( 'orders', $ids, true ) && ! in_array( 'questions', $ids, true ), 'the site description lists the metrics agents may read' );
check( 'query_metrics' === $described[0]['tool'] && 'ai-chat-bedrock/query-metrics' === $described[0]['ability'], 'and says how to read them' );

echo "\n" . ( $failures ? "$failures FAILED\n" : "All metrics checks passed.\n" );
exit( $failures ? 1 : 0 );
